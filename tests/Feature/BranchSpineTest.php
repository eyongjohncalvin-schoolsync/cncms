<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DataTransferObjects\ComplaintData;
use App\Models\Agent;
use App\Models\AuditLog;
use App\Models\BillBatchFile;
use App\Models\Branch;
use App\Models\BranchPrice;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Company;
use App\Models\Concerns\BelongsToBranch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\TenantUser;
use App\Models\User;
use App\Models\Zone;
use App\Services\CashBookService;
use App\Services\ComplaintService;
use Database\Factories\AgentFactory;
use Database\Factories\BranchFactory;
use Database\Factories\CustomerFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\ZoneFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 P1 — the data spine (docs/plans/branches-v2.md §2 rule 1, §4.2,
 * §5): every branch-owned row carries its own branch_id, stamped from its
 * parent when created, never changed afterwards; a customer's / agent's
 * branch is their zone's (enforced by the database), while their history
 * keeps the branch where it happened.
 */
class BranchSpineTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $headquarters;

    private Branch $buea;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        $this->headquarters = Branch::query()->where('is_headquarters', true)->sole();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
    }

    public function test_every_branch_owned_model_stamps_its_branch(): void
    {
        $exempt = [
            // Carry branch_id with another meaning, or nullable by design.
            Zone::class, Company::class, TenantUser::class,
            AuditLog::class, BillBatchFile::class,
            // A branch's own price list (settings), not data the branch owns.
            BranchPrice::class,
        ];

        $checked = 0;

        foreach (glob(app_path('Models/*.php')) as $file) {
            $class = 'App\\Models\\'.basename($file, '.php');

            if (! is_subclass_of($class, Model::class) || (new \ReflectionClass($class))->isAbstract() || in_array($class, $exempt, true)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (! Schema::connection('tenant')->hasTable($table) || ! Schema::connection('tenant')->hasColumn($table, 'branch_id')) {
                continue;
            }

            $this->assertContains(BelongsToBranch::class, class_uses_recursive($class), "{$class} has branch_id but does not use BelongsToBranch.");
            $checked++;
        }

        $this->assertGreaterThanOrEqual(20, $checked);
    }

    public function test_a_customer_takes_its_zone_branch_and_its_history_takes_the_customer_branch(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->buea->id])->id]);
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->assertSame($this->buea->id, $customer->branch_id);
        $this->assertSame($this->buea->id, $payment->branch_id);
    }

    public function test_the_branch_of_a_history_row_never_changes(): void
    {
        $payment = PaymentFactory::new()->create();
        $otherBranchId = $payment->branch_id === $this->buea->id ? $this->headquarters->id : $this->buea->id;

        $this->expectException(LogicException::class);

        $payment->forceFill(['branch_id' => $otherBranchId])->save();
    }

    public function test_a_customer_moved_to_another_branch_keeps_its_old_payments_where_they_happened(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->headquarters->id])->id]);
        $oldPayment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $customer->update(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->buea->id])->id]);
        $newPayment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $this->assertSame($this->buea->id, $customer->refresh()->branch_id);
        $this->assertSame($this->headquarters->id, $oldPayment->refresh()->branch_id);
        $this->assertSame($this->buea->id, $newPayment->branch_id);
    }

    public function test_moving_a_zone_to_another_branch_moves_its_customers_and_agents_but_not_their_history(): void
    {
        $zone = ZoneFactory::new()->create(['branch_id' => $this->headquarters->id]);
        $customer = CustomerFactory::new()->create(['zone_id' => $zone->id]);
        $agent = AgentFactory::new()->create(['zone_id' => $zone->id, 'user_id' => null]);
        $payment = PaymentFactory::new()->create(['customer_id' => $customer->id]);

        $zone->update(['branch_id' => $this->buea->id]);

        $this->assertSame($this->buea->id, $customer->refresh()->branch_id);
        $this->assertSame($this->buea->id, $agent->refresh()->branch_id);
        $this->assertSame($this->headquarters->id, $payment->refresh()->branch_id);

        // The next payment belongs to the zone's new branch — no stale lookup.
        $this->assertSame($this->buea->id, PaymentFactory::new()->create(['customer_id' => $customer->id])->branch_id);
    }

    public function test_the_database_refuses_a_customer_whose_branch_is_not_its_zones(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->headquarters->id])->id]);

        $this->expectExceptionMessage('customers_zone_branch_foreign');

        DB::connection('tenant')->table('customers')->where('id', $customer->id)->update(['branch_id' => $this->buea->id]);
    }

    public function test_a_payment_verification_and_receipt_take_the_payment_branch(): void
    {
        $this->actingAsRole('manager');
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->buea->id])->id]);
        $payment = PaymentFactory::new()->pending()->create(['customer_id' => $customer->id, 'amount' => 2500]);

        $this->post("/payments/{$payment->uuid}/verify", ['action' => 'approve'])->assertSessionHasNoErrors();

        $payment->refresh()->load(['verification', 'receipt']);
        $this->assertSame($this->buea->id, $payment->verification->branch_id);
        $this->assertSame($this->buea->id, $payment->receipt->branch_id);
    }

    public function test_an_operational_complaint_takes_the_submitters_branch(): void
    {
        $user = $this->actingAsRole('manager', $this->buea->id);

        $complaint = app(ComplaintService::class)->create(ComplaintData::fromArray([
            'category' => 'operational',
            'title' => 'Amplifier down',
            'description' => 'The Buea amplifier is off.',
        ]), $user->id);

        $this->assertSame($this->buea->id, $complaint->branch_id);
    }

    public function test_a_cash_movement_takes_its_account_branch(): void
    {
        $cashier = $this->actingAsRole('cashier', $this->buea->id);
        $cashBook = app(CashBookService::class);
        CashAccount::query()->where('holder_user_id', $cashier->id)->update(['holder_user_id' => null]);

        $cashBook->openingBalance($cashBook->tillFor($cashier), '10000', $cashier);

        $this->assertSame($this->buea->id, CashMovement::query()->latest('id')->firstOrFail()->branch_id);
    }

    public function test_an_audit_row_carries_the_branch_of_what_changed(): void
    {
        $customer = CustomerFactory::new()->create(['zone_id' => ZoneFactory::new()->create(['branch_id' => $this->buea->id])->id]);

        $audit = AuditLog::query()->where('table_name', 'customers')->where('record_id', $customer->id)->where('action', 'create')->sole();

        $this->assertSame($this->buea->id, $audit->branch_id);
    }

    private function actingAsRole(string $role, ?int $branchId = null): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        $this->actingAs($user);

        return $user;
    }
}
