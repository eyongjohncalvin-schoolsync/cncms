<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\Manuscript;
use App\Services\BillNotificationService;
use App\Services\InvoiceService;
use App\Services\ManuscriptService;
use Database\Factories\CustomerFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Displays & messaging for free months (docs/plans/installation-and-
 * promotions.md, phase 2, item 8): the register says FREE (never PREPAID),
 * no bill slip or reminder for a month with nothing due (D11), and the
 * statement never bills a free month.
 */
class FreeMonthDisplaysTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Customer $customer;

    private Manuscript $free;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create()->id, 'bill' => 2500, 'status' => 'active', 'phone' => '677123456']);
        $this->free = Manuscript::query()->create([
            'customer_id' => $this->customer->id, 'period' => '2035-11', 'bill' => 0, 'free_value' => 2500,
            'total_arrears' => 0, 'credit' => 0, 'total_bill' => 0, 'prepaid_months_remaining' => 0,
        ]);
    }

    public function test_the_register_says_free_not_prepaid(): void
    {
        $this->assertSame('FREE', $this->free->expiryLabel());

        $prepaid = new Manuscript(['period' => '2035-11', 'bill' => 2500, 'free_value' => 0, 'prepaid_months_remaining' => 2]);
        $this->assertSame('Jan 36', $prepaid->expiryLabel());
    }

    public function test_no_reminder_and_no_slip_for_a_free_month(): void
    {
        $this->assertNull(app(BillNotificationService::class)->composeMessage($this->customer, $this->free));
        $this->assertNull(app(BillNotificationService::class)->waLink($this->customer, $this->free));

        $recipients = app(ManuscriptService::class)->billRecipients(['period' => '2035-11']);
        $this->assertFalse($recipients['customers']->contains('id', $this->customer->id));
    }

    public function test_the_statement_never_bills_a_free_month(): void
    {
        $statement = app(InvoiceService::class)->statement($this->customer, '2035-11', '2035-11');

        $this->assertTrue($statement['bills'][0]['is_free']);
        $this->assertSame('0.00', $statement['bills'][0]['monthly_charge']);
        $this->assertSame('0.00', $statement['total_billed']);
        $this->assertSame(InvoiceService::STATUS_NOTHING_DUE, $statement['bills'][0]['status']);
    }
}
