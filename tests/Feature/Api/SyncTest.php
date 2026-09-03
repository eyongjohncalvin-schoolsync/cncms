<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Complaint;
use App\Models\Customer;
use App\Models\Expenditure;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Services\NotificationService;
use Database\Factories\AgentFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ExpenseCategoryFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Runs against the real `tenantswecom` schema, same strategy as
 * PaymentTest/ManuscriptTest — see InteractsWithTenantRoles for the
 * transaction/role-switching setup this shares.
 */
class SyncTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    /**
     * Explicitly 'active': CustomerFactory's default state picks status
     * randomly (including a 20% chance of 'disconnected'), which made
     * several payment-push tests relying on this shared helper intermittently
     * fail — a disconnected customer correctly gets its payment skipped (see
     * test_push_payment_for_a_disconnected_customer_is_skipped below), so any
     * OTHER test using this helper that expects a normal 'synced' payment
     * would flake whenever the random draw landed on 'disconnected'. Mirrors
     * the identical fix already applied to tests/Feature/Web/PaymentTest.php's
     * own customer() helper for the same reason.
     */
    private function customer(): Customer
    {
        $zone = ZoneFactory::new()->create();

        return CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
    }

    /**
     * Audit scenario, now fixed (business-rules.md #1 / StorePaymentRequest's
     * doc comment): the disconnected/suspended payment block previously
     * existed ONLY at the HTTP-validation layer (StorePaymentRequest) and
     * inside PaymentService::createBulk()'s loop — deliberately NOT inside
     * PaymentService::create() itself, so that CustomerStatusService::
     * reconnectOne()'s direct create() call (recording the reconnection fine
     * while status is still 'disconnected') keeps working. But
     * SyncService::pushPayment() ALSO calls PaymentService::create()
     * directly, the exact same way reconnectOne() does — bypassing
     * StorePaymentRequest entirely, and unlike createBulk() had no block of
     * its own. SyncService::pushPayment() now carries the identical
     * disconnected/suspended check createBulk() already had, so a mobile
     * push for a disconnected customer is skipped (a per-item 'failed' entry
     * in the sync response, never a hard error that would abort the rest of
     * the batch) instead of quietly doing what the web form and web
     * bulk-entry both explicitly refuse to do. See
     * test_push_payment_for_a_disconnected_customer_via_reconnection_still_works
     * below for proof the legitimate reconnectOne() bypass is unaffected.
     */
    public function test_push_payment_for_a_disconnected_customer_is_skipped(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'disconnected']);
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-disconnected-1',
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => $localUuid,
                            'customer_uuid' => $customer->uuid,
                            'amount' => 2500,
                            'frequency' => 'monthly',
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $entry = collect($response->json('results.payments'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame('failed', $entry['status']);
        $this->assertStringContainsString('disconnected', $entry['error']);
        $this->assertNotEmpty($response->json('errors'));

        $this->assertDatabaseMissing('payments', [
            'customer_id' => $customer->id,
            'amount' => 2500,
        ]);
    }

    /**
     * A `suspended` customer must be blocked the same way — mirrors
     * createBulk()'s own ['disconnected', 'suspended'] check exactly.
     */
    public function test_push_payment_for_a_suspended_customer_is_skipped(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'suspended']);
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-suspended-1',
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => $localUuid,
                            'customer_uuid' => $customer->uuid,
                            'amount' => 2500,
                            'frequency' => 'monthly',
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $entry = collect($response->json('results.payments'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame('failed', $entry['status']);
        $this->assertDatabaseMissing('payments', [
            'customer_id' => $customer->id,
            'amount' => 2500,
        ]);
    }

    /**
     * A `passive` customer must NOT be blocked — createBulk()'s check
     * deliberately leaves `passive` payable, and pushPayment() mirrors that
     * exact same allow-list rather than inventing a stricter rule of its own.
     */
    public function test_push_payment_for_a_passive_customer_still_succeeds(): void
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'passive']);
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-passive-1',
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => $localUuid,
                            'customer_uuid' => $customer->uuid,
                            'amount' => 2500,
                            'frequency' => 'monthly',
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $entry = collect($response->json('results.payments'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame('synced', $entry['status']);
        $this->assertDatabaseHas('payments', [
            'customer_id' => $customer->id,
            'amount' => 2500,
        ]);
    }

    /**
     * 2026-08 revision: auto-verify is purely role/zone-scope based (does
     * this actor already hold PaymentPolicy::verify() power for this
     * customer?), not channel-based — see App\Services\PaymentService::
     * create()'s doc comment. A 'super' pusher's synced payment now DOES
     * auto-verify, same as it would via a plain POST /payments — the owner's
     * explicit call: trust is a property of the role, not of whether the
     * entry came in live or via offline sync. `recorded_offline`/
     * `recorded_by_device` are still stamped either way, purely for the
     * "Offline"/"Office" display badge and audit trail.
     */
    public function test_push_maps_local_uuid_to_server_uuid_and_auto_verifies_for_a_trusted_role(): void
    {
        $customer = $this->customer();
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('super');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-abc-123',
                'last_sync_at' => null,
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => $localUuid,
                            'customer_uuid' => $customer->uuid,
                            'amount' => 2500,
                            'frequency' => 'monthly',
                        ],
                    ],
                ],
            ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $entry = collect($response->json('results.payments'))->firstWhere('local_uuid', $localUuid);

        $this->assertNotNull($entry);
        $this->assertSame('synced', $entry['status']);
        $this->assertNotEmpty($entry['server_uuid']);
        $this->assertNotSame($localUuid, $entry['server_uuid']);

        $this->assertDatabaseHas('payments', [
            'uuid' => $entry['server_uuid'],
            'verification_status' => 'verified',
            'recorded_offline' => true,
            'recorded_by_device' => 'device-abc-123',
        ]);
    }

    /**
     * The counterpart to the test above: a role with no unconditional
     * verify power (agent, with no Agent row → zoneId null → the zone
     * fence in PaymentPolicy::verify()/PaymentService::create() never
     * matches) still lands 'pending' when synced, exactly as a plain
     * POST /payments from that same actor would.
     */
    public function test_push_stays_pending_for_an_agent_with_no_zone_match(): void
    {
        $customer = $this->customer();
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-abc-123',
                'last_sync_at' => null,
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => $localUuid,
                            'customer_uuid' => $customer->uuid,
                            'amount' => 2500,
                            'frequency' => 'monthly',
                        ],
                    ],
                ],
            ]);

        $response->assertOk()->assertJsonPath('status', 'success');

        $entry = collect($response->json('results.payments'))->firstWhere('local_uuid', $localUuid);

        $this->assertDatabaseHas('payments', [
            'uuid' => $entry['server_uuid'],
            'verification_status' => 'pending',
            'recorded_offline' => true,
            'recorded_by_device' => 'device-abc-123',
        ]);
    }

    /**
     * The core of the collected_at fix: the client sends the actual
     * field-collection timestamp as `created_at` in the wire payload
     * (SyncPushRequest validates `changes.payments.*.created_at` — see
     * mobile/src/sync/SyncManager.ts:281) and it must land on
     * `payments.collected_at`, NOT overwrite `payments.created_at` — that
     * column keeps meaning "when this row landed on the server", which
     * PaymentController::index()'s month-scoping/"Today" filter and the
     * daily-close-of-day design both rely on (see SyncService::
     * pushPayment()'s doc comment).
     */
    public function test_push_payment_maps_client_created_at_to_collected_at_without_touching_created_at(): void
    {
        // Explicit 'active' status (not the shared customer() helper, whose
        // status randomizes per CustomerFactory::definition() and could
        // otherwise flake this test into the disconnected/suspended-block
        // path, which is a different behavior entirely from what this test
        // is about).
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
        $localUuid = (string) Str::uuid();
        $collectedAt = now()->subHours(5)->startOfSecond();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-collected-1',
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => $localUuid,
                            'customer_uuid' => $customer->uuid,
                            'amount' => 2500,
                            'frequency' => 'monthly',
                            'created_at' => $collectedAt->toIso8601String(),
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $entry = collect($response->json('results.payments'))->firstWhere('local_uuid', $localUuid);
        $this->assertSame('synced', $entry['status']);

        $payment = Payment::query()->where('uuid', $entry['server_uuid'])->firstOrFail();

        $this->assertNotNull($payment->collected_at);
        $this->assertTrue(
            $payment->collected_at->equalTo($collectedAt),
            'collected_at must match the timestamp the client sent as created_at.'
        );

        // created_at is untouched: it reflects server-arrival time (roughly
        // "now"), not the 5-hours-ago collection timestamp sent by the
        // client — this is the exact behavior PaymentController::index()'s
        // month-scoping and the daily-close-of-day design depend on. Checked
        // via a coarse hour-diff (rather than a tight "between now and a
        // captured $before" window) to avoid sub-second/timestamp-precision
        // flakiness — the only thing that actually matters here is that
        // created_at is nowhere near the 5-hours-ago collected_at value.
        $this->assertGreaterThanOrEqual(4, $payment->created_at->diffInHours($collectedAt, true));
        $this->assertFalse($payment->created_at->equalTo($collectedAt));
    }

    /**
     * A push with no `created_at` at all (an older mobile build, or a
     * payment entered with no client clock available) must still succeed —
     * collected_at simply stays null, nothing errors.
     */
    public function test_push_payment_with_no_created_at_leaves_collected_at_null(): void
    {
        // Explicit 'active' status — see the comment in the test above for
        // why this doesn't use the shared customer() helper.
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id, 'bill' => 2500, 'status' => 'active']);
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-collected-2',
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => $localUuid,
                            'customer_uuid' => $customer->uuid,
                            'amount' => 2500,
                            'frequency' => 'monthly',
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $entry = collect($response->json('results.payments'))->firstWhere('local_uuid', $localUuid);
        $this->assertSame('synced', $entry['status']);

        $this->assertDatabaseHas('payments', [
            'uuid' => $entry['server_uuid'],
            'collected_at' => null,
        ]);
    }

    public function test_push_continues_processing_other_items_when_one_item_is_invalid(): void
    {
        $goodCustomer = $this->customer();
        $goodLocalUuid = (string) Str::uuid();
        $badLocalUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-xyz-999',
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => $badLocalUuid,
                            'customer_uuid' => (string) Str::uuid(), // syntactically valid, does not exist
                            'amount' => 2500,
                            'frequency' => 'monthly',
                        ],
                        [
                            'local_uuid' => $goodLocalUuid,
                            'customer_uuid' => $goodCustomer->uuid,
                            'amount' => 3000,
                            'frequency' => 'monthly',
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $results = collect($response->json('results.payments'));

        $bad = $results->firstWhere('local_uuid', $badLocalUuid);
        $good = $results->firstWhere('local_uuid', $goodLocalUuid);

        $this->assertNotNull($bad);
        $this->assertSame('failed', $bad['status']);
        $this->assertArrayHasKey('error', $bad);

        $this->assertNotNull($good);
        $this->assertSame('synced', $good['status']);
        $this->assertNotEmpty($good['server_uuid']);

        $this->assertDatabaseHas('payments', ['uuid' => $good['server_uuid']]);
        $this->assertNotEmpty($response->json('errors'));
    }

    /**
     * Fix 1 (mobile-app-react-native.md section 3): a client retry after a
     * dropped connection resends the identical local_uuid. The server must
     * recognize the second push as already-applied and return the same
     * server_uuid rather than creating a second payment row — the real
     * duplicate-payment bug this local_uuid column exists to close.
     */
    public function test_push_is_idempotent_when_the_same_local_uuid_is_retried(): void
    {
        $customer = $this->customer();
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $payload = [
            'device_id' => 'device-retry-1',
            'changes' => [
                'payments' => [
                    [
                        'local_uuid' => $localUuid,
                        'customer_uuid' => $customer->uuid,
                        'amount' => 2500,
                        'frequency' => 'monthly',
                    ],
                ],
            ],
        ];

        $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', $payload);
        $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', $payload);

        $first->assertOk();
        $second->assertOk();

        $firstEntry = collect($first->json('results.payments'))->firstWhere('local_uuid', $localUuid);
        $secondEntry = collect($second->json('results.payments'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame('synced', $firstEntry['status']);
        $this->assertSame('synced', $secondEntry['status']);
        $this->assertNotEmpty($firstEntry['server_uuid']);
        $this->assertSame($firstEntry['server_uuid'], $secondEntry['server_uuid']);

        $this->assertSame(
            1,
            Payment::query()->where('local_uuid', $localUuid)->count(),
            'A retried push with the same local_uuid must not create a second payment row.'
        );
    }

    /**
     * Audit scenario: a delayed retry racing against a fast office reviewer.
     * pushPayment()'s idempotency lookup (SyncService::pushPayment()) is
     * `Payment::where('local_uuid', $localUuid)->value('uuid')` — it does
     * NOT filter on verification_status, so it must find and return the
     * existing row's uuid regardless of whether that row has since moved
     * from 'pending' to 'verified' (or 'rejected') by the time the retry
     * arrives. If this lookup were ever narrowed to
     * `where('verification_status', 'pending')` it would stop matching a
     * verified/rejected row and the retry would silently create a second
     * payment for the same real-world transaction — a genuine double-count.
     * This proves that regression doesn't currently exist.
     */
    public function test_push_idempotency_holds_after_the_original_payment_has_already_been_verified(): void
    {
        $customer = $this->customer();
        $localUuid = (string) Str::uuid();

        $agentToken = $this->tokenForRole('agent');

        $payload = [
            'device_id' => 'device-race-1',
            'changes' => [
                'payments' => [
                    [
                        'local_uuid' => $localUuid,
                        'customer_uuid' => $customer->uuid,
                        'amount' => 2500,
                        'frequency' => 'monthly',
                    ],
                ],
            ],
        ];

        $first = $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->postJson('/api/v1/sync/push', $payload);
        $first->assertOk();

        $serverUuid = collect($first->json('results.payments'))->firstWhere('local_uuid', $localUuid)['server_uuid'];

        // A fast office reviewer approves the payment before the agent's
        // delayed retry ever reaches the server.
        $managerToken = $this->tokenForRole('manager');
        $this->withHeader('Authorization', "Bearer {$managerToken}")
            ->postJson("/api/v1/payments/{$serverUuid}/verify", ['action' => 'approve'])
            ->assertOk();

        $this->assertDatabaseHas('payments', ['uuid' => $serverUuid, 'verification_status' => 'verified']);

        // The delayed retry: same local_uuid, arrives after verification.
        $second = $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->postJson('/api/v1/sync/push', $payload);
        $second->assertOk();

        $secondEntry = collect($second->json('results.payments'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame('synced', $secondEntry['status']);
        $this->assertSame($serverUuid, $secondEntry['server_uuid'], 'The retry must resolve to the same already-verified row, not create a new one.');

        $this->assertSame(
            1,
            Payment::query()->where('local_uuid', $localUuid)->count(),
            'A retried push arriving after verification must not create a second payment row (double-count risk).'
        );
        // Verification state must be untouched by the retry.
        $this->assertDatabaseHas('payments', ['uuid' => $serverUuid, 'verification_status' => 'verified']);
    }

    /**
     * Same race, but the reviewer rejects instead of approving — proves the
     * idempotency lookup finds the row for a 'rejected' state too, not just
     * 'verified'.
     */
    public function test_push_idempotency_holds_after_the_original_payment_has_already_been_rejected(): void
    {
        $customer = $this->customer();
        $localUuid = (string) Str::uuid();

        $agentToken = $this->tokenForRole('agent');

        $payload = [
            'device_id' => 'device-race-2',
            'changes' => [
                'payments' => [
                    [
                        'local_uuid' => $localUuid,
                        'customer_uuid' => $customer->uuid,
                        'amount' => 2500,
                        'frequency' => 'monthly',
                    ],
                ],
            ],
        ];

        $first = $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->postJson('/api/v1/sync/push', $payload);
        $first->assertOk();

        $serverUuid = collect($first->json('results.payments'))->firstWhere('local_uuid', $localUuid)['server_uuid'];

        $managerToken = $this->tokenForRole('manager');
        $this->withHeader('Authorization', "Bearer {$managerToken}")
            ->postJson("/api/v1/payments/{$serverUuid}/verify", ['action' => 'reject', 'notes' => 'Amount mismatch.'])
            ->assertOk();

        $this->assertDatabaseHas('payments', ['uuid' => $serverUuid, 'verification_status' => 'rejected']);

        $second = $this->withHeader('Authorization', "Bearer {$agentToken}")
            ->postJson('/api/v1/sync/push', $payload);
        $second->assertOk();

        $secondEntry = collect($second->json('results.payments'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame($serverUuid, $secondEntry['server_uuid']);
        $this->assertSame(
            1,
            Payment::query()->where('local_uuid', $localUuid)->count(),
            'A retried push arriving after rejection must not create a second payment row.'
        );
        $this->assertDatabaseHas('payments', ['uuid' => $serverUuid, 'verification_status' => 'rejected']);
    }

    /**
     * Same idempotency guarantee as the payments test above, for
     * pushExpenditure().
     */
    public function test_push_expenditure_is_idempotent_when_the_same_local_uuid_is_retried(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $payload = [
            'device_id' => 'device-retry-exp-1',
            'changes' => [
                'expenditures' => [
                    [
                        'local_uuid' => $localUuid,
                        'category_uuid' => $category->uuid,
                        'amount' => 1500,
                        'description' => 'Fuel for zone rounds',
                        'spent_at' => now()->toDateString(),
                    ],
                ],
            ],
        ];

        $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', $payload);
        $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', $payload);

        $first->assertOk();
        $second->assertOk();

        $firstEntry = collect($first->json('results.expenditures'))->firstWhere('local_uuid', $localUuid);
        $secondEntry = collect($second->json('results.expenditures'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame('synced', $firstEntry['status']);
        $this->assertSame('synced', $secondEntry['status']);
        $this->assertNotEmpty($firstEntry['server_uuid']);
        $this->assertSame($firstEntry['server_uuid'], $secondEntry['server_uuid']);

        $this->assertSame(
            1,
            Expenditure::query()->where('local_uuid', $localUuid)->count(),
            'A retried push with the same local_uuid must not create a second expenditure row.'
        );
    }

    public function test_push_expenditure_is_synced(): void
    {
        $category = ExpenseCategoryFactory::new()->create();
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-exp-1',
                'changes' => [
                    'expenditures' => [
                        [
                            'local_uuid' => $localUuid,
                            'category_uuid' => $category->uuid,
                            'amount' => 1500,
                            'description' => 'Fuel for zone rounds',
                            'spent_at' => now()->toDateString(),
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $entry = collect($response->json('results.expenditures'))->firstWhere('local_uuid', $localUuid);

        $this->assertNotNull($entry);
        $this->assertSame('synced', $entry['status']);
        $this->assertNotEmpty($entry['server_uuid']);

        $this->assertDatabaseHas('expenditures', [
            'uuid' => $entry['server_uuid'],
            'recorded_offline' => true,
            'recorded_by_device' => 'device-exp-1',
        ]);
    }

    public function test_pull_with_null_since_returns_all_customers(): void
    {
        $a = $this->customer();
        $b = $this->customer();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/sync/pull');

        $response->assertOk();

        $upsertedUuids = collect($response->json('changes.customers.upserted'))->pluck('uuid');
        $this->assertGreaterThanOrEqual(2, $upsertedUuids->count());
        $this->assertContains($a->uuid, $upsertedUuids);
        $this->assertContains($b->uuid, $upsertedUuids);

        // The two freshly-created customers are active, so neither is a
        // tombstone. We can't assert `deleted === []` — this runs against the
        // real `tenantswecom` schema, which already holds genuinely archived
        // customers, and with a null `since` deletedCustomers() has no
        // window to filter them out.
        $deletedUuids = $response->json('changes.customers.deleted');
        $this->assertNotContains($a->uuid, $deletedUuids);
        $this->assertNotContains($b->uuid, $deletedUuids);
    }

    /**
     * services.md section 6 — Customer Detail (app/(tabs)/customers/
     * [uuid].tsx) renders entirely from the local SQLite cache with no
     * live call, so pull() is the ONLY way that screen ever sees a
     * customer's services at all.
     */
    public function test_pull_carries_each_customers_services(): void
    {
        $customer = $this->customer();
        $tv = Service::query()->where('slug', 'tv')->firstOrFail();
        $customer->subscriptions()->create(['service_id' => $tv->id, 'price' => 5000]);

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');

        $response->assertOk();

        $row = collect($response->json('changes.customers.upserted'))->firstWhere('uuid', $customer->uuid);
        $this->assertNotNull($row);
        $this->assertCount(1, $row['services']);
        $this->assertSame($tv->uuid, $row['services'][0]['service_uuid']);
        $this->assertSame('5000.00', $row['services'][0]['price']);
    }

    /**
     * Fix 2 (mobile-app-react-native.md section 3 / rbac-permissions.md
     * section 6): before this fix, upsertedCustomers()/changedPayments()
     * applied zero branch/zone scoping — any authenticated puller got the
     * tenant's entire customer/payment set. An agent's mobile cache must be
     * fenced to their own zone (TenantContext::currentZoneId()), mirroring
     * BranchScopingTest's "real data in two different scopes, assert one
     * actor sees only their own" pattern.
     */
    public function test_agent_pull_never_includes_customers_or_payments_outside_their_own_zone(): void
    {
        $zoneA = ZoneFactory::new()->create();
        $zoneB = ZoneFactory::new()->create();

        $customerA = CustomerFactory::new()->create(['zone_id' => $zoneA->id, 'name' => 'SYNC SCOPE TEST ALPHA']);
        $customerB = CustomerFactory::new()->create(['zone_id' => $zoneB->id, 'name' => 'SYNC SCOPE TEST BETA']);

        // Default factory state is verification_status = 'verified', so
        // both land in pull()'s changes.payments.verified bucket.
        $paymentA = PaymentFactory::new()->create(['customer_id' => $customerA->id]);
        $paymentB = PaymentFactory::new()->create(['customer_id' => $customerB->id]);

        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        // tokenForRole leaves tenant_users.branch_id null — per
        // TenantContext::resolve(), an agent's fence comes entirely from
        // their own Agent row's zone, never from tenant_users.branch_id.
        $token = $this->tokenForRole('agent');

        AgentFactory::new()->create(['zone_id' => $zoneA->id, 'user_id' => $user->id]);

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');

        $response->assertOk();

        $customerUuids = collect($response->json('changes.customers.upserted'))->pluck('uuid');
        $this->assertTrue($customerUuids->contains($customerA->uuid), "Agent's own zone customer must be visible.");
        $this->assertFalse($customerUuids->contains($customerB->uuid), 'Other-zone customer must NOT be visible.');

        $paymentUuids = collect($response->json('changes.payments.verified'))->pluck('uuid');
        $this->assertTrue($paymentUuids->contains($paymentA->uuid), "Agent's own zone payment must be visible.");
        $this->assertFalse($paymentUuids->contains($paymentB->uuid), 'Other-zone payment must NOT be visible.');
    }

    public function test_sync_status_returns_sensible_counts_after_a_push(): void
    {
        $customer = $this->customer();
        $token = $this->tokenForRole('agent');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-status-1',
                'changes' => [
                    'payments' => [
                        [
                            'local_uuid' => (string) Str::uuid(),
                            'customer_uuid' => $customer->uuid,
                            'amount' => 2500,
                            'frequency' => 'monthly',
                        ],
                    ],
                ],
            ])->assertOk();

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/sync/status?device_id=device-status-1');

        $response->assertOk()
            ->assertJsonPath('device_id', 'device-status-1')
            ->assertJsonPath('failed_items', 0)
            ->assertJsonPath('pending_push', 0);

        $this->assertIsInt($response->json('pending_pull'));
    }

    public function test_worker_cannot_sync(): void
    {
        $token = $this->tokenForRole('worker');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/sync/pull');

        $response->assertStatus(403);
    }

    /**
     * Same idempotency guarantee as the payments/expenditures tests above,
     * for pushComplaint() — the third create-only sync entity type
     * (complaint-desk.md section 7 / mobile-app-react-native.md section 3).
     */
    public function test_push_complaint_is_idempotent_when_the_same_local_uuid_is_retried(): void
    {
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $payload = [
            'device_id' => 'device-complaint-retry-1',
            'changes' => [
                'complaints' => [
                    [
                        'local_uuid' => $localUuid,
                        'category' => 'operational',
                        'title' => 'Manuscript numbers look wrong',
                        'description' => 'The arrears total for zone 3 does not match what I see on paper.',
                        'urgent' => false,
                    ],
                ],
            ],
        ];

        $first = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', $payload);
        $second = $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', $payload);

        $first->assertOk();
        $second->assertOk();

        $firstEntry = collect($first->json('results.complaints'))->firstWhere('local_uuid', $localUuid);
        $secondEntry = collect($second->json('results.complaints'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame('synced', $firstEntry['status']);
        $this->assertSame('synced', $secondEntry['status']);
        $this->assertNotEmpty($firstEntry['server_uuid']);
        $this->assertSame($firstEntry['server_uuid'], $secondEntry['server_uuid']);

        $this->assertSame(
            1,
            Complaint::query()->where('local_uuid', $localUuid)->count(),
            'A retried push with the same local_uuid must not create a second complaint row.'
        );

        $this->assertDatabaseHas('complaints', [
            'uuid' => $firstEntry['server_uuid'],
            'category' => 'operational',
            'urgent' => false,
        ]);
    }

    /**
     * Same client `created_at` -> `collected_at` mapping as the payment test
     * above, for pushComplaint() (see that method's doc comment in
     * SyncService).
     */
    public function test_push_complaint_maps_client_created_at_to_collected_at(): void
    {
        $localUuid = (string) Str::uuid();
        $collectedAt = now()->subHours(3)->startOfSecond();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-complaint-collected-1',
                'changes' => [
                    'complaints' => [
                        [
                            'local_uuid' => $localUuid,
                            'category' => 'operational',
                            'title' => 'Manuscript numbers look wrong',
                            'description' => 'The arrears total for zone 3 does not match what I see on paper.',
                            'urgent' => false,
                            'created_at' => $collectedAt->toIso8601String(),
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $entry = collect($response->json('results.complaints'))->firstWhere('local_uuid', $localUuid);
        $this->assertSame('synced', $entry['status']);

        $complaint = Complaint::query()->where('uuid', $entry['server_uuid'])->firstOrFail();

        $this->assertNotNull($complaint->collected_at);
        $this->assertTrue($complaint->collected_at->equalTo($collectedAt));
        $this->assertFalse($complaint->created_at->equalTo($collectedAt));
    }

    public function test_push_complaint_for_a_customer_requires_and_resolves_customer_uuid(): void
    {
        $customer = $this->customer();
        $localUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-complaint-customer-1',
                'changes' => [
                    'complaints' => [
                        [
                            'local_uuid' => $localUuid,
                            'category' => 'customer',
                            'title' => 'Customer says signal keeps dropping',
                            'description' => 'Relayed on the customer\'s behalf during a route visit.',
                            'urgent' => true,
                            'customer_uuid' => $customer->uuid,
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $entry = collect($response->json('results.complaints'))->firstWhere('local_uuid', $localUuid);

        $this->assertSame('synced', $entry['status']);
        $this->assertDatabaseHas('complaints', [
            'uuid' => $entry['server_uuid'],
            'category' => 'customer',
            'customer_id' => $customer->id,
            'zone_id' => $customer->zone_id,
            'urgent' => true,
        ]);
    }

    public function test_push_continues_processing_other_complaints_when_one_item_is_invalid(): void
    {
        $customer = $this->customer();
        $badLocalUuid = (string) Str::uuid();
        $goodLocalUuid = (string) Str::uuid();

        $token = $this->tokenForRole('agent');

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/sync/push', [
                'device_id' => 'device-complaint-mixed-1',
                'changes' => [
                    'complaints' => [
                        [
                            // 'customer' category with no customer_uuid —
                            // ComplaintService::resolveCustomer() throws a
                            // ValidationException, caught per-item.
                            'local_uuid' => $badLocalUuid,
                            'category' => 'customer',
                            'title' => 'Missing customer reference',
                            'description' => 'This item is deliberately malformed.',
                        ],
                        [
                            'local_uuid' => $goodLocalUuid,
                            'category' => 'customer',
                            'title' => 'Valid customer complaint',
                            'description' => 'This one has a real customer_uuid.',
                            'customer_uuid' => $customer->uuid,
                        ],
                    ],
                ],
            ]);

        $response->assertOk();

        $results = collect($response->json('results.complaints'));

        $bad = $results->firstWhere('local_uuid', $badLocalUuid);
        $good = $results->firstWhere('local_uuid', $goodLocalUuid);

        $this->assertSame('failed', $bad['status']);
        $this->assertArrayHasKey('error', $bad);

        $this->assertSame('synced', $good['status']);
        $this->assertNotEmpty($good['server_uuid']);
        $this->assertNotEmpty($response->json('errors'));
    }

    /**
     * complaint-desk.md section 7 / in-app-notifications.md section 6: the
     * mobile pull cycle gains a `notifications` block that delegates
     * entirely to NotificationService::feedForUser() — the exact same
     * lazy per-recipient-state computation the web bell/banner use
     * (in-app-notifications.md section 3), never re-derived here. This
     * mirrors NotificationTest's own "broadcast, then assert the count
     * moved" convention, just reading the sync pull response instead of
     * the Inertia `notifications` prop.
     */
    public function test_pull_notifications_block_reflects_lazy_computed_unread_and_emergency_state(): void
    {
        $token = $this->tokenForRole('agent');
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();

        $baseline = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');
        $baseline->assertOk();
        $baselineUnread = $baseline->json('changes.notifications.unread_count');
        $baselineEmergency = count($baseline->json('changes.notifications.emergency'));

        app(NotificationService::class)->broadcastToUser($user, 'test.sync_pull_info', 'info', 'Routine notice', 'Body text');
        $emergency = app(NotificationService::class)->broadcastToUser($user, 'test.sync_pull_emergency', 'emergency', 'Critical', 'Act now');

        $response = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');
        $response->assertOk();

        $this->assertSame($baselineUnread + 2, $response->json('changes.notifications.unread_count'));
        $this->assertSame($baselineEmergency + 1, count($response->json('changes.notifications.emergency')));

        $emergencyUuids = collect($response->json('changes.notifications.emergency'))->pluck('uuid');
        $this->assertTrue($emergencyUuids->contains($emergency->uuid));

        // Acknowledging removes it from the emergency block on the next
        // pull — same "unacknowledged" query NotificationRepository::
        // unacknowledgedEmergenciesForUser() already implements, exercised
        // here through the mobile-facing acknowledge endpoint rather than
        // the web one.
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson("/api/v1/notifications/{$emergency->uuid}/acknowledge")
            ->assertOk()
            ->assertJsonStructure(['uuid', 'acknowledged_at']);

        $afterAck = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');
        $this->assertSame($baselineEmergency, count($afterAck->json('changes.notifications.emergency')));
    }

    /**
     * Split into two fixed-role test methods deliberately, rather than one
     * test that flips tokenForRole() mid-method between multiple calls to
     * the SAME `/sync/pull` route: Illuminate\Routing\Route::getController()
     * memoizes the resolved controller instance on the Route object itself
     * once per test method's booted Router, so a second request to the
     * identical route within one test reuses the SAME SyncController (and
     * therefore the SAME constructor-injected TenantContext/SyncService)
     * captured at the FIRST call — a real Laravel testing artifact, not a
     * production concern (every real request is a separate PHP process).
     * Every existing SyncTest method that already calls the same route
     * twice (the local_uuid idempotency tests) is immune to this because
     * their assertions never depend on a role changing between the two
     * calls; this pair keeps the same discipline — one fixed role per
     * method, matching the codebase's existing safe pattern.
     */
    public function test_pull_notifications_exclude_a_broadcast_for_a_different_role(): void
    {
        $token = $this->tokenForRole('agent');

        $baseline = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');
        $baseline->assertOk();
        $baselineUnread = $baseline->json('changes.notifications.unread_count');

        app(NotificationService::class)->broadcastToRole('manager', 'test.sync_pull_role_scope_excluded', 'info', 'Manager only', 'Body text');

        $after = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');
        $after->assertOk();
        $this->assertSame($baselineUnread, $after->json('changes.notifications.unread_count'));
    }

    public function test_pull_notifications_include_a_broadcast_for_the_matching_role(): void
    {
        $token = $this->tokenForRole('manager');

        $baseline = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');
        $baseline->assertOk();
        $baselineUnread = $baseline->json('changes.notifications.unread_count');

        app(NotificationService::class)->broadcastToRole('manager', 'test.sync_pull_role_scope_included', 'info', 'Manager only', 'Body text');

        $after = $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/v1/sync/pull');
        $after->assertOk();
        $this->assertSame($baselineUnread + 1, $after->json('changes.notifications.unread_count'));
    }
}
