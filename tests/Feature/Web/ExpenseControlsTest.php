<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use App\DataTransferObjects\ExpenditureData;
use App\Models\Budget;
use App\Models\Company;
use App\Models\Expenditure;
use App\Models\ExpenseCategory;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use App\Models\TenantUser;
use App\Models\User;
use App\Services\ExpenditureService;
use App\Services\SupplierService;
use Database\Factories\ExpenditureFactory;
use Database\Factories\ExpenseCategoryFactory;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Api\Concerns\InteractsWithTenantRoles;
use Tests\TestCase;

/**
 * Resources phase 1 — the expense controls: automatic approval for small
 * clean expenses, manager / owner approval tiers, maker ≠ checker, receipt
 * evidence, split / duplicate / backdate / budget detection, suppliers,
 * voiding instead of deleting, the database guards, regular expenses and
 * the mobile sync path.
 */
class ExpenseControlsTest extends TestCase
{
    use DatabaseTransactions;
    use InteractsWithTenantRoles;

    private ExpenseCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        $this->initializeTenant();
        Storage::fake('local');

        // Known limits, whatever the seeded company says.
        Company::query()->first()?->update([
            'expense_auto_approve_below' => 5000,
            'expense_second_approval_above' => 50000,
            'expense_receipt_required_from' => 2000,
            'expense_supplier_required_from' => 10000,
            'expense_backdate_days' => 1,
        ]);
        Company::forgetCache();

