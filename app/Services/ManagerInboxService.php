<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ArrearsAdjustment;
use App\Models\Complaint;
use App\Models\OpeningBalanceBatch;
use App\Models\PaymentReversalRequest;
use App\Models\User;
use App\Models\Voucher;
use App\Support\TenantContext;
use Illuminate\Support\Carbon;

/**
 * The phone's "Needs you" inbox (docs/plans/mobile-manager-app.md, wave 1):
 * everything waiting for THIS user's decision, from the same rules the web
 * uses — VoucherService::canDecide(), the arrears / payment / complaint
 * policies, the permission catalog. Branch fences apply through each
 * model's BranchScope.
 *
 * Items the phone can decide carry `actions`; the rest (field activations,
 * payments to verify, cash alerts, opening balances) are counts with a
 * `web_path` — the app says "open on the website".
 *
 * Severity: `now` (money or customers waiting, or escalated), `today`,
 * `fyi`. Newest first within each.
 */
class ManagerInboxService
{
    private const int LIMIT = 50;

    public function __construct(
        private readonly VoucherService $vouchers,
        private readonly CashAlertService $cashAlerts,
        private readonly FieldInstallationService $fieldInstallations,
        private readonly PaymentService $payments,
    ) {}

    /**
     * @return list<array{key: string, type: string, uuid: string|null, title: string, subtitle: string, amount: string|null, severity: 'now'|'today'|'fyi', created_at: string|null, actions: list<string>, web_path: string}>
     */
    public function items(User $user, TenantContext $context): array
    {
        $items = [
            ...$this->vouchersFor($user, $context),
            ...$this->complaintsFor($context),
            ...$this->arrearsFor($user),
            ...$this->reversalsFor($user, $context),
            ...$this->countsFor($context),
        ];

        $rank = ['now' => 0, 'today' => 1, 'fyi' => 2];

        usort($items, fn (array $a, array $b): int => [$rank[$a['severity']], $b['created_at'] ?? ''] <=> [$rank[$b['severity']], $a['created_at'] ?? '']);

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function vouchersFor(User $user, TenantContext $context): array
    {
        return Voucher::query()
            ->where('status', 'pending')
            ->with('requestedBy:id,name')
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->filter(fn (Voucher $voucher) => $this->vouchers->canDecide($voucher, $user, $context))
            ->map(fn (Voucher $voucher) => $this->item(
                'voucher',
                $voucher->uuid,
                "Voucher {$voucher->number}: {$voucher->purpose}",
                ($voucher->requestedBy?->name ?? 'Someone').' asks · '.($voucher->payee_name ?: 'no payee'),
                (string) $voucher->amount,
                'now',
                $voucher->created_at,
                ['approve', 'reject'],
                "/vouchers/{$voucher->uuid}",
            ))
            ->values()
            ->all();
    }

    /**
     * Open complaints with nobody on them, and escalated ones, for whoever
     * assigns technicians.
     *
     * @return list<array<string, mixed>>
     */
    private function complaintsFor(TenantContext $context): array
    {
        if (! $context->can('complaints.assign')) {
            return [];
        }

        return Complaint::query()
            ->where('status', 'open')
            ->whereNull('duplicate_of_id')
            ->where(fn ($query) => $query->whereNull('assigned_to')->orWhereNotNull('escalated_at'))
            ->with(['customer:id,name', 'assignedTo:id,name'])
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->map(fn (Complaint $complaint) => $this->item(
                'complaint',
                $complaint->uuid,
                $complaint->escalated_at ? "Escalated: {$complaint->title}" : "Assign: {$complaint->title}",
                $complaint->assignedTo ? "With {$complaint->assignedTo->name} · overdue" : ($complaint->customer?->name ?? ucfirst((string) $complaint->category).' complaint').' · nobody assigned',
                null,
                $complaint->escalated_at || $complaint->urgent ? 'now' : 'today',
                $complaint->created_at,
                ['assign'],
                "/complaints/{$complaint->uuid}",
            ))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function arrearsFor(User $user): array
    {
        return ArrearsAdjustment::query()
            ->whereIn('status', ['pending', 'pending_second_approval'])
            ->with(['customer:id,name', 'requestedBy:id,name'])
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            ->filter(fn (ArrearsAdjustment $adjustment) => $user->can('approve', $adjustment))
            ->map(fn (ArrearsAdjustment $adjustment) => $this->item(
                'arrears_adjustment',
                $adjustment->uuid,
                ($adjustment->status === 'pending_second_approval' ? 'Second approval: ' : 'Arrears adjustment: ').($adjustment->customer?->name ?? 'customer'),
                ucfirst(str_replace('_', ' ', (string) $adjustment->reason_category)).' · '.($adjustment->requestedBy?->name ?? 'someone'),
                ($adjustment->direction === 'decrease' ? '-' : '').$adjustment->amount,
                'today',
                $adjustment->created_at,
                ['approve', 'reject'],
                '/arrears-adjustments',
            ))
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function reversalsFor(User $user, TenantContext $context): array
    {
        return PaymentReversalRequest::query()
            ->where('status', PaymentReversalRequest::STATUS_PENDING)
            ->with(['payment.customer:id,name', 'requestedBy:id,name'])
            ->latest()
            ->limit(self::LIMIT)
            ->get()
            // Office work: not for a collector fenced to their own zones.
            ->filter(fn (PaymentReversalRequest $request) => $request->payment !== null && $context->zoneIds === null && $user->can('declineReversal', $request->payment))
            ->map(fn (PaymentReversalRequest $request) => $this->item(
                'reversal_request',
                $request->uuid,
                'Reverse a payment? '.($request->payment->customer?->name ?? ''),
                ($request->requestedBy?->name ?? 'A collector').': '.$request->reason,
                (string) $request->payment->amount,
                'today',
                $request->created_at,
                $user->can('reverse', $request->payment) ? ['reverse', 'decline'] : ['decline'],
                "/payments/{$request->payment->uuid}",
            ))
            ->values()
            ->all();
    }

    /**
     * Work done on the website, shown as counts.
     *
     * @return list<array<string, mixed>>
     */
    private function countsFor(TenantContext $context): array
    {
        $counts = [];

        if ($context->can('cash.view') && ($alerts = array_sum($this->cashAlerts->counts())) > 0) {
            $counts[] = $this->item('cash_alerts', null, "{$alerts} cash ".($alerts === 1 ? 'alert' : 'alerts'), 'Cash not handed over, shortages, unsigned vouchers', null, 'now', null, [], '/cash/alerts');
        }

        if ($context->can('installations.activate') && ($pending = $this->fieldInstallations->pendingCount()) > 0) {
            $counts[] = $this->item('field_activations', null, "{$pending} new ".($pending === 1 ? 'customer' : 'customers').' to activate', 'Registered in the field, waiting for the office', null, 'today', null, [], '/customers?pending_activation=1');
        }

        // Branch-wide count: not for a collector fenced to their own zones.
        if ($context->can('payments.verify') && $context->zoneIds === null && ($pending = $this->payments->pendingVerificationCount()) > 0) {
            $counts[] = $this->item('payments_to_verify', null, "{$pending} ".($pending === 1 ? 'payment' : 'payments').' to verify', 'Recorded by collectors, not yet checked', null, 'today', null, [], '/payments?verification_status=pending');
        }

        if ($context->can('opening_balances.approve') && ($pending = OpeningBalanceBatch::query()->where('status', 'pending_approval')->count()) > 0) {
            $counts[] = $this->item('opening_balances', null, "{$pending} opening ".($pending === 1 ? 'balance' : 'balances').' to approve', 'Starting balances for imported customers', null, 'fyi', null, [], '/opening-balances');
        }

        return $counts;
    }

    /**
     * @param  'now'|'today'|'fyi'  $severity
     * @param  list<string>  $actions
     * @return array{key: string, type: string, uuid: string|null, title: string, subtitle: string, amount: string|null, severity: 'now'|'today'|'fyi', created_at: string|null, actions: list<string>, web_path: string}
     */
    private function item(string $type, ?string $uuid, string $title, string $subtitle, ?string $amount, string $severity, ?Carbon $createdAt, array $actions, string $webPath): array
    {
        return [
            'key' => $type.':'.($uuid ?? 'count'),
            'type' => $type,
            'uuid' => $uuid,
            'title' => $title,
            'subtitle' => $subtitle,
            'amount' => $amount,
            'severity' => $severity,
            'created_at' => $createdAt?->toIso8601String(),
            'actions' => $actions,
            'web_path' => $webPath,
        ];
    }
}
