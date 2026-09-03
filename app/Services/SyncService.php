<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ComplaintData;
use App\DataTransferObjects\ExpenditureData;
use App\DataTransferObjects\PaymentData;
use App\Models\Agent;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\CustomerSubscription;
use App\Models\Expenditure;
use App\Models\Payment;
use App\Models\SyncQueue;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Server side of the field-agent offline sync protocol (see
 * .ai/skills/cncms/cncms-context/references/offline-sync-strategy.md and
 * references/api-spec.md section 7).
 */
class SyncService
{
    public function __construct(
        private readonly PaymentService $payments,
        private readonly PaymentVerificationService $verifications,
        private readonly ExpenditureService $expenditures,
        private readonly ComplaintService $complaints,
        private readonly NotificationService $notifications,
        private readonly TenantContext $context,
    ) {}

    /**
     * Push (mobile -> server): applies queued offline changes one item at a
     * time. A failure on one item (bad customer_uuid, validation error,
     * anything) is caught and reported per-item — it must never abort
     * processing of the rest of the batch. This mirrors the defensive
     * per-record try/catch in App\Console\Commands\ManuscriptCalculate's
     * runForEveryCustomer(), which does the same for per-customer errors.
     *
     * @param  array<string, mixed>  $changes  {"payments": [...], "expenditures": [...], "complaints": [...]}
     * @return array<string, mixed>
     */
    public function push(array $changes, string $deviceId, ?string $lastSyncAt, User $actor): array
    {
        $this->registerDevice($actor, $deviceId);

        $results = ['payments' => [], 'expenditures' => [], 'complaints' => []];
        $errors = [];

        foreach ($changes['payments'] ?? [] as $item) {
            [$entry, $error] = $this->pushPayment($item, $deviceId);
            $results['payments'][] = $entry;

            if ($error !== null) {
                $errors[] = $error;
            }
        }

        foreach ($changes['expenditures'] ?? [] as $item) {
            [$entry, $error] = $this->pushExpenditure($item, $deviceId, $actor);
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
     * @return array{message: string, receipt_url: string}
     */
    public function uploadReceipt(string $entityType, string $entityUuid, UploadedFile $file): array
    {
        if ($entityType === 'payment') {
            $payment = Payment::query()->where('uuid', $entityUuid)->firstOrFail();

            $storedPath = $file->store('receipts/payments', 'public');

            $this->verifications->attachReceipt($payment, $storedPath);

            return [
                'message' => 'Receipt uploaded',
                'receipt_url' => Storage::disk('public')->url($storedPath),
            ];
        }

        if ($entityType === 'expenditure') {
            $expenditure = Expenditure::query()->where('uuid', $entityUuid)->firstOrFail();

            $storedPath = $file->store('receipts/expenditures', 'public');

            // No verification workflow exists for expenditures (unlike
            // payments), so there is no service indirection to go through —
            // updating the column directly is the whole operation.
            $expenditure->update(['receipt_path' => $storedPath]);

            return [
                'message' => 'Receipt uploaded',
                'receipt_url' => Storage::disk('public')->url($storedPath),
            ];
        }

        throw ValidationException::withMessages(['entity_type' => ['entity_type must be payment or expenditure.']]);
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
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function pushPayment(array $item, string $deviceId): array
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
            $existingUuid = Payment::query()->where('local_uuid', $localUuid)->value('uuid');

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
        $customerUuid = $item['customer_uuid'] ?? null;
        $customer = is_string($customerUuid) ? Customer::query()->where('uuid', $customerUuid)->first() : null;

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
            ]);

            $payment = $this->payments->create($data);

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
     * Mirrors pushPayment() for `changes.expenditures[]`, calling
     * App\Services\ExpenditureService::create(ExpenditureData $data, int
     * $userId): Expenditure.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: array<string, mixed>, 1: ?array<string, mixed>}
     */
    private function pushExpenditure(array $item, string $deviceId, User $actor): array
    {
        $localUuid = $item['local_uuid'] ?? null;

        // Same idempotency guard as pushPayment() above — see that method's
        // comment for the full rationale.
        if ($localUuid !== null) {
            $existingUuid = Expenditure::query()->where('local_uuid', $localUuid)->value('uuid');

            if ($existingUuid !== null) {
                return [
                    ['local_uuid' => $localUuid, 'server_uuid' => $existingUuid, 'status' => 'synced'],
                    null,
                ];
            }
        }

        try {
            $data = ExpenditureData::fromArray([
                'category_uuid' => $item['category_uuid'] ?? null,
                'amount' => $item['amount'] ?? null,
                'description' => $item['description'] ?? null,
                'spent_at' => $item['spent_at'] ?? null,
                'notes' => $item['notes'] ?? null,
                'recorded_offline' => true,
                'recorded_by_device' => $deviceId,
                'local_uuid' => $localUuid,
            ]);

            $expenditure = $this->expenditures->create($data, $actor->id);

            $this->recordSyncQueue($deviceId, 'expenditure', $expenditure->uuid, $localUuid, $item, 'synced');

            return [
                ['local_uuid' => $localUuid, 'server_uuid' => $expenditure->uuid, 'status' => 'synced'],
                null,
            ];
        } catch (Throwable $e) {
            $message = $this->errorMessage($e);

            $this->recordSyncQueue($deviceId, 'expenditure', null, $localUuid, $item, 'failed', $message);

            report($e);

            return [
                ['local_uuid' => $localUuid, 'status' => 'failed', 'error' => $message],
                ['entity_type' => 'expenditure', 'local_uuid' => $localUuid, 'message' => $message],
            ];
        }
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
            $existingUuid = Complaint::query()->where('local_uuid', $localUuid)->value('uuid');

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
     * which rows are even eligible. An `agent` is fenced to their own zone
     * (TenantContext::currentZoneId()) rather than the wider branch fence,
     * since a mobile agent's cache should only ever hold customers/payments
     * from the zone they actually work — narrower than what a branch-fenced
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
        $zoneId = TenantContext::currentZoneId();
        $branchId = TenantContext::currentBranchId();

        return Customer::query()
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
            ->when($sinceAt, fn ($query) => $query->where('updated_at', '>=', $sinceAt))
            ->when($zoneId !== null, fn ($query) => $query->where('zone_id', $zoneId))
            ->when(
                $zoneId === null && $branchId !== null,
                fn ($query) => $query->whereHas('zone', fn ($inner) => $inner->where('branch_id', $branchId))
            )
            ->get()
            ->map(fn (Customer $customer): array => [
                'uuid' => $customer->uuid,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'bill' => $customer->bill,
                'location' => $customer->location,
                'level' => $customer->level,
                'status' => $customer->status,
                'zone_uuid' => $customer->zone?->uuid,
                'total_arrears' => $customer->latestManuscript?->total_arrears,
                'credit' => $customer->latestManuscript?->credit,
                'services' => $customer->subscriptions->map(fn (CustomerSubscription $row): array => [
                    'service_uuid' => $row->service->uuid,
                    'service_name' => $row->service->name,
                    'service_variant_uuid' => $row->serviceVariant?->uuid,
                    'service_variant_name' => $row->serviceVariant?->name,
                    'price' => $row->price,
                ])->values()->all(),
            ])
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
    private function deletedCustomers(?Carbon $sinceAt): array
    {
        $zoneId = TenantContext::currentZoneId();
        $branchId = TenantContext::currentBranchId();

        return Customer::query()
            ->onlyTrashed()
            ->when($sinceAt, fn ($query) => $query->where('deleted_at', '>=', $sinceAt))
            ->when($zoneId !== null, fn ($query) => $query->where('zone_id', $zoneId))
            ->when(
                $zoneId === null && $branchId !== null,
                fn ($query) => $query->whereHas('zone', fn ($inner) => $inner->where('branch_id', $branchId))
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
     * Same branch/zone fence as upsertedCustomers() above, applied via the
     * customer relation (Payment has no direct zone — see
     * App\Models\Payment::branchRouteBindingRelation()'s identical
     * 'customer.zone' path).
     *
     * @return array<int, array<string, mixed>>
     */
    private function changedPayments(string $status, ?Carbon $sinceAt): array
    {
        $zoneId = TenantContext::currentZoneId();
        $branchId = TenantContext::currentBranchId();

        return Payment::query()
            ->with(['customer', 'verification'])
            ->where('verification_status', $status)
            ->when($sinceAt, fn ($query) => $query->where('updated_at', '>=', $sinceAt))
            ->when($zoneId !== null, fn ($query) => $query->whereHas('customer', fn ($inner) => $inner->where('zone_id', $zoneId)))
            ->when(
                $zoneId === null && $branchId !== null,
                fn ($query) => $query->whereHas('customer.zone', fn ($inner) => $inner->where('branch_id', $branchId))
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

    private function errorMessage(Throwable $e): string
    {
        if ($e instanceof ValidationException) {
            return collect($e->errors())->flatten()->implode(' ');
        }

        return $e->getMessage();
    }
}
