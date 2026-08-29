<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ArrearsAdjustment;
use App\Models\AuditLog;
use App\Models\TenantUser;
use App\Services\ArrearsAdjustmentService;
use App\Services\AuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Web (session-auth, Inertia) counterpart to Api\AuditLogController — same
 * AuditLogService, rendered as an Inertia page. Read-only: audit_logs is
 * append-only (audit-strategy.md section 2), so there's no store/update/
 * destroy here. Gated by AuditLogPolicy::viewAny (super/admin/manager only).
 */
class AuditLogController extends Controller
{
    /**
     * Tables tracked by App\Traits\Auditable (audit-strategy.md section
     * 4.2's model list) — offered as the filter dropdown's options
     * regardless of whether each table has produced any events yet.
     *
     * @var array<int, string>
     */
    private const AUDITED_TABLES = [
        'customers', 'payments', 'manuscripts', 'agents', 'zones',
        'expenditures', 'expense_categories', 'budgets', 'companies',
        'messages', 'payment_verifications',
    ];

    public function __construct(
        private readonly AuditLogService $auditLogs,
        private readonly TenantContext $context,
        private readonly ArrearsAdjustmentService $arrearsAdjustments,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditLog::class);

        // "All Activity" | "Arrears Adjustments" sub-tab (this feature's
        // design doc: "mirror SettingsTabs' pattern") — same query-param-
        // driven "one route, server picks payload" idiom as ReportController's
        // ?tier=. Gated to the SAME roles as the rest of this page
        // (AuditLogPolicy::viewAny — super/admin/manager), not a separate
        // check, since it is still the audit surface, just scoped to one
        // feature's records instead of every table.
        $view = $request->query('view') === 'arrears_adjustments' ? 'arrears_adjustments' : 'activity';

        $filters = $request->only(['table_name', 'action', 'user_uuid', 'search', 'record_uuid', 'from', 'to']);

        $paginated = $this->auditLogs->list($filters, 25);

        $categoryNames = $this->auditLogs->categoryNamesFor($paginated->getCollection());

        $paginated->through(fn (AuditLog $log): array => [
            'id' => $log->id,
            'table_name' => $log->table_name,
            'record_uuid' => $log->record_uuid,
            'action' => $log->action,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'user' => $log->user ? ['uuid' => $log->user->uuid, 'name' => $log->user->name] : null,
            'ip_address' => $log->ip_address,
            'device_id' => $log->device_id,
            'created_at' => $log->created_at,
            'summary' => $this->auditLogs->summarize($log, $categoryNames),
        ]);

        return Inertia::render('Audit/Index', [
            'view' => $view,
            'filters' => $filters,
            'logs' => $this->paginatorProps($paginated),
            'tables' => self::AUDITED_TABLES,
            'users' => $this->tenantUsers(),
            'arrears_adjustments' => $view === 'arrears_adjustments' ? $this->arrearsAdjustmentsTabData($request) : null,
        ]);
    }

    /**
     * This IS the arrears-adjustment audit trail the product owner asked for
     * (2026-08-28 addendum) — `arrears_adjustments` already carries every
     * fact a real audit needs (requester, both approvers + timestamps,
     * outcome, and — via `arrears_snapshot`, added below — the customer's
     * arrears balance immediately before the change), so this stays a
     * wiring task against the sub-tab that already existed, not a new
     * table. `arrears_snapshot` is the one field this row shape was missing
     * for "what changed" to be answerable from the table alone, without
     * clicking into a row; see Audit/Index.tsx's rendering of it.
     *
     * @return array{stats: array{pending_approval: int, applied_this_month: int, total_written_off: string}, adjustments: array{data: array<int, array<string, mixed>>, links: array<int, array{url: ?string, label: string, active: bool}>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}}
     */
    private function arrearsAdjustmentsTabData(Request $request): array
    {
        $filters = $request->only(['status']);
        $paginated = $this->arrearsAdjustments->list($filters, 25);

        $paginated->through(fn (ArrearsAdjustment $adjustment): array => [
            'uuid' => $adjustment->uuid,
            'target_period' => $adjustment->target_period,
            'direction' => $adjustment->direction,
            'amount' => $adjustment->amount,
            'arrears_snapshot' => $adjustment->arrears_snapshot,
            'reason_category' => $adjustment->reason_category,
            'reason_note' => $adjustment->reason_note,
            'status' => $adjustment->status,
            'customer_uuid' => $adjustment->customer?->uuid,
            'customer_name' => $adjustment->customer?->name,
            'requested_by_name' => $adjustment->requestedBy?->name,
            'approved_by_name' => $adjustment->approvedBy?->name,
            'second_approved_by_name' => $adjustment->secondApprovedBy?->name,
            'rejection_reason' => $adjustment->rejection_reason,
            'created_at' => $adjustment->created_at?->toIso8601String(),
            'can_approve' => $request->user()->can('approve', $adjustment),
            'can_reject' => $request->user()->can('reject', $adjustment),
            // Drives the web review UI's "approve your own request?" confirm
            // step — the super self-approval carve-out (ArrearsAdjustmentPolicy)
            // is allowed, but never silent.
            'is_own_request' => $adjustment->requested_by === $request->user()->id,
        ]);

        return [
            'stats' => $this->arrearsAdjustments->dashboard(),
            'adjustments' => $this->paginatorProps($paginated),
        ];
    }

    /**
     * @return array<int, array{uuid: string, name: string}>
     */
    private function tenantUsers(): array
    {
        return TenantUser::query()
            ->where('tenant_id', $this->context->tenantUser->tenant_id)
            ->with('user')
            ->get()
            ->map(fn (TenantUser $tenantUser): array => [
                'uuid' => $tenantUser->user->uuid,
                'name' => $tenantUser->user->name,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, links: array<int, array{url: ?string, label: string, active: bool}>, meta: array{current_page: int, per_page: int, total: int, last_page: int}}
     */
    private function paginatorProps(LengthAwarePaginator $paginator): array
    {
        $array = $paginator->toArray();

        return [
            'data' => $array['data'],
            'links' => $array['links'],
            'meta' => [
                'current_page' => $array['current_page'],
                'per_page' => $array['per_page'],
                'total' => $array['total'],
                'last_page' => $array['last_page'],
            ],
        ];
    }
}
