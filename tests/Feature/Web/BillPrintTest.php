<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Customer;
use App\Models\TenantUser;
use App\Models\User;
use Database\Factories\CompanyFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\ManuscriptFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * The session-auth web bill-slip route
 * (App\Http\Controllers\CustomerController::printBill(),
 * GET /customers/{customer}/bill/print).
 *
 * Owner decision (2026-08): a bill slip only ever prints for an ACTIVE
 * customer. A disconnected / suspended / passive customer is frozen —
 * their manuscript carries a 0 total_bill — so printBill() refuses with a
 * friendly flash 'error' and a redirect back to the customer page rather
 * than streaming a slip. The guard lives in ManuscriptService::billData()
 * and is shared with the API twin (tests/Feature/Api/BillPrintTest.php).
 */
class BillPrintTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();

        CompanyFactory::new()->create();
    }

    private function actingAsRole(string $role): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role]);

        $this->actingAs($user);

        return $user;
    }

    private function customerWithManuscript(string $status = 'active'): Customer
    {
        $zone = ZoneFactory::new()->create();
        $customer = CustomerFactory::new()->create([
            'zone_id' => $zone->id,
            'bill' => 2500,
            'status' => $status,
        ]);

        ManuscriptFactory::new()
            ->forPeriod(Carbon::now()->format('Y-m'))
            ->create(['customer_id' => $customer->id, 'bill' => 2500, 'total_bill' => 2500]);

        return $customer;
    }

    public function test_printing_a_bill_for_an_active_customer_streams_a_pdf(): void
    {
        $this->actingAsRole('manager');

        $customer = $this->customerWithManuscript();

        $response = $this->get("/customers/{$customer->uuid}/bill/print");

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonActiveStatusProvider(): array
    {
        return [
            'disconnected' => ['disconnected'],
            'suspended' => ['suspended'],
            'passive' => ['passive'],
        ];
    }

    #[DataProvider('nonActiveStatusProvider')]
    public function test_printing_a_bill_is_refused_for_a_non_active_customer(string $status): void
    {
        $this->actingAsRole('manager');

        $customer = $this->customerWithManuscript($status);

        $response = $this->get("/customers/{$customer->uuid}/bill/print");

        $response->assertRedirect(route('customers.show', $customer->uuid));
        $response->assertSessionHas('error');
        $this->assertStringContainsString('only printed for active customers', session('error'));
        $this->assertStringContainsString($status, session('error'));
    }
}
