<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\PaymentReceiptService;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Monthly invoices (each month's bill, with a paid status) and the
 * statement of account (App\Services\InvoiceService,
 * CustomerStatementController).
 */
class CustomerStatementTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('local');
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);
        $this->actingAs($user);

        return $user;
    }

    /**
     * Three months of history: Aug fully paid (paid in Sep), Sep part paid
     * (paid in Oct), Oct (latest) unpaid, plus an earlier Jul with nothing due.
     */
    private function customerWithHistory(): Customer
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active', 'others' => 0]);
        $bill = fn (string $period, int $due, int $arrears) => ManuscriptFactory::new()->forPeriod($period)->create([
            'customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => $due, 'total_arrears' => $arrears, 'credit' => 0,
        ]);
        $bill('2031-07', 0, 0);
        $bill('2031-08', 2500, 0);
        $bill('2031-09', 2500, 0);
        $bill('2031-10', 4000, 1500);

        $pay = fn (int $amount, ?string $processed, string $collected) => PaymentFactory::new()->create([
            'customer_id' => $customer->id, 'amount' => $amount, 'verification_status' => 'verified',
            'processed_period' => $processed, 'collected_at' => $collected, 'frequency' => 'monthly',
        ]);
        $pay(2500, '2031-09', '2031-08-20 10:00:00');
        $pay(1000, '2031-10', '2031-09-21 10:00:00');

        return $customer;
    }

    public function test_each_bill_gets_a_number_and_a_paid_status(): void
    {
        $customer = $this->customerWithHistory();

        $bills = collect(app(InvoiceService::class)->billsFor($customer))->keyBy('period');

        $this->assertSame(['2031-10', '2031-09', '2031-08', '2031-07'], $bills->keys()->all());
        $this->assertSame(InvoiceService::STATUS_NOTHING_DUE, $bills['2031-07']['status']);
        $this->assertSame(InvoiceService::STATUS_PAID, $bills['2031-08']['status']);
        $this->assertSame(InvoiceService::STATUS_PARTLY_PAID, $bills['2031-09']['status']);
        $this->assertSame('1000.00', $bills['2031-09']['paid']);
        $this->assertSame(InvoiceService::STATUS_UNPAID, $bills['2031-10']['status']);
        $this->assertSame(Manuscript::billNumberFor($customer->id, '2031-08'), $bills['2031-08']['number']);
    }

    public function test_money_received_since_the_latest_bill_counts_toward_it(): void
    {
        $customer = $this->customerWithHistory();
        PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 4000, 'verification_status' => 'verified', 'processed_period' => null]);

        $latest = app(InvoiceService::class)->billsFor($customer)[0];

        $this->assertSame('2031-10', $latest['period']);
        $this->assertSame(InvoiceService::STATUS_PAID, $latest['status']);
    }

    public function test_the_statement_covers_the_range_with_opening_and_closing_balances(): void
    {
        $customer = $this->customerWithHistory();

        $statement = app(InvoiceService::class)->statement($customer, '2031-08', '2031-10');

        $this->assertSame('0.00', $statement['opening_balance']);
        $this->assertSame('1500.00', $statement['closing_balance']);
        $this->assertSame(['2031-08', '2031-09', '2031-10'], array_column($statement['bills'], 'period'));
        $this->assertSame(['2500.00', '1000.00'], array_column($statement['payments'], 'amount'));
        $this->assertSame('3500.00', $statement['total_paid']);
        $this->assertSame('7500.00', $statement['total_billed']);
    }

    public function test_the_statement_downloads_as_a_pdf(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();

        $response = $this->get("/customers/{$customer->uuid}/statement?from=2031-07&to=2031-10");

        $response->assertOk();
        $this->assertStringContainsString('pdf', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('%PDF-', $response->getContent());
    }

    public function test_the_customer_page_lists_their_bills(): void
    {
        $this->actingAsRole('manager');
        $customer = $this->customerWithHistory();

        $this->get("/customers/{$customer->uuid}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('bills', 4)
            ->where('bills.0.period', '2031-10')
            ->where('bills.0.status', 'unpaid'));
    }

    public function test_a_receipt_names_the_bill_it_pays_toward(): void
    {
        $customer = $this->customerWithHistory();
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id, 'amount' => 4000, 'verification_status' => 'verified']);

        $receipt = app(PaymentReceiptService::class)->issueFor($payment, autoSend: false);

        $this->assertSame(Manuscript::billNumberFor($customer->id, '2031-10'), $receipt->snapshot['invoice']['number']);
        $this->assertSame('2031-10', $receipt->snapshot['invoice']['period']);
    }
}