        $this->category = ExpenseCategoryFactory::new()->create(['name' => 'Ctl '.Str::random(6)]);
    }

    private function owner(string $role = 'super'): User
    {
        $user = User::query()->where('email', 'kelvin@shalomtech.dev')->firstOrFail();
        TenantUser::query()->where('user_id', $user->id)->update(['role' => $role, 'branch_id' => null]);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function record(User $by, string $amount, array $overrides = [], ?UploadedFile $receipt = null): Expenditure
    {
        $this->actingAs($by);

        return app(ExpenditureService::class)->create(ExpenditureData::fromArray([
            'category_uuid' => $this->category->uuid,
            'amount' => $amount,
            'spent_at' => Carbon::today()->toDateString(),
            'description' => 'Test',
            ...$overrides,
        ]), $by->id, $receipt);
    }

    private function photo(string $content = 'receipt-a'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.jpg', $content);
    }

    public function test_a_small_clean_expense_is_approved_automatically(): void
    {
        $expense = $this->record($this->owner('agent'), '1500');

        $this->assertSame(Expenditure::STATUS_APPROVED, $expense->status);
        $this->assertTrue($expense->auto_approved);
    }

    public function test_an_expense_over_the_limit_waits_and_its_recorder_cannot_approve_it(): void
    {
        $agent = $this->owner('manager');
        $expense = $this->record($agent, '8000', receipt: $this->photo());

        $this->assertSame(Expenditure::STATUS_PENDING_APPROVAL, $expense->status);

        $this->actingAs($agent)->post("/resources/expenditures/{$expense->uuid}/approve")->assertSessionHasErrors('expenditure');
        $this->assertSame(Expenditure::STATUS_PENDING_APPROVAL, $expense->fresh()->status);

        $this->actingAs($this->secondStaffUser('manager'))->post("/resources/expenditures/{$expense->uuid}/approve")->assertSessionHas('success');
        $this->assertSame(Expenditure::STATUS_APPROVED, $expense->fresh()->status);
    }

    public function test_a_large_expense_needs_two_different_approvers_the_second_being_the_owner(): void
    {
        $recorder = $this->secondStaffUser('agent');
        $expense = $this->record($recorder, '75000', ['supplier_uuid' => $this->activeSupplier()->uuid], $this->photo());

        $this->assertSame(Expenditure::STATUS_PENDING_APPROVAL, $expense->status);
        $this->assertTrue($expense->hasFlag('large'));

        $owner = $this->owner('admin');
        $this->actingAs($owner)->post("/resources/expenditures/{$expense->uuid}/approve")->assertSessionHas('success');
        $this->assertSame(Expenditure::STATUS_PENDING_SECOND_APPROVAL, $expense->fresh()->status);

        // An admin can't give the second approval (approve_large is the owner's)…
        $this->actingAs($owner)->post("/resources/expenditures/{$expense->uuid}/approve")->assertForbidden();

        // …and the owner can't give both approvals either.
        $this->owner('super');
        $this->actingAs($owner)->post("/resources/expenditures/{$expense->uuid}/approve")->assertSessionHasErrors('expenditure');
        $this->assertSame(Expenditure::STATUS_PENDING_SECOND_APPROVAL, $expense->fresh()->status);
    }

    public function test_an_expense_without_its_required_receipt_waits_for_evidence_then_moves_on(): void
    {
        $agent = $this->owner('agent');
        $expense = $this->record($agent, '3000');

        $this->assertSame(Expenditure::STATUS_PENDING_EVIDENCE, $expense->status);
        $this->assertTrue($expense->hasFlag('no_receipt'));

        $this->actingAs($agent)->post("/resources/expenditures/{$expense->uuid}/receipt", ['receipt' => $this->photo()])->assertSessionHas('success');

        $fresh = $expense->fresh();
        $this->assertSame(Expenditure::STATUS_APPROVED, $fresh->status);
        $this->assertNotNull($fresh->receipt_sha256);
        Storage::disk('local')->assertExists($fresh->receipt_path);
    }

    public function test_a_receipt_photo_used_twice_is_flagged_and_sent_to_a_person(): void
    {
        $agent = $this->owner('agent');
        $this->record($agent, '2500', receipt: $this->photo('same-photo'));
        $second = $this->record($agent, '2600', ['category_uuid' => ExpenseCategoryFactory::new()->create()->uuid], $this->photo('same-photo'));

        $this->assertTrue($second->hasFlag('duplicate_receipt'));
        $this->assertSame(Expenditure::STATUS_PENDING_APPROVAL, $second->status);
    }

    public function test_purchases_split_to_stay_under_the_limit_are_added_up(): void
    {
        $agent = $this->owner('agent');
        $first = $this->record($agent, '3000', receipt: $this->photo('a'));
        $second = $this->record($agent, '3500', receipt: $this->photo('b'));

        $this->assertSame(Expenditure::STATUS_APPROVED, $first->status);
        $this->assertTrue($second->hasFlag('split'));
        $this->assertSame(Expenditure::STATUS_PENDING_APPROVAL, $second->status);
    }

    public function test_backdated_and_duplicate_entries_need_a_person(): void
    {
        $agent = $this->owner('agent');
        $old = $this->record($agent, '1000', ['spent_at' => Carbon::today()->subDays(5)->toDateString()]);
        $this->assertTrue($old->hasFlag('backdated'));
        $this->assertSame(Expenditure::STATUS_PENDING_APPROVAL, $old->status);

        $otherCategory = ExpenseCategoryFactory::new()->create();
        $this->record($agent, '1200', ['category_uuid' => $otherCategory->uuid]);
        $again = $this->record($agent, '1200', ['category_uuid' => $otherCategory->uuid]);
        $this->assertTrue($again->hasFlag('duplicate_entry'));
        $this->assertSame(Expenditure::STATUS_PENDING_APPROVAL, $again->status);
    }

    public function test_going_over_budget_needs_the_owner(): void
    {
        Budget::query()->create(['category_id' => $this->category->id, 'period' => Carbon::today()->format('Y-m'), 'amount' => 4000]);

        $expense = $this->record($this->owner('agent'), '4500', receipt: $this->photo());

        $this->assertTrue($expense->hasFlag('over_budget'));
        $this->assertTrue($expense->hasFlag('large'));
    }

    public function test_a_supplier_needs_a_second_person_and_a_new_one_sends_the_expense_for_review(): void
    {
        $agent = $this->owner('agent');
        $this->actingAs($agent);
        $supplier = app(SupplierService::class)->create(['name' => 'Shell Kumba '.Str::random(4)], $agent);
        $this->assertSame(Supplier::STATUS_PENDING, $supplier->status);

        $expense = $this->record($agent, '1000', ['supplier_uuid' => $supplier->uuid]);
        $this->assertTrue($expense->hasFlag('new_supplier'));
        $this->assertSame(Expenditure::STATUS_PENDING_APPROVAL, $expense->status);

        $this->expectException(ValidationException::class);
        app(SupplierService::class)->approve($supplier, $agent);
    }

    public function test_voiding_keeps_the_record_and_it_stops_counting_in_the_profit_and_loss(): void
    {
        $owner = $this->owner('super');
        $expense = ExpenditureFactory::new()->create(['category_id' => $this->category->id, 'user_id' => $this->secondStaffUser()->id, 'amount' => 7000, 'spent_at' => Carbon::today()]);

        $this->actingAs($owner)->post("/resources/expenditures/{$expense->uuid}/void", ['reason' => 'Paid by the customer'])->assertSessionHas('success');

        $this->assertDatabaseHas('expenditures', ['id' => $expense->id, 'status' => 'void']);
        $this->actingAs($owner)->get('/resources?period='.Carbon::today()->format('Y-m'))->assertInertia(fn (Assert $page) => $page
            ->where('expenses.by_category', fn ($rows) => collect($rows)->firstWhere('name', $this->category->name) === null));
    }

    public function test_the_database_refuses_to_change_an_approved_expense_or_delete_any_expense(): void
    {
        $expense = ExpenditureFactory::new()->create(['category_id' => $this->category->id, 'user_id' => $this->owner()->id, 'amount' => 1000]);

        try {
            DB::transaction(fn () => DB::table('expenditures')->where('id', $expense->id)->update(['amount' => 1]));
            $this->fail('An approved expense was changed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('cannot be changed', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('expenditures')->where('id', $expense->id)->delete());
            $this->fail('An expense was deleted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('never deleted', $e->getMessage());
        }
    }

    public function test_a_payment_reference_can_only_be_used_once(): void
    {
        $agent = $this->owner('agent');
        $this->record($agent, '1000', ['payment_method' => 'mtn_momo', 'payment_ref' => 'MP-EXP-1']);

        $this->expectException(ValidationException::class);
        $this->record($agent, '1100', ['payment_method' => 'mtn_momo', 'payment_ref' => 'mp-exp-1']);
    }

    public function test_a_regular_expense_is_recorded_once_per_month(): void
    {
        $owner = $this->owner('super');
        $template = RecurringExpense::query()->create([
            'category_id' => $this->category->id, 'name' => 'Office rent', 'amount' => 3000, 'day_of_month' => 1, 'created_by' => $owner->id,
        ]);
        $period = Carbon::today()->format('Y-m');

        $this->actingAs($owner)->post("/resources/recurring/{$template->uuid}/record", ['period' => $period])->assertSessionHas('success');
        $this->actingAs($owner)->post("/resources/recurring/{$template->uuid}/record", ['period' => $period])->assertSessionHasErrors('template');

        $this->assertSame(1, Expenditure::query()->where('recurring_expense_id', $template->id)->count());
    }

    public function test_the_approvals_inbox_shows_others_pending_items_but_never_your_own(): void
    {
        $owner = $this->owner('manager');
        $mine = $this->record($owner, '9000', receipt: $this->photo('m'));
        $theirs = $this->record($this->secondStaffUser('agent'), '9500', receipt: $this->photo('t'));

        $this->owner('manager');
        $this->actingAs($owner)->get('/resources/approvals')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Resources/Approvals')
            ->where('items', fn ($items) => collect($items)->pluck('uuid')->contains($theirs->uuid)
                && ! collect($items)->pluck('uuid')->contains($mine->uuid)));
    }

    public function test_an_offline_expense_from_the_app_waits_for_its_receipt_then_moves_on_when_it_uploads(): void
    {
        $token = $this->tokenForRole('agent');
        $localUuid = (string) Str::uuid();

        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/v1/sync/push', [
            'device_id' => 'device-exp-1',
            'changes' => ['expenditures' => [[
                'local_uuid' => $localUuid,
                'category_uuid' => $this->category->uuid,
                'amount' => 3000,
                'spent_at' => Carbon::today()->toDateString(),
                'payment_method' => 'cash',
                'created_at' => Carbon::now()->toIso8601String(),
            ]]],
        ])->assertOk();

        $expense = Expenditure::query()->where('local_uuid', $localUuid)->firstOrFail();
        $this->assertSame(Expenditure::STATUS_PENDING_EVIDENCE, $expense->status);
        $this->assertSame('cash', $expense->payment_method);

        $this->withHeader('Authorization', "Bearer {$token}")->post('/api/v1/sync/upload-receipt', [
            'entity_type' => 'expenditure',
            'entity_uuid' => $expense->uuid,
            'receipt' => UploadedFile::fake()->image('r.jpg'),
            'source' => 'camera',
        ])->assertOk();

        $this->assertSame(Expenditure::STATUS_APPROVED, $expense->fresh()->status);
        $this->assertSame('camera', $expense->fresh()->receipt_meta['source']);
    }

    private function activeSupplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Active '.Str::random(6), 'status' => Supplier::STATUS_ACTIVE, 'created_by' => $this->owner()->id,
        ]);
    }
}
