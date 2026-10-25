<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ComplaintData;
use App\DataTransferObjects\PaymentData;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Expenditure;
use App\Models\FieldInstallation;
use App\Models\Manuscript;
use App\Models\Payment;
use App\Models\Scopes\BranchScope;
use App\Models\Service;
use App\Models\SyncQueue;
use App\Models\User;
use App\Models\Voucher;
use App\Models\ZoneAgent;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Support\Billing\FreeMonthPlan;
use App\Support\BranchLookup;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Server side of the field-agent offline sync protocol (see
 * .ai/skills/cncms/cncms-context/references/offline-sync-strategy.md and
 * references/api-spec.md section 7).
 */
class SyncService
{
    /** How far back a first / full pull sends payment verification results (mobile data). */
    private const int FULL_PULL_PAYMENT_DAYS = 60;

    /**
     * The oldest collection date a phone may report: a phone can be offline
     * a while, but not across more than two billing months. Older (or in the
     * future) means a wrong clock — the server's time is used instead.
     */
    private const int OLDEST_COLLECTION_DAYS = 62;

    /**
     * Each pushed payment's values, checked one at a time (pushPayment()) so
     * one bad item fails alone — SyncPushRequest only checks the shape.
     *
     * @var array<string, list<string>>
     */
    private const array PAYMENT_ITEM_RULES = [
        'customer_uuid' => ['required', 'string'],
        'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
        'credit' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
        'frequency' => ['required', 'string', 'in:monthly,yearly,months'],
        'months' => ['nullable', 'integer', 'min:1', 'max:120', 'required_if:frequency,months'],
        'clear_arrears_first' => ['nullable', 'boolean'],
        'created_at' => ['nullable', 'string'],
        // Cash controls: an unknown method or a MoMo payment without its ID
        // is refused by PaymentService.
        'method' => ['nullable', 'string', 'max:20'],
        'method_reference' => ['nullable', 'string', 'max:100'],
        // The receipt the phone printed — checked by App\Services\FieldReceiptService.
        'receipt_number' => ['nullable', 'string', 'max:30'],
        'security_code' => ['nullable', 'string', 'max:12'],
        'receipt_issued_at' => ['nullable', 'string', 'max:40'],
        // The agent's answer to "already paid this month — why another?"
        // (anything else, or nothing, is stored as `unconfirmed`).
        'duplicate_reason' => ['nullable', 'string', 'max:20'],
        'duplicate_note' => ['nullable', 'string', 'max:255'],
        'prepayment_offer_uuid' => ['nullable', 'uuid'],
    ];

    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentVerificationService $verifications,
        private readonly VoucherService $vouchers,
        private readonly ComplaintService $complaints,
        private readonly NotificationService $notifications,
        private readonly TenantContext $context,
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    /**
     * Push (mobile -> server): applies queued offline changes one item at a
     * time. A failure on one item (bad customer_uuid, validation error,
     * anything) is caught and reported per-item — it must never abort
     * processing of the rest of the batch. This mirrors the defensive
     * per-record try/catch in App\Console\Commands\ManuscriptCalculate's
     * runForEveryCustomer(), which does the same for per-customer errors.
     *
     * @param  array<string, mixed>  $changes  {"payments": [...], "bon_requests": [...], "expenditures": [...], "complaints": [...]}
     * @return array<string, mixed>
     */
    public function push(array $changes, string $deviceId, ?string $lastSyncAt, User $actor): array
    {
        $this->registerDevice($actor, $deviceId);

        $results = ['installations' => [], 'payments' => [], 'bon_requests' => [], 'expenditures' => [], 'complaints' => []];
        $errors = [];

        // Field installations first (installation phase 3): a payment the
        // agent recorded offline for a customer registered offline in the
        // same batch needs that customer to exist.
        foreach ($changes['installations'] ?? [] as $item) {
            [$entry, $error] = $this->pushInstallation($item, $deviceId, $actor);
            $results['installations'][] = $entry;

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        // A phone's clock decides which month a payment lands in (and the
        // duplicate check's window), so an impossible date is pulled back
        // to the server's time before anything reads it.
        $changes['payments'] = array_map(function (mixed $item): mixed {
            if (! is_array($item)) {
                return $item;
            }

            $bounded = $this->boundedCollectedAt($item['created_at'] ?? null);

            // The phone's own value is kept beside it (the sync_queue payload) when it was replaced.
            return $bounded === ($item['created_at'] ?? null)
                ? $item
                : [...$item, 'created_at' => $bounded, 'phone_created_at' => $item['created_at'] ?? null];
        }, $changes['payments'] ?? []);

        $paymentLookups = $this->paymentPushLookups($changes['payments']);
        BranchLookup::remember($paymentLookups['customers']);
        // The "already paid this month?" check for the whole batch, in one query.
        $this->payments->primeEarlierPayments(
            $paymentLookups['payable']->map(fn (Customer $customer): int => (int) $customer->id)->values()->all(),
            collect($changes['payments'] ?? [])->map(fn (mixed $item): ?string => is_array($item) && is_string($item['created_at'] ?? null) ? $item['created_at'] : null)->values()->all(),
        );

        try {
            foreach ($changes['payments'] ?? [] as $item) {
                [$entry, $error] = $this->pushPayment($item, $deviceId, $paymentLookups);
                $results['payments'][] = $entry;

                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        } finally {
            $this->payments->forgetEarlierPayments();
        }

        foreach ($changes['bon_requests'] ?? [] as $item) {
            [$entry, $error] = $this->pushBonRequest($item, $deviceId, $actor, 'bon_request');
            $results['bon_requests'][] = $entry;

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        // Older app builds still push "Record expense" items. Since E1 they
        // become bon requests awaiting approval — the data is never dropped.
        foreach ($changes['expenditures'] ?? [] as $item) {
            [$entry, $error] = $this->pushBonRequest($this->legacyExpenditureAsBonRequest($item), $deviceId, $actor, 'expenditure');
            $results['expenditures'][] = $entry;

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        foreach ($changes['complaints'] ?? [] as $item) {
            [$entry, $error] = $this->pushComplaint($item, $deviceId, $actor);
            $results['complaints'][] = $entry;

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return [
            'status' => 'success',
            'synced_at' => Carbon::now()->toIso8601String(),
            'results' => $results,
            'errors' => $errors,
        ];
    }

    /**
     * Pull (server -> mobile): everything the client's local caches need to
     * catch up since `$since` (or, when `$since` is null, a full first-login
     * sync). See offline-sync-strategy.md section 4.3.
     *
     * @return array<string, mixed>
     */
    public function pull(?string $since, User $actor): array
    {
        $this->touchAgentLastSync($actor);

        $sinceAt = $since ? Carbon::parse($since) : null;

        return [
            'synced_at' => Carbon::now()->toIso8601String(),
            'changes' => [
                'customers' => [
                    'upserted' => $this->upsertedCustomers($sinceAt),
                    // Archived (soft-deleted) customers are real tombstones
                    // now (customer-deletion deliberation, 2026-08-29) — a
                    // device that has one cached must drop it. Restoring a
                    // customer later re-surfaces them through `upserted` on
                    // the next pull (their updated_at moves), so the two
                    // directions stay consistent.
                    'deleted' => $this->deletedCustomers($sinceAt),
                ],
                'payments' => [
                    'verified' => $this->changedPayments('verified', $sinceAt),
                    'rejected' => $this->changedPayments('rejected', $sinceAt),
                ],
                // The office's decision on this user's own field
                // registrations (installation phase 3): pending, activated
                // or rejected with its reason.
                'installations' => $this->changedInstallations($sinceAt, $actor),
                // in-app-notifications.md section 6 / complaint-desk.md
                // section 7: piggybacks on this existing pull cycle rather
                // than a second real-time channel. Delegates entirely to
                // NotificationService::feedForUser() — the same lazy
                // per-recipient-state computation the web bell/banner use
                // (in-app-notifications.md section 3) — so mobile and web
                // can never disagree about what counts as "unread" or
                // "unacknowledged emergency" for this user. $since is
                // deliberately NOT threaded through here: feedForUser()
                // always returns this user's current full unread/emergency
                // state (bounded by its own $limit), not a delta, since an
                // agent who was offline for days must still see everything
                // still relevant, not just what changed since their last
                // successful pull.
                'notifications' => $this->notifications->feedForUser($actor, $this->context, 20),
            ],
        ];
    }

    /**
     * A receipt photo for a payment, or for a bon request (`voucher`; an
     * older build's `expenditure` is the same bon — its server_uuid is the
     * bon's uuid). A bon's photo may only be attached by the person who
     * requested it, and only while it is still pending — after that it is
     * evidence and nobody replaces it.
     *
     * @return array{message: string, receipt_url: string}
     */
    public function uploadReceipt(string $entityType, string $entityUuid, UploadedFile $file, ?User $actor = null): array
    {
        if ($entityType === 'voucher' || $entityType === 'expenditure') {
            $voucher = Voucher::query()->where('uuid', $entityUuid)->first();

            if ($voucher === null && $entityType === 'expenditure' && Expenditure::query()->where('uuid', $entityUuid)->exists()) {
                throw ValidationException::withMessages(['entity_uuid' => ['This expenditure is already on record; its receipt can no longer be replaced.']]);
            }

            if ($voucher === null) {
                throw ValidationException::withMessages(['entity_uuid' => ['Unknown bon.']]);
            }

            if ($actor === null || $voucher->requested_by !== $actor->id) {
                abort(403, 'Only the person who requested this bon can attach its receipt.');
            }

            if (! $voucher->isPending()) {
                throw ValidationException::withMessages(['entity_uuid' => ["{$voucher->number} is no longer pending; its receipt can't be changed."]]);
            }

            $storedPath = $file->store('vouchers', $this->vouchers->disk());
            $voucher->update(['receipt_path' => $storedPath]);

            return [
                'message' => 'Receipt uploaded',
                'receipt_url' => "/vouchers/{$voucher->uuid}/files/receipt",
            ];
        }

        if ($entityType === 'payment') {
            // Only a payment of the caller's own branch (branches v2).
            $payment = Payment::query()->forBranch(TenantContext::currentBranchId())->where('uuid', $entityUuid)->firstOrFail();

            $storedPath = $file->store('receipts/payments', 'public');

            $this->verifications->attachReceipt($payment, $storedPath);

            return [
                'message' => 'Receipt uploaded',
                'receipt_url' => Storage::disk('public')->url($storedPath),
            ];
        }

        throw ValidationException::withMessages(['entity_type' => ['entity_type must be payment or voucher.']]);
    }

    /**
     * @return array{device_id: string, last_sync_at: ?string, pending_push: int, pending_pull: int, failed_items: int}
     */
    public function status(string $deviceId): array
    {
        $lastSyncAt = Agent::query()->where('sync_token', $deviceId)->value('last_sync_at');

        $pendingPush = SyncQueue::query()
            ->where('device_id', $deviceId)
            ->where('direction', 'up')
            ->whereIn('status', ['pending', 'processing'])
            ->count();

        $failedItems = SyncQueue::query()
            ->where('device_id', $deviceId)
            ->where('status', 'failed')
            ->count();

        // There is no persistent 'down' queue populated for this device (the
        // pull() response is computed live from customers/payments rather
        // than drained from sync_queue rows) — pending_pull is therefore a
        // best-effort count of records changed since last_sync_at, not an
        // exact queue depth. See report note.
        $pendingPull = 0;

        if ($lastSyncAt) {
            $pendingPull = Customer::query()->where('updated_at', '>=', $lastSyncAt)->count()
                + Payment::query()
                    ->forBranch(TenantContext::currentBranchId())
                    ->whereIn('verification_status', ['verified', 'rejected'])
                    ->where('updated_at', '>=', $lastSyncAt)
                    ->count();
        }

        return [
            'device_id' => $deviceId,
            'last_sync_at' => $lastSyncAt ? Carbon::parse($lastSyncAt)->toIso8601String() : null,
            'pending_push' => $pendingPush,
            'pending_pull' => $pendingPull,
            'failed_items' => $failedItems,
        ];
    }

    /**
     * Everything pushPayment() would otherwise look up once per item,
     * resolved in three queries for the whole batch:
     * - synced: local_uuid => server uuid of already-synced payments (the
     *   idempotency check), kept current as the batch creates payments;
     * - customers: every referenced customer, unscoped, for the
     *   disconnected/suspended check (as before);
     * - payable: the same customers through the branch-scoped repository,
     *   which is what PaymentService::create() would resolve.
     *
     * @param  array<int, mixed>  $items
     * @return array{synced: array<string, string>, customers: Collection<string, Customer>, payable: Collection<string, Customer>}
     */
    private function paymentPushLookups(array $items): array
    {
        $localUuids = collect($items)->pluck('local_uuid')->filter(fn (mixed $uuid): bool => is_string($uuid))->unique()->values()->all();
        $customerUuids = collect($items)->pluck('customer_uuid')->filter(fn (mixed $uuid): bool => is_string($uuid))->unique()->values()->all();

        return [
            'synced' => $localUuids === []
                ? []
                : Payment::query()->whereIn('local_uuid', $localUuids)->pluck('uuid', 'local_uuid')->all(),
            'customers' => $customerUuids === []
                ? collect()
                : Customer::query()->whereIn('uuid', $customerUuids)->get()->keyBy('uuid'),
            'payable' => $customerUuids === []
                ? collect()
                : $this->customers->findManyByUuids($customerUuids, ['zone.branch', 'latestManuscript'])->keyBy('uuid'),
        ];
    }

    /**
     * `$item['created_at']` -> `PaymentData::$collectedAt` -> `payments.collected_at`:
     * the mobile client names this field `created_at` in the wire payload
     * (mobile/src/sync/SyncManager.ts:281 sends it per queued payment, and
     * SyncPushRequest validates it as `changes.payments.*.created_at`) since
     * from the client's point of view it genuinely IS the row's creation
     * time. Server-side that name is misleading: `payments.created_at` is
     * Eloquent's own auto-managed column and already means something else
     * here — "when this row landed on the server" — which backs
     * App\Http\Controllers\PaymentController::index()'s month-scoping/
     * "Today" filter and the daily-close-of-day design. Renaming on the way
     * in (rather than asking the mobile app to rename its own field) avoids
     * a mobile release just to fix a server-side naming collision; the
     * client's timestamp is preserved verbatim, just under a column that
     * doesn't already have a conflicting meaning.
     *
     * @param  array<string, mixed>  $item
     * @param  array{synced: array<string, string>, customers: Collection<string, Customer>, payable: Collection<string, Customer>}  $lookups  See paymentPushLookups().
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function pushPayment(array $item, string $deviceId, array &$lookups): array
    {
        $localUuid = $item['local_uuid'] ?? null;

        // Idempotency (mobile-app-react-native.md section 3): a push whose
        // server-side commit succeeded but whose response never reached the
        // client (dropped connection, routine on flaky field connectivity)
        // gets retried by the client with the identical local_uuid. Without
        // this check that retry would call PaymentService::create() again
        // and produce a second payment row. A hit here means "already
        // synced" — short-circuit straight to the existing server_uuid
        // rather than re-creating.
        if ($localUuid !== null) {
            $existingUuid = $lookups['synced'][$localUuid] ?? null;

            if ($existingUuid !== null) {
                return [
                    ['local_uuid' => $localUuid, 'server_uuid' => $existingUuid, 'status' => 'synced'],
                    null,
                ];
            }
        }

        // Same disconnected/suspended block App\Services\PaymentService::
        // createBulk()'s loop already applies before calling create() — this
        // was missing here, letting a mobile push record a payment for a
        // customer who isn't actually payable. Mirrors createBulk() exactly:
        // only 'disconnected'/'suspended' are blocked ('passive' stays
        // payable, per StorePaymentRequest's same rule), and it's a per-item
        // skip (recorded into this sync response like any other failure),
        // never a hard exception that would abort the rest of the batch.
        // App\Services\CustomerStatusService::reconnectOne() is unaffected —
        // it calls PaymentService::create() directly, never through this
        // pushPayment() method, so its reconnection-fine payment (recorded
        // while the customer is still `disconnected`) keeps working exactly
        // as it does today.
        // One bad payment fails alone (SyncPushRequest checks only the shape).
        $invalid = Validator::make($item, self::PAYMENT_ITEM_RULES);

        if ($invalid->fails()) {
            $message = implode(' ', $invalid->errors()->all());

            $this->recordSyncQueue($deviceId, 'payment', null, $localUuid, $item, 'failed', $message);

            return [
                ['local_uuid' => $localUuid, 'status' => 'failed', 'error' => $message],
                ['entity_type' => 'payment', 'local_uuid' => $localUuid, 'message' => $message],
            ];
        }

        $customerUuid = $item['customer_uuid'] ?? null;
        $customer = is_string($customerUuid) ? $lookups['customers']->get($customerUuid) : null;

        if ($customer && in_array($customer->status, ['disconnected', 'suspended'], true)) {
            $message = "{$customer->name} is currently {$customer->status} and cannot be paid until reconnected.";

            $this->recordSyncQueue($deviceId, 'payment', null, $localUuid, $item, 'failed', $message);

            return [
                ['local_uuid' => $localUuid, 'status' => 'failed', 'error' => $message],
                ['entity_type' => 'payment', 'local_uuid' => $localUuid, 'message' => $message],
            ];
        }

        try {
            $data = PaymentData::fromArray([
                'customer_uuid' => $item['customer_uuid'] ?? null,
                'amount' => $item['amount'] ?? null,
                'credit' => $item['credit'] ?? null,
                'frequency' => $item['frequency'] ?? null,
                'months' => $item['months'] ?? null,
                'clear_arrears_first' => $item['clear_arrears_first'] ?? null,
                'recorded_offline' => true,
                'recorded_by_device' => $deviceId,
                'local_uuid' => $localUuid,
                // See this method's doc comment: the client calls this
                // field `created_at`; it is stored server-side as
                // `collected_at`, never as the payment's actual
                // `created_at` column.
                'collected_at' => $item['created_at'] ?? null,
                // Cash controls: older builds send neither — the column
                // default (`cash`) applies, exactly what they collected.
                'method' => $item['method'] ?? null,
                'method_reference' => $item['method_reference'] ?? null,
                // The receipt the phone printed at collection, if any.
                'receipt_number' => $item['receipt_number'] ?? null,
                'security_code' => $item['security_code'] ?? null,
                'receipt_issued_at' => $item['receipt_issued_at'] ?? null,
                // A second payment that month: the cash was collected, so it is
                // always recorded — flagged, with the agent's reason when the
                // phone asked them (1.0.1+), else `unconfirmed`. Never refused.
                'duplicate_reason' => in_array($item['duplicate_reason'] ?? null, Payment::DUPLICATE_REASONS_PICKABLE, true) ? $item['duplicate_reason'] : 'unconfirmed',
                'duplicate_note' => $item['duplicate_note'] ?? null,
                // A prepayment offer sold in the field (never refused offline — see PaymentService::applyPrepaymentOffer()).
                'prepayment_offer_uuid' => $item['prepayment_offer_uuid'] ?? null,
            ]);

            // The branch-scoped customer resolved up front, when there is
            // one; otherwise create() resolves (and rejects) it itself.
            $payableCustomer = is_string($customerUuid) ? $lookups['payable']->get($customerUuid) : null;

            $payment = $payableCustomer !== null
                ? $this->payments->createForCustomer($payableCustomer, $data)
                : $this->payments->create($data);

            if ($localUuid !== null) {
                // A retry of this same local_uuid later in the batch must
                // resolve to this payment, not create a second one.
                $lookups['synced'][$localUuid] = $payment->uuid;
            }

            $this->recordSyncQueue($deviceId, 'payment', $payment->uuid, $localUuid, $item, 'synced');

            return [
                ['local_uuid' => $localUuid, 'server_uuid' => $payment->uuid, 'status' => 'synced'],
                null,
            ];
        } catch (Throwable $e) {
            $message = $this->errorMessage($e);

            $this->recordSyncQueue($deviceId, 'payment', null, $localUuid, $item, 'failed', $message);

            report($e);

            return [
                ['local_uuid' => $localUuid, 'status' => 'failed', 'error' => $message],
                ['entity_type' => 'payment', 'local_uuid' => $localUuid, 'message' => $message],
            ];
        }
    }

    /**
     * A customer registered in the field, offline (installation phase 3,
     * D10) — App\Services\FieldInstallationService::register(). Idempotent
     * on local_uuid; needs `installations.field` and the agent's own zone; a
     * refusal fails only this item. `server_uuid` is the customer's uuid
     * (the one the phone generated), `activation` where the office is with
     * it and `possible_duplicate` whether the office was warned.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function pushInstallation(array $item, string $deviceId, User $actor): array
    {
        $localUuid = $item['local_uuid'] ?? null;

        try {
            $result = app(FieldInstallationService::class)->register($item, $deviceId, $actor);
            $installation = $result['installation'];

            if (! $result['replayed']) {
                $this->recordSyncQueue($deviceId, 'installation', $installation->customer?->uuid, $localUuid, $item, 'synced');
            }

            return [
                [
                    'local_uuid' => $localUuid,
                    'server_uuid' => $installation->customer?->uuid,
                    'status' => 'synced',
                    'activation' => $installation->status,
                    'possible_duplicate' => ! empty($installation->possible_duplicates),
                    'payments' => $installation->payments()->get(['uuid', 'local_uuid', 'purpose', 'amount', 'verification_status'])
                        ->map(fn (Payment $payment): array => [
                            'uuid' => $payment->uuid,
                            'local_uuid' => $payment->local_uuid,
                            'purpose' => $payment->purpose,
                            'amount' => (string) $payment->amount,
                            'verification_status' => $payment->verification_status,
                        ])
                        ->values()
                        ->all(),
                ],
                null,
            ];
        } catch (Throwable $e) {
            $message = $this->errorMessage($e);

            $this->recordSyncQueue($deviceId, 'installation', null, $localUuid, $item, 'failed', $message);

            if (! $e instanceof ValidationException) {
                report($e);
            }

            return [
                ['local_uuid' => $localUuid, 'status' => 'failed', 'error' => $message],
                ['entity_type' => 'installation', 'local_uuid' => $localUuid, 'message' => $message],
            ];
        }
    }

    /**
     * A bon request from the phone ("Request a bon",
     * docs/plans/expenditures-and-spending-controls.md item 2): queued
     * offline, created here by App\Services\VoucherService::request() as a
     * pending bon by this agent — the same approval chain as the web.
     * Idempotent on local_uuid. Needs `vouchers.request` (not merely sync
     * access); a refusal fails only this item.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function pushBonRequest(array $item, string $deviceId, User $actor, string $entityType): array
    {
        $localUuid = $item['local_uuid'] ?? null;

        // Same idempotency guard as pushPayment() above. A row an older
        // build synced before E1 is a legacy expenditure with that local_uuid.
        if ($localUuid !== null) {
            // Idempotency must see every branch (branches v2): a row that can't be seen would be re-inserted and fail its unique key forever.
            $existingUuid = Voucher::query()->withoutGlobalScope(BranchScope::class)->where('local_uuid', $localUuid)->value('uuid')
                ?? Expenditure::query()->withoutGlobalScope(BranchScope::class)->where('local_uuid', $localUuid)->value('uuid');

            if ($existingUuid !== null) {
                return [
                    ['local_uuid' => $localUuid, 'server_uuid' => $existingUuid, 'status' => 'synced'],
                    null,
                ];
            }
        }

        try {
            if (! $this->context->can('vouchers.request')) {
                throw ValidationException::withMessages(['permission' => ['You are not allowed to request bons.']]);
            }

            $voucher = $this->vouchers->request([
                'kind' => Voucher::KIND_EXPENSE,
                'payee_name' => trim((string) ($item['payee_name'] ?? '')) !== '' ? mb_substr(trim((string) $item['payee_name']), 0, 150) : 'Not stated',
                'purpose' => trim((string) ($item['purpose'] ?? '')) !== '' ? mb_substr(trim((string) $item['purpose']), 0, 500) : 'Expense recorded on the phone',
                'category_uuid' => $item['category_uuid'] ?? null,
                'amount' => number_format((float) ($item['amount'] ?? 0), 2, '.', ''),
                'needed_on' => $this->neededOn($item['needed_on'] ?? null),
                'from_collections' => (bool) ($item['from_collections'] ?? false),
                'local_uuid' => $localUuid,
                'recorded_by_device' => $deviceId,
            ], $actor);

            $this->recordSyncQueue($deviceId, $entityType, $voucher->uuid, $localUuid, $item, 'synced');

            return [
                ['local_uuid' => $localUuid, 'server_uuid' => $voucher->uuid, 'status' => 'synced', 'number' => $voucher->number],
                null,
            ];
        } catch (Throwable $e) {
            $message = $this->errorMessage($e);

            $this->recordSyncQueue($deviceId, $entityType, null, $localUuid, $item, 'failed', $message);

            if (! $e instanceof ValidationException) {
                report($e);
            }

            return [
                ['local_uuid' => $localUuid, 'status' => 'failed', 'error' => $message],
                ['entity_type' => $entityType, 'local_uuid' => $localUuid, 'message' => $message],
            ];
        }
    }

    /**
     * An older build's "Record expense" item, as a bon request: its
     * description becomes the purpose (with the day it says it was spent),
     * the payee is unknown. It waits for approval like any bon.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function legacyExpenditureAsBonRequest(array $item): array
    {
        $description = trim((string) ($item['description'] ?? ''));
        $spentAt = isset($item['spent_at']) ? substr((string) $item['spent_at'], 0, 10) : null;

        return [
            'local_uuid' => $item['local_uuid'] ?? null,
            'category_uuid' => $item['category_uuid'] ?? null,
            'amount' => $item['amount'] ?? null,
            'payee_name' => null,
            'purpose' => mb_substr($description !== '' ? $description : 'Expense recorded on an older app version', 0, 470).($spentAt ? " (spent {$spentAt})" : ''),
        ];
    }

    /** A requested day in the past or unreadable becomes today (Cameroon time). */
    private function neededOn(mixed $value): string
    {
        $today = BusinessTimezone::today();

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return $today;
        }

        $date = substr($value, 0, 10);

        return $date < $today ? $today : $date;
    }

    /**
     * Mirrors pushPayment()/pushExpenditure() for `changes.complaints[]`
     * (mobile-app-react-native.md section 3 / complaint-desk.md section 7),
     * calling App\Services\ComplaintService::create(ComplaintData $data,
     * int $submittedByUserId): Complaint — the same service the web
     * Api\ComplaintController::store() uses, so category/customer_uuid
     * validation, zone_id derivation (the submitting agent's own zone for
     * `category = 'operational'`, the customer's zone for `category =
     * 'customer'`), and the "no self-declared urgency beyond the plain
     * urgent boolean" rule all stay in exactly one place rather than being
     * re-implemented here.
     *
     * Same `created_at` -> `collected_at` client-field-name mapping as
     * pushPayment() above, for the identical reason: the client sends the
     * offline-submission timestamp as `created_at` (validated by
     * SyncPushRequest as `changes.complaints.*.created_at`), but
     * `complaints.created_at` already means "when this row landed on the
     * server" server-side, so it is stored as `complaints.collected_at`
     * instead of overwriting that column's meaning.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function pushComplaint(array $item, string $deviceId, User $actor): array
    {
        $localUuid = $item['local_uuid'] ?? null;

        // Same idempotency guard as pushPayment()/pushExpenditure() above —
        // see pushPayment()'s comment for the full rationale.
        if ($localUuid !== null) {
            $existingUuid = Complaint::query()->withoutGlobalScope(BranchScope::class)->where('local_uuid', $localUuid)->value('uuid');

            if ($existingUuid !== null) {
                return [
                    ['local_uuid' => $localUuid, 'server_uuid' => $existingUuid, 'status' => 'synced'],
                    null,
                ];
            }
        }

        try {
            $data = ComplaintData::fromArray([
                'category' => $item['category'] ?? null,
                'title' => $item['title'] ?? null,
                'description' => $item['description'] ?? null,
                'urgent' => $item['urgent'] ?? false,
                'customer_uuid' => $item['customer_uuid'] ?? null,
                'local_uuid' => $localUuid,
                // See this method's doc comment: client `created_at` ->
                // stored as `collected_at`.
                'collected_at' => $item['created_at'] ?? null,
            ]);

            $complaint = $this->complaints->create($data, $actor->id);

            $this->recordSyncQueue($deviceId, 'complaint', $complaint->uuid, $localUuid, $item, 'synced');

            return [
                ['local_uuid' => $localUuid, 'server_uuid' => $complaint->uuid, 'status' => 'synced'],
                null,
            ];
        } catch (Throwable $e) {
            $message = $this->errorMessage($e);

            $this->recordSyncQueue($deviceId, 'complaint', null, $localUuid, $item, 'failed', $message);

            report($e);

            return [
                ['local_uuid' => $localUuid, 'status' => 'failed', 'error' => $message],
                ['entity_type' => 'complaint', 'local_uuid' => $localUuid, 'message' => $message],
            ];
        }
    }

    /**
     * Branch/zone-fenced the same way every other list/show query in this
     * app already is (App\Repositories\Concerns\ScopesByBranch) — the
     * puller's own TenantContext, not the client's `since` window, decides
     * which rows are even eligible. An `agent` is fenced to the zones they
     * cover (TenantContext::currentZoneIds()) rather than the wider branch
     * fence, since a mobile agent's cache should only ever hold customers/
     * payments from the zones they actually work — narrower than what a branch-fenced
     * office role sees, and correct even for a cross-branch (null-branch)
     * office role, who still sees everything. See
     * mobile-app-react-native.md section 3 / rbac-permissions.md section 6
     * for why this scoping didn't exist before: it predates the zone-scoped
     * verify RBAC work and was flagged as a gap during that design.
     *
     * @return array<int, array<string, mixed>>
     */
    private function upsertedCustomers(?Carbon $sinceAt): array
    {
        $zoneIds = TenantContext::currentZoneIds();
        $branchId = TenantContext::currentBranchId();
        // A zone given to the agent since their last pull: its customers are
        // new to this phone though their rows haven't changed — send them all.
        $newZoneIds = $sinceAt !== null ? $this->zonesCoveredSince($sinceAt) : [];

        $customers = Customer::query()
            // latestManuscript is eager-loaded alongside zone so the mobile
            // Customers list/detail cache (mobile-app-react-native.md
            // section 4) has arrears/credit to render offline without a
            // second round trip per customer — CustomerResource::toArray()
            // exposes the same relation for the online show endpoint.
            // `subscriptions.*` (services.md section 6) is the same idea for
            // the customer's ticked services — Customer Detail
            // (app/(tabs)/customers/[uuid].tsx) renders entirely from the
            // local SQLite cache with no live call, so this is the only way
            // that screen ever sees service details at all.
            ->with(['zone', 'latestManuscript', 'subscriptions.service', 'subscriptions.serviceVariant'])
            ->when($sinceAt, fn ($query) => $query->where(fn ($inner) => $inner
                ->where('updated_at', '>=', $sinceAt)
                // Their debt moved (a payment recorded, verified or reversed;
                // a bill written) though the customer row didn't: the phone
                // needs the new unpaid_debt.
                ->orWhereIn('id', Payment::query()->withoutGlobalScope(BranchScope::class)->where('updated_at', '>=', $sinceAt)->select('customer_id'))
                ->orWhereIn('id', Manuscript::query()->withoutGlobalScope(BranchScope::class)->where('updated_at', '>=', $sinceAt)->select('customer_id'))
                ->when($newZoneIds !== [], fn ($orNew) => $orNew->orWhereIn('zone_id', $newZoneIds))))
            ->when($zoneIds !== null, fn ($query) => $query->whereIn('zone_id', $zoneIds))
            ->when(
                $zoneIds === null && $branchId !== null,
                fn ($query) => $query->where('customers.branch_id', $branchId)
            )
            ->get();

        // Promo state (installation phase 2): first billed month and the
        // free months still to come, two queries for the whole pull.
        $plans = FreeMonthPlan::forCustomers($customers);
        $serviceNames = Service::query()->pluck('name', 'id');
        $currentPeriod = BusinessTimezone::currentPeriod();
        $branchUuids = Branch::query()->pluck('uuid', 'id');
        $unpaidDebts = PaymentService::unpaidDebts($customers);

        return $customers
            ->map(fn (Customer $customer): array => [
                'uuid' => $customer->uuid,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'bill' => $customer->bill,
                'location' => $customer->location,
                'level' => $customer->level,
                'status' => $customer->status,
                'zone_uuid' => $customer->zone?->uuid,
                // The phone sells an offer at the customer's branch price (offers carry branch_prices).
                'branch_uuid' => $branchUuids[$customer->branch_id] ?? null,
                'total_arrears' => $customer->latestManuscript?->total_arrears,
                'credit' => $customer->latestManuscript?->credit,
                // Old debt still unpaid — every collector's payments counted
                // (PaymentService::unpaidDebts(), what an offer payment is
                // checked against). The phone takes off what it collected since.
                'unpaid_debt' => $unpaidDebts[$customer->id],
                'services' => $customer->subscriptions->map(fn (CustomerSubscription $row): array => [
                    'service_uuid' => $row->service->uuid,
                    'service_name' => $row->service->name,
                    'service_variant_uuid' => $row->serviceVariant?->uuid,
                    'service_variant_name' => $row->serviceVariant?->name,
                    'price' => $row->price,
                ])->values()->all(),
                'billing_starts_period' => $customer->billing_starts_period,
                // "pending" = registered in the field, not activated yet.
                'activation_status' => $customer->activation_status,
                // FREE (not PREPAID): the latest bill's month was free.
                'is_free' => $customer->latestManuscript !== null
                    && bccomp((string) $customer->latestManuscript->free_value, '0', 2) > 0
                    && bccomp((string) $customer->latestManuscript->bill, '0', 2) === 0,
                'prepaid_months_remaining' => (int) ($customer->latestManuscript?->prepaid_months_remaining ?? 0),
                // Months paid upfront per service still to come (D15).
                'service_paid_months' => collect((array) ($customer->latestManuscript?->service_prepaid ?? []))
                    ->map(fn (array $row, int|string $serviceId): array => ['service' => (string) ($serviceNames[(int) $serviceId] ?? 'Service'), 'months' => (int) ($row['months'] ?? 0)])
                    ->values()
                    ->all(),
                'free_months' => array_map(fn (array $window): array => [
                    'service' => $window['service_id'] === null ? 'All services' : (string) ($serviceNames[$window['service_id']] ?? 'Service'),
                    'from' => $window['start'],
                    'until' => $window['end'],
                ], ($plans[$customer->id] ?? FreeMonthPlan::none())->windowsFrom($currentPeriod)),
            ])
            ->all();
    }

    /**
     * This user's own field registrations that changed since $sinceAt.
     *
     * @return list<array{local_uuid: string, customer_uuid: ?string, customer_name: ?string, status: string, reason: ?string, possible_duplicate: bool, decided_at: ?string}>
     */
    private function changedInstallations(?Carbon $sinceAt, User $actor): array
    {
        return FieldInstallation::query()
            ->with('customer:id,uuid,name,deleted_at')
            ->where('submitted_by', $actor->id)
            ->when($sinceAt, fn ($query) => $query->where('updated_at', '>=', $sinceAt))
            ->orderBy('id')
            ->get()
            ->map(fn (FieldInstallation $installation): array => [
                'local_uuid' => $installation->local_uuid,
                'customer_uuid' => $installation->customer?->uuid,
                'customer_name' => $installation->customer?->name,
                'status' => $installation->status,
                'reason' => $installation->status === FieldInstallation::STATUS_REJECTED ? $installation->decision_note : null,
                'possible_duplicate' => ! empty($installation->possible_duplicates),
                'decided_at' => $installation->decided_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Customers archived (soft-deleted) since the client's last sync —
     * returned as bare uuids for the device to evict from its local cache.
     * Same zone/branch fence as upsertedCustomers() above. `onlyTrashed()`
     * escapes the SoftDeletes global scope; the window is `deleted_at`, not
     * `updated_at`, so a customer archived long ago isn't re-sent forever.
     *
     * @return array<int, string>
     */
    /**
     * Zones the pulling agent was given at or after $sinceAt — one query.
     * Empty for anyone not zone-scoped.
     *
     * @return list<int>
     */
    private function zonesCoveredSince(Carbon $sinceAt): array
    {
        $context = app()->bound(TenantContext::class) ? app(TenantContext::class) : null;

        if ($context === null || $context->zoneIds === null || $context->zoneIds === []) {
            return [];
        }

        return ZoneAgent::query()->withoutGlobalScope(BranchScope::class)
            ->whereIn('zone_id', $context->zoneIds)
            ->whereIn('agent_id', Agent::query()->withoutGlobalScope(BranchScope::class)->select('id')->where('user_id', $context->tenantUser->user_id))
            ->where('created_at', '>=', $sinceAt)
            ->pluck('zone_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    private function deletedCustomers(?Carbon $sinceAt): array
    {
        $zoneIds = TenantContext::currentZoneIds();
        $branchId = TenantContext::currentBranchId();

        return Customer::query()
            ->onlyTrashed()
            ->when($sinceAt, fn ($query) => $query->where('deleted_at', '>=', $sinceAt))
            ->when($zoneIds !== null, fn ($query) => $query->whereIn('zone_id', $zoneIds))
            ->when(
                $zoneIds === null && $branchId !== null,
                fn ($query) => $query->where('customers.branch_id', $branchId)
            )
            ->pluck('uuid')
            ->all();
    }

    /**
     * Best-effort: payments don't carry a dedicated "verification status
     * changed at" timestamp, so this filters by `updated_at >= $since` AND
     * the target status — precise enough for a mobile delta pull, but a
     * payment that was touched for an unrelated reason after being verified
     * would not be re-included unless it also picked up a fresh updated_at.
     * See report note.
     *
     * Same branch/zone fence as upsertedCustomers() above: the zone through
     * the customer relation (Payment has no direct zone), the branch on the
     * payment's own branch_id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function changedPayments(string $status, ?Carbon $sinceAt): array
    {
        $zoneIds = TenantContext::currentZoneIds();
        $branchId = TenantContext::currentBranchId();

        return Payment::query()
            ->with(['customer', 'verification'])
            ->where('verification_status', $status)
            // A first / full pull only needs recent verification results (the phone
            // tracks its own queued payments), not every payment ever — that payload
            // never finished downloading on 3G.
            ->where('updated_at', '>=', $sinceAt ?? now()->subDays(self::FULL_PULL_PAYMENT_DAYS))
            ->when($zoneIds !== null, fn ($query) => $query->whereHas('customer', fn ($inner) => $inner->whereIn('zone_id', $zoneIds)))
            ->when(
                $zoneIds === null && $branchId !== null,
                fn ($query) => $query->where('payments.branch_id', $branchId)
            )
            ->get()
            ->map(function (Payment $payment) use ($status): array {
                $entry = [
                    'uuid' => $payment->uuid,
                    'customer_uuid' => $payment->customer?->uuid,
                    'amount' => $payment->amount,
                    'verification_status' => $payment->verification_status,
                ];

                if ($status === 'rejected') {
                    $entry['rejection_reason'] = $payment->verification?->notes;
                }

                return $entry;
            })
            ->all();
    }

    /**
     * Verifies/records the device_id an agent is syncing from. Per
     * offline-sync-strategy.md section 9 and the task's explicit scope
     * note: this is deliberately "first-sync-wins" — whatever device_id
     * arrives is simply accepted and stored on `agents.sync_token` (there
     * is no separate `agents.device_id` column; sync_token doubles as the
     * agent's registered active device identifier). No multi-device
     * revocation logic is implemented — it isn't specified anywhere.
     * Callers with no matching Agent row (e.g. an admin/manager testing
     * sync endpoints) are simply skipped.
     */
    private function registerDevice(User $actor, string $deviceId): void
    {
        $agent = Agent::query()->where('user_id', $actor->id)->first();

        if (! $agent) {
            return;
        }

        $agent->update([
            'sync_token' => $deviceId,
            'last_sync_at' => Carbon::now(),
        ]);
    }

    private function touchAgentLastSync(User $actor): void
    {
        Agent::query()->where('user_id', $actor->id)->update(['last_sync_at' => Carbon::now()]);
    }

    /**
     * Writes one sync_queue row (direction='up') per pushed item, purely
     * for observability/debugging per database-schema.md's sync_queue
     * section — nothing downstream currently reads these rows back to drive
     * behavior.
     *
     * @param  array<string, mixed>  $payload
     */
    private function recordSyncQueue(
        string $deviceId,
        string $entityType,
        ?string $entityUuid,
        ?string $localUuid,
        array $payload,
        string $status,
        ?string $error = null,
    ): void {
        SyncQueue::query()->create([
            'device_id' => $deviceId,
            'direction' => 'up',
            'entity_type' => $entityType,
            'entity_uuid' => $entityUuid,
            'local_uuid' => $localUuid,
            'payload' => $payload,
            'status' => $status,
            'attempt_count' => 1,
            'attempted_at' => Carbon::now(),
            'completed_at' => $status === 'synced' ? Carbon::now() : null,
            'error_message' => $error,
        ]);
    }

    /**
     * The phone's collection time, or the server's now when it can't be
     * right: unreadable, ahead of now (beyond a few minutes of clock drift),
     * or older than OLDEST_COLLECTION_DAYS. push() keeps the phone's value as
     * `phone_created_at` in the sync_queue payload, for the record.
     */
    private function boundedCollectedAt(mixed $collectedAt): ?string
    {
        if (! is_string($collectedAt) || $collectedAt === '') {
            return null;
        }

        try {
            $at = Carbon::parse($collectedAt);
        } catch (Throwable) {
            return Carbon::now()->toIso8601String();
        }

        $now = Carbon::now();

        return $at->greaterThan($now->copy()->addMinutes(10)) || $at->lessThan($now->copy()->subDays(self::OLDEST_COLLECTION_DAYS))
            ? $now->toIso8601String()
            : $collectedAt;
    }

    private function errorMessage(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->implode(' ');
        }

        return $e->getMessage();
    }
}
