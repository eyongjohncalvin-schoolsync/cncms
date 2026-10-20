<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\FieldDevice;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\ReceiptBlock;
use App\Models\ReceiptLeaf;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ReceiptStockService;
use App\Support\AmountInWords;
use App\Support\ReceiptSecurityCode;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Cash controls wave 2 (docs/plans/cash-controls.md): receipt numbers only
 * the system creates, signed field receipts, printed books, the public
 * check page and the receipt-stock screen.
 */
class ReceiptStockTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
    }

    private function owner(): User
    {
        return User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
    }

    private function actingAsRole(string $role): User
    {
        $user = $this->owner();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    private function customer(): Customer
    {
        return CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active']);
    }

    /**
     * Registers a phone for the seeded owner (acting as $role) and draws its
     * first block of numbers — what the mobile app does on first sync.
     *
     * @return array{token: string, device: string, secret: string, numbers: list<string>}
     */
    private function registeredPhone(string $role = 'agent'): array
    {
        $token = $this->tokenForRole($role);

        $registered = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/field-devices', ['name' => 'Tecno Spark (test)'])
            ->assertCreated();

        $device = $registered->json('data.device_uuid');

        $numbers = $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Field-Device' => $device])
            ->getJson('/api/v1/receipt-stock')
            ->assertOk()
            ->json('data.numbers');

        return ['token' => $token, 'device' => $device, 'secret' => $registered->json('data.secret'), 'numbers' => $numbers];
    }

    /** What the phone prints: the code for this number, customer, amount and time. */
    private function phoneCode(string $secret, string $number, Customer $customer, string|int $amount, Carbon $at): string
    {
        return ReceiptSecurityCode::compute(
            ReceiptSecurityCode::deviceKey($secret),
            ReceiptSecurityCode::canonical($number, $customer->uuid, $amount, $at->getTimestamp()),
        );
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function pushPayment(array $phone, array $item): array
    {
        $localUuid = (string) Str::uuid();

        $response = $this->withHeader('Authorization', "Bearer {$phone['token']}")->postJson('/api/v1/sync/push', [
            'device_id' => 'phone-'.$phone['device'],
            'last_sync_at' => null,
            'changes' => ['payments' => [['local_uuid' => $localUuid, 'frequency' => 'monthly', ...$item]]],
        ])->assertOk();

        return collect($response->json('results.payments'))->firstWhere('local_uuid', $localUuid);
    }

    // ---- amounts in words / codes ------------------------------------

    public function test_amounts_are_written_in_french(): void
    {
        $this->assertSame('Dix mille francs CFA', AmountInWords::french(10000));
        $this->assertSame('Deux mille cinq cents francs CFA', AmountInWords::french(2500));
        $this->assertSame('Soixante et onze francs CFA', AmountInWords::french(71));
        $this->assertSame('Quatre-vingts francs CFA', AmountInWords::french(80));
        $this->assertSame('Quatre-vingt mille francs CFA', AmountInWords::french(80000));
        $this->assertSame('Deux cent un francs CFA', AmountInWords::french(201));
        $this->assertSame('Vingt et un mille francs CFA', AmountInWords::french(21000));
        $this->assertSame('Deux millions cinq cent mille francs CFA', AmountInWords::french(2500000));
        $this->assertSame('Ten Thousand FCFA Only', AmountInWords::convert(10000));
    }

    public function test_the_security_code_changes_with_the_amount_and_tolerates_typing(): void
    {
        $key = random_bytes(32);
        $base = ReceiptSecurityCode::compute($key, ReceiptSecurityCode::canonical('RCP-2026-000001', 'abc', 10000, 1760000000));
        $altered = ReceiptSecurityCode::compute($key, ReceiptSecurityCode::canonical('RCP-2026-000001', 'abc', 100000, 1760000000));

        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{8}$/', $base);
        $this->assertNotSame($base, $altered);
        $this->assertTrue(ReceiptSecurityCode::matches($base, strtolower(ReceiptSecurityCode::format($base))));
        $this->assertFalse(ReceiptSecurityCode::matches($base, $altered));
    }

    // ---- office receipts ---------------------------------------------

    public function test_an_office_receipt_is_signed_and_checks_as_genuine(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->customer();

        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 10000, 'frequency' => 'monthly'])->assertSessionHasNoErrors();
        $receipt = Payment::query()->where('customer_id', $customer->id)->sole()->receipt;

        $this->assertSame(PaymentReceipt::SOURCE_OFFICE, $receipt->source);
        $this->assertSame(8, strlen((string) $receipt->security_code));
        $this->assertSame('Dix mille francs CFA', $receipt->snapshot['amount_in_words']['fr']);

        $this->get("/r/swecom/{$receipt->receipt_number}?c=".$receipt->formattedSecurityCode())
            ->assertOk()->assertSee('Genuine receipt')->assertSee('10 000 FCFA');

        $this->get("/r/swecom/{$receipt->receipt_number}")->assertOk()->assertSee('Receipt recorded');
    }

    public function test_a_wrong_code_on_the_check_page_warns_the_office(): void
    {
        $this->actingAsRole('admin');
        $customer = $this->customer();
        $this->post('/payments', ['customer_uuid' => $customer->uuid, 'amount' => 10000, 'frequency' => 'monthly']);
        $receipt = Payment::query()->where('customer_id', $customer->id)->sole()->receipt;

        $this->get("/r/swecom/{$receipt->receipt_number}?c=AAAA-AAAA")->assertOk()->assertSee('Details do not match');

        $this->assertTrue(Notification::query()->where('type', 'receipts.code_mismatch')->where('source_uuid', $receipt->uuid)->exists());
    }

    public function test_an_unknown_number_is_reported_as_not_ours(): void
    {
        $this->get('/r/swecom/RCP-1999-999999')->assertOk()->assertSee('Not one of our receipts');
    }

    // ---- digital field receipts --------------------------------------

    public function test_a_registered_phone_gets_numbers_and_is_not_topped_up_while_it_has_enough(): void
    {
        $phone = $this->registeredPhone();

        $this->assertCount(ReceiptStockService::DIGITAL_BLOCK_SIZE, $phone['numbers']);
        $this->assertSame(64, strlen($phone['secret']));

        $again = $this->withHeaders(['Authorization' => "Bearer {$phone['token']}", 'X-Field-Device' => $phone['device']])
            ->getJson('/api/v1/receipt-stock')->assertOk()->json('data.numbers');

        $this->assertSame($phone['numbers'], $again);
        $this->assertSame(1, ReceiptBlock::query()->whereHas('fieldDevice', fn ($q) => $q->where('uuid', $phone['device']))->count());
    }

    public function test_an_unregistered_phone_is_told_to_register(): void
    {
        $token = $this->tokenForRole('agent');

        $this->withHeaders(['Authorization' => "Bearer {$token}", 'X-Field-Device' => (string) Str::uuid()])
            ->getJson('/api/v1/receipt-stock')
            ->assertStatus(409)
            ->assertJsonPath('code', 'device_not_registered');
    }

    public function test_a_signed_field_receipt_is_recorded_at_collection_even_while_the_payment_is_pending(): void
    {
        $phone = $this->registeredPhone('agent');
        $customer = $this->customer();
        $number = $phone['numbers'][0];
        $at = Carbon::now()->subMinutes(30)->startOfSecond();

        $result = $this->pushPayment($phone, [
            'customer_uuid' => $customer->uuid,
            'amount' => 10000,
            'receipt_number' => $number,
            'security_code' => ReceiptSecurityCode::format($this->phoneCode($phone['secret'], $number, $customer, 10000, $at)),
            'receipt_issued_at' => $at->toIso8601String(),
        ]);

        $this->assertSame('synced', $result['status']);

        $payment = Payment::query()->where('uuid', $result['server_uuid'])->sole();
        $this->assertSame('pending', $payment->verification_status);
        $this->assertSame($number, $payment->receipt->receipt_number);
        $this->assertSame(PaymentReceipt::SOURCE_DIGITAL, $payment->receipt->source);
        $this->assertSame(ReceiptLeaf::STATUS_USED, ReceiptLeaf::query()->where('number', $number)->value('status'));

        $code = $payment->receipt->formattedSecurityCode();
        $this->get("/r/swecom/{$number}?c={$code}")->assertOk()->assertSee('Genuine receipt');
    }

    public function test_a_receipt_whose_amount_was_changed_is_refused_and_nothing_is_stored(): void
    {
        $phone = $this->registeredPhone();
        $customer = $this->customer();
        $number = $phone['numbers'][0];
        $at = Carbon::now()->subMinutes(5)->startOfSecond();

        $result = $this->pushPayment($phone, [
            'customer_uuid' => $customer->uuid,
            'amount' => 100000, // printed for 10 000
            'receipt_number' => $number,
            'security_code' => $this->phoneCode($phone['secret'], $number, $customer, 10000, $at),
            'receipt_issued_at' => $at->toIso8601String(),
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('does not match', $result['error']);
        $this->assertFalse(Payment::query()->where('customer_id', $customer->id)->exists());
        $this->assertSame(ReceiptLeaf::STATUS_UNUSED, ReceiptLeaf::query()->where('number', $number)->value('status'));
    }

    public function test_a_number_cannot_be_used_twice(): void
    {
        $phone = $this->registeredPhone();
        $number = $phone['numbers'][0];
        $at = Carbon::now()->startOfSecond();

        foreach ([$this->customer(), $this->customer()] as $index => $customer) {
            $result = $this->pushPayment($phone, [
                'customer_uuid' => $customer->uuid,
                'amount' => 2500,
                'receipt_number' => $number,
                'security_code' => $this->phoneCode($phone['secret'], $number, $customer, 2500, $at),
                'receipt_issued_at' => $at->toIso8601String(),
            ]);

            $this->assertSame($index === 0 ? 'synced' : 'failed', $result['status']);
        }
    }

    public function test_another_collectors_number_is_refused(): void
    {
        $manager = User::query()->where('email', 'terence@shalomtech.dev')->firstOrFail();
        $book = app(ReceiptStockService::class)->allocate(ReceiptBlock::KIND_BOOK, $manager, 25);

        $this->actingAsRole('admin');
        $customer = $this->customer();

        $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'frequency' => 'monthly',
            'receipt_number' => $book->firstNumber(),
        ])->assertSessionHasErrors('receipt_number');

        $this->assertFalse(Payment::query()->where('customer_id', $customer->id)->exists());
    }

    public function test_receipts_from_a_revoked_phone_are_refused(): void
    {
        $phone = $this->registeredPhone();
        FieldDevice::query()->where('uuid', $phone['device'])->sole()->update(['revoked_at' => now()]);

        $customer = $this->customer();
        $number = $phone['numbers'][0];
        $at = Carbon::now()->startOfSecond();

        $result = $this->pushPayment($phone, [
            'customer_uuid' => $customer->uuid,
            'amount' => 2500,
            'receipt_number' => $number,
            'security_code' => $this->phoneCode($phone['secret'], $number, $customer, 2500, $at),
            'receipt_issued_at' => $at->toIso8601String(),
        ]);

        $this->assertSame('failed', $result['status']);
        $this->assertStringContainsString('revoked', $result['error']);
    }

    public function test_the_holder_can_cancel_a_burned_number_from_the_phone(): void
    {
        $phone = $this->registeredPhone();
        $number = $phone['numbers'][0];

        $this->withHeader('Authorization', "Bearer {$phone['token']}")
            ->postJson("/api/v1/receipt-numbers/{$number}/cancel", ['reason' => 'Printer jammed'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->get("/r/swecom/{$number}")->assertOk()->assertSee('Cancelled number');
    }

    // ---- printed books -----------------------------------------------

    public function test_a_manager_issues_a_book_and_its_holder_records_a_payment_on_a_leaf(): void
    {
        $holder = $this->actingAsRole('manager');

        $this->post('/receipt-stock/books', ['holder_user_id' => $holder->id, 'size' => 25])->assertSessionHasNoErrors();
        $book = ReceiptBlock::query()->where('holder_user_id', $holder->id)->where('kind', 'book')->latest('id')->firstOrFail();
        $this->assertSame(25, $book->leaves()->count());

        $this->get('/payments/create')->assertInertia(fn (Assert $page) => $page->where('has_receipt_book', true));

        $customer = $this->customer();
        $this->post('/payments', [
            'customer_uuid' => $customer->uuid,
            'amount' => 5000,
            'frequency' => 'monthly',
            'receipt_number' => strtolower($book->firstNumber()),
        ])->assertSessionHasNoErrors();

        $receipt = Payment::query()->where('customer_id', $customer->id)->sole()->receipt;
        $this->assertSame($book->firstNumber(), $receipt->receipt_number);
        $this->assertSame(PaymentReceipt::SOURCE_BOOK, $receipt->source);

        // A handwritten leaf has no printed code: the page shows what was
        // recorded so the customer can compare.
        $this->get("/r/swecom/{$book->firstNumber()}")->assertOk()->assertSee('Receipt recorded')->assertSee('5 000 FCFA');
    }

    public function test_the_book_prints_as_a_pdf(): void
    {
        $holder = $this->actingAsRole('manager');
        $book = app(ReceiptStockService::class)->allocate(ReceiptBlock::KIND_BOOK, $holder, 25, null, $holder);

        $response = $this->get("/receipt-stock/{$book->uuid}/pdf")->assertOk();

        $this->assertStringStartsWith('%PDF', (string) $response->getContent());
    }

    public function test_scanning_an_unused_leaf_flags_it_on_the_stock_page(): void
    {
        $holder = $this->actingAsRole('manager');
        $book = app(ReceiptStockService::class)->allocate(ReceiptBlock::KIND_BOOK, $holder, 25);

        $this->get("/r/swecom/{$book->firstNumber()}")->assertOk()->assertSee('Not recorded yet')->assertSee($holder->name);

        $this->assertNotNull(ReceiptLeaf::query()->where('number', $book->firstNumber())->value('scanned_unused_at'));

        $this->get('/receipt-stock')->assertInertia(fn (Assert $page) => $page
            ->component('ReceiptStock/Index')
            ->where('blocks', fn ($blocks) => collect($blocks)->firstWhere('uuid', $book->uuid)['counts']['scanned_unused'] === 1));
    }

    public function test_closing_a_returned_book_cancels_its_blank_leaves(): void
    {
        $holder = $this->actingAsRole('manager');
        $book = app(ReceiptStockService::class)->allocate(ReceiptBlock::KIND_BOOK, $holder, 25);

        $this->post("/receipt-stock/{$book->uuid}/close", ['cancel_remaining' => true, 'reason' => 'Book returned'])->assertSessionHasNoErrors();

        $this->assertSame('closed', $book->refresh()->status);
        $this->assertSame(25, $book->leaves()->where('status', 'cancelled')->count());
    }

    public function test_the_stock_page_is_for_stock_managers_only(): void
    {
        $this->actingAsRole('agent');
        $this->get('/receipt-stock')->assertForbidden();
        $this->post('/receipt-stock/books', ['holder_user_id' => $this->owner()->id, 'size' => 25])->assertForbidden();

        $this->actingAsRole('manager');
        $this->get('/receipt-stock?search=RCP-1999-000001')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('lookup.found', false));
    }

    public function test_revoking_a_phone_closes_its_blocks(): void
    {
        $phone = $this->registeredPhone();
        $this->actingAsRole('manager');

        $this->post("/receipt-stock/devices/{$phone['device']}/revoke")->assertSessionHasNoErrors();

        $device = FieldDevice::query()->where('uuid', $phone['device'])->sole();
        $this->assertNotNull($device->revoked_at);
        $this->assertSame(0, $device->blocks()->where('status', 'open')->count());
    }
}
