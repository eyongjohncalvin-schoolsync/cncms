<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\Models\Branch;
use App\Models\BranchTransfer;
use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\CashMovement;
use App\Models\Scopes\BranchScope;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\CashAlertService;
use App\Services\CashBookService;
use App\Support\BusinessTimezone;
use App\Support\TenantContext;
use Database\Factories\BranchFactory;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Branches v2 P5 (docs/plans/branches-v2.md §4.7, D3, D6): no joint account —
 * a branch sends money with a transfer (out at once, in transit until the
 * receiving branch takes it into one of its accounts or refuses it); each
 * branch keeps its own wallets and budgets; headquarters is told when money
 * sits in transit or a branch has not sent on schedule.
 */
class BranchMoneyTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private Branch $headquarters;

    private Branch $buea;

    private User $kelvin;

    private CashBookService $cashBook;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Mail::fake();
        $this->headquarters = Branch::query()->where('is_headquarters', true)->sole();
        $this->buea = BranchFactory::new()->create(['name' => 'Buea']);
        $this->kelvin = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        $this->cashBook = app(CashBookService::class);
    }

    public function test_a_branch_sends_money_that_is_in_transit_until_headquarters_receives_it(): void
    {
        $bueaSafe = $this->account($this->buea, 'Buea safe', '100000');
        $hqSafe = $this->account($this->headquarters, 'HQ safe', '0');

        $this->as('admin', $this->buea->id);
        $this->post('/cash/branch-transfers', ['from_account' => $bueaSafe->uuid, 'to_branch' => $this->headquarters->uuid, 'amount' => '60000', 'note' => 'Week 43'])
            ->assertSessionHasNoErrors();

        $transfer = BranchTransfer::query()->latest('id')->firstOrFail();
        $this->assertSame(BranchTransfer::STATUS_SENT, $transfer->status);
        $this->assertSame('40000.00', $this->balance($bueaSafe));
        $this->assertSame('0.00', $this->balance($hqSafe));

        // Buea cannot receive what it sent to headquarters.
        $this->post("/cash/branch-transfers/{$transfer->uuid}/receive", ['account' => $bueaSafe->uuid])->assertForbidden();

        $this->as('admin', null, 'patience@shalomtech.dev');
        $this->post("/cash/branch-transfers/{$transfer->uuid}/receive", ['account' => $hqSafe->uuid])
            ->assertRedirect('/cash/branch-transfers')->assertSessionHasNoErrors();

        $this->assertSame(BranchTransfer::STATUS_RECEIVED, $transfer->fresh()->status);
        $this->assertSame('60000.00', $this->balance($hqSafe));
        $this->assertSame($this->headquarters->id, CashMovement::query()->withoutGlobalScope(BranchScope::class)->where('cash_account_id', $hqSafe->id)->latest('id')->value('branch_id'));
    }

    public function test_money_goes_into_the_receiving_branchs_account_only(): void
    {
        $bueaSafe = $this->account($this->buea, 'Buea safe', '100000');
        $this->as('admin', $this->buea->id);
        $this->post('/cash/branch-transfers', ['from_account' => $bueaSafe->uuid, 'to_branch' => $this->headquarters->uuid, 'amount' => '10000']);
        $transfer = BranchTransfer::query()->latest('id')->firstOrFail();

        $this->as('admin', null, 'patience@shalomtech.dev');
        $this->post("/cash/branch-transfers/{$transfer->uuid}/receive", ['account' => $bueaSafe->uuid])->assertSessionHasErrors('account');
        $this->assertTrue($transfer->fresh()->isInTransit());
    }

    public function test_a_refused_transfer_returns_to_the_sender(): void
    {
        $bueaSafe = $this->account($this->buea, 'Buea safe', '50000');
        $this->as('admin', $this->buea->id);
        $this->post('/cash/branch-transfers', ['from_account' => $bueaSafe->uuid, 'to_branch' => $this->headquarters->uuid, 'amount' => '20000']);
        $transfer = BranchTransfer::query()->latest('id')->firstOrFail();

        $this->as('admin', $this->headquarters->id, 'patience@shalomtech.dev');
        $this->post("/cash/branch-transfers/{$transfer->uuid}/reject", ['reason' => 'Wrong amount'])->assertSessionHasNoErrors();

        $this->assertSame(BranchTransfer::STATUS_REJECTED, $transfer->fresh()->status);
        $this->assertSame('50000.00', $this->balance($bueaSafe));
    }

    public function test_other_branches_transfers_are_invisible_to_a_fenced_branch(): void
    {
        $limbe = BranchFactory::new()->create(['name' => 'Limbe']);
        $limbeSafe = $this->account($limbe, 'Limbe safe', '30000');
        $this->as('admin', $limbe->id);
        $this->post('/cash/branch-transfers', ['from_account' => $limbeSafe->uuid, 'to_branch' => $this->headquarters->uuid, 'amount' => '5000']);
        $transfer = BranchTransfer::query()->latest('id')->firstOrFail();

        $this->as('admin', $this->buea->id);
        $this->get('/cash/branch-transfers')->assertOk()->assertInertia(fn (Assert $page) => $page->where('transfers', []));
        $this->post("/cash/branch-transfers/{$transfer->uuid}/reject", ['reason' => 'x'])->assertNotFound();
    }

    public function test_money_never_crosses_branches_by_a_direct_transfer_or_to_ones_own_branch(): void
    {
        $bueaSafe = $this->account($this->buea, 'Buea safe', '10000');
        $hqSafe = $this->account($this->headquarters, 'HQ safe', '0');

        $this->as('admin');

        try {
            $this->cashBook->transfer($bueaSafe, $hqSafe, '1000', $this->kelvin, null, null);
            $this->fail('A direct transfer across branches must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('to_account', $e->errors());
        }

        $this->post('/cash/branch-transfers', ['from_account' => $bueaSafe->uuid, 'to_branch' => $this->buea->uuid, 'amount' => '1000'])
            ->assertSessionHasErrors('to_branch');
    }

    public function test_each_branch_keeps_its_own_wallet(): void
    {
        $hqWallet = $this->cashBook->walletFor('mtn_momo');
        $bueaWallet = $this->cashBook->walletFor('mtn_momo', $this->buea->id);

        $this->assertNotSame($hqWallet->id, $bueaWallet->id);
        $this->assertSame($this->buea->id, $bueaWallet->branch_id);
        $this->assertSame($bueaWallet->id, $this->cashBook->walletFor('mtn_momo', $this->buea->id)->id);
    }

    public function test_a_branch_manager_sets_their_branchs_budgets_never_the_companys(): void
    {
        $category = ExpenseCategoryFactory::new()->create(['name' => 'Fuel']);
        $period = BusinessTimezone::currentPeriod();
        Budget::query()->create(['category_id' => $category->id, 'period' => $period, 'amount' => 90000]);

        $this->as('admin', $this->buea->id);
        $this->put('/resources/budgets', ['month' => $period, 'amounts' => [$category->uuid => '15000']])->assertSessionHasNoErrors();

        $this->assertSame('90000.00', (string) Budget::query()->whereNull('branch_id')->where('category_id', $category->id)->value('amount'));
        $this->assertSame('15000.00', (string) Budget::query()->where('branch_id', $this->buea->id)->where('category_id', $category->id)->value('amount'));

        $this->get('/resources/budgets')->assertInertia(fn (Assert $page) => $page
            ->where('grid.branch', 'BUEA')
            ->where('grid.rows', fn ($rows) => collect($rows)->firstWhere('uuid', $category->uuid)['budget'] === '15000.00'));
    }

    public function test_headquarters_is_told_about_money_in_transit_and_branches_not_sending(): void
    {
        $this->buea->update(['sends_to_hq' => 'weekly']);
        $bueaSafe = $this->account($this->buea, 'Buea safe', '10000');
        $this->as('admin', $this->buea->id);
        $this->post('/cash/branch-transfers', ['from_account' => $bueaSafe->uuid, 'to_branch' => $this->headquarters->uuid, 'amount' => '1000']);
        BranchTransfer::query()->latest('id')->firstOrFail()->update(['sent_at' => now()->subDays(10)]);

        $this->as('admin');
        $keys = collect(app(CashAlertService::class)->alerts())->pluck('key');

        $this->assertContains('branch_transfers_in_transit', $keys);
        $this->assertContains('branches_not_sending', $keys);
    }

    public function test_only_headquarters_sets_how_often_a_branch_sends_money(): void
    {
        $this->as('manager', $this->buea->id);
        $this->patch("/branches/{$this->buea->uuid}/settings", ['sends_to_hq' => 'off'])->assertSessionHasErrors('sends_to_hq');

        $this->as('admin');
        $this->patch("/branches/{$this->buea->uuid}/settings", ['sends_to_hq' => 'monthly'])->assertSessionHasNoErrors();
        $this->assertSame('monthly', $this->buea->fresh()->sends_to_hq);
    }

    private function account(Branch $branch, string $name, string $opening): CashAccount
    {
        $account = CashAccount::query()->create(['kind' => CashAccount::KIND_SAFE, 'name' => $name, 'is_active' => true, 'branch_id' => $branch->id]);

        if (bccomp($opening, '0', 2) > 0) {
            $this->cashBook->record($account, 'in', $opening, CashMovement::KIND_OPENING_BALANCE, $this->kelvin);
        }

        return $account;
    }

    private function balance(CashAccount $account): string
    {
        $balance = CashMovement::query()->withoutGlobalScope(BranchScope::class)->where('cash_account_id', $account->id)
            ->selectRaw("coalesce(sum(case when direction = 'in' then amount else -amount end), 0) as balance")
            ->value('balance');

        return number_format((float) $balance, 2, '.', '');
    }

    /** Acts as kelvin, or as $email — a second person where the sender may not receive (wave 3). */
    private function as(string $role, ?int $branchId = null, ?string $email = null): void
    {
        $user = $email !== null ? User::query()->where('email', $email)->firstOrFail() : $this->kelvin;
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => $branchId]);
        app()->forgetInstance(TenantContext::class);
        $this->actingAs($user);
    }
}
