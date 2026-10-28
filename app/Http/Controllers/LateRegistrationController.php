<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ArrearsAdjustment;
use App\Models\Customer;
use App\Models\User;
use App\Services\LateRegistrationService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Charge as old debt" for a customer registered late (owner, 2026-10-26):
 * the months that passed unbilled — or every month since connection, ending
 * their free months — become an arrears adjustment on the current bill,
 * approved by someone else (App\Services\LateRegistrationService). Decided
 * by those who approve arrears decisions (`arrears.approve`).
 */
class LateRegistrationController extends Controller
{
    public function __construct(
        private readonly LateRegistrationService $lateRegistrations,
        private readonly TenantContext $context,
    ) {}

    /**
     * The late-registration report, for auditing (owner, 2026-10-26): every
     * customer registered after their connection month closed, in the last
     * 12 months — how late, who connected them, what the customer says they
     * paid, the months not billed and what was charged — totalled per
     * person who connected them, per branch and per zone.
     */
    public function index(Request $request): Response
    {
        abort_unless($this->context->canAny('audit.view', 'arrears.approve'), 403);

        $months = (int) $request->integer('months', 12);
        $months = in_array($months, [3, 6, 12, 24], true) ? $months : 12;

        $customers = Customer::query()
            ->withTrashed()
            ->with(['zone.branch'])
            ->whereNotNull('connected_on')
            ->whereNotNull('billing_starts_period')
            ->whereRaw("to_char(connected_on, 'YYYY-MM') < billing_starts_period")
            ->where('created_at', '>=', now()->subMonths($months))
            ->latest('created_at')
            ->get();

        $connectors = User::query()->whereIn('id', $customers->pluck('connected_by_user_id')->filter()->unique())->pluck('name', 'id');

        $rows = $customers->map(function (Customer $customer) use ($connectors): array {
            $summary = $this->lateRegistrations->summary($customer);
            $charged = $summary['charge'] !== null && $summary['charge']['status'] !== 'rejected' ? $summary['charge']['amount'] : '0.00';

            return [
                'uuid' => $customer->uuid,
                'name' => $customer->name,
                'archived' => $customer->trashed(),
                'branch' => $customer->zone?->branch?->name ?? '—',
                'zone' => $customer->zone?->name ?? '—',
                'connected_on' => $customer->connected_on?->toDateString(),
                'registered_on' => $customer->created_at?->toDateString(),
                'days_late' => (int) $customer->connected_on?->diffInDays($customer->created_at, true),
                'connected_by' => $connectors[$customer->connected_by_user_id] ?? 'Not recorded',
                'reason' => $customer->late_registration_reason,
                'says_paid_amount' => $customer->customer_says_paid_amount !== null ? (string) $customer->customer_says_paid_amount : null,
                'says_paid_to' => $customer->customer_says_paid_to,
                'not_billed_months' => $summary['missed_months'] ?? 0,
                'not_billed_amount' => $summary['missed_amount'] ?? '0.00',
                'charged_amount' => $charged,
                'charge_status' => $summary['charge']['status'] ?? null,
            ];
        });

        $groupBy = fn (string $key) => $rows->groupBy($key)->map(fn ($group, $name): array => [
            'name' => (string) $name,
            'customers' => $group->count(),
            'average_days_late' => (int) round($group->avg('days_late')),
            'not_billed_amount' => $group->reduce(fn (string $carry, array $row): string => bcadd($carry, $row['not_billed_amount'], 2), '0.00'),
            'charged_amount' => $group->reduce(fn (string $carry, array $row): string => bcadd($carry, $row['charged_amount'], 2), '0.00'),
            'says_paid_someone' => $group->filter(fn (array $row): bool => (float) ($row['says_paid_amount'] ?? 0) > 0)->count(),
        ])->sortByDesc('not_billed_amount')->values();

        return Inertia::render('LateRegistrations/Index', [
            'months' => $months,
            'rows' => $rows->values(),
            'by_connector' => $groupBy('connected_by'),
            'by_branch' => $groupBy('branch'),
            'by_zone' => $groupBy('zone'),
        ]);
    }

    public function charge(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($this->context->can('arrears.approve'), 403);

        $validated = $request->validate([
            'scope' => ['required', 'in:missed,all'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999.99', 'decimal:0,2'],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->lateRegistrations->charge($customer, $validated['scope'], (string) $validated['amount'], $validated['note'], $request->user());

        return back()->with('success', 'Old debt of '.number_format((float) $validated['amount'], 0, '.', ',').' FCFA asked for — another approver confirms it on Arrears Adjustments'
            .($validated['scope'] === 'all' ? '; the free months end when it is approved.' : '.'));
    }

    /** A manager says what became of the money the customer says they paid someone (a loss decision: vouchers.approve). */
    public function settleSaysPaid(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($this->context->can('vouchers.approve'), 403);

        $validated = $request->validate([
            'outcome' => ['required', 'in:'.implode(',', LateRegistrationService::SAYS_PAID_OUTCOMES)],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $this->lateRegistrations->settleSaysPaid($customer, $validated['outcome'], $validated['note'], $request->user());

        return back()->with('success', 'Settled — it leaves the cash alerts.');
    }

    /** Correct the connection date and reason; the registration month undoes the late registration. */
    public function correct(Request $request, Customer $customer): RedirectResponse
    {
        $this->authorize('update', $customer);

        $validated = $request->validate([
            'connected_on' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $this->lateRegistrations->correct($customer, $validated['connected_on'], $validated['reason'] ?? null, $request->user());

        return back()->with('success', 'Connection date corrected — free months and billing follow it.');
    }

    /** Whoever asked for the old-debt charge takes it back while it waits. */
    public function withdraw(Request $request, Customer $customer): RedirectResponse
    {
        $charge = ArrearsAdjustment::query()
            ->where('customer_id', $customer->id)
            ->where('reason_category', LateRegistrationService::REASON_CATEGORY)
            ->whereIn('status', ['pending', 'pending_second_approval'])
            ->latest('id')
            ->firstOrFail();

        $this->lateRegistrations->withdraw($charge, $request->user());

        return back()->with('success', 'Old-debt charge withdrawn.');
    }

    /** Undo an approved old-debt charge: an equal credit, approved by someone else. */
    public function reverse(Request $request, Customer $customer): RedirectResponse
    {
        abort_unless($this->context->can('arrears.request'), 403);

        $validated = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        $this->lateRegistrations->reverse($customer, $validated['note'], $request->user());

        return back()->with('success', 'Reversal asked for — another approver confirms it; the debt is credited back (and any free months it ended return).');
    }
}
