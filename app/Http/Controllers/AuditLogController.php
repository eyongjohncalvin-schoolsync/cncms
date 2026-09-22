<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\TenantUser;
use App\Services\AuditLogService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
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
    ) {}

    public function index(Request $request): Response|RedirectResponse
    {
        // The Arrears Adjustments sub-tab moved to its own page
        // (ArrearsAdjustmentController::index, 2026-09-22); old links and
        // bookmarks to ?view=arrears_adjustments land there instead.
        if ($request->query('view') === 'arrears_adjustments') {
            return redirect()->route('arrears-adjustments.index', $request->only(['status', 'page']));
        }

        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->only(['table_name', 'action', 'user_uuid', 'search', 'record_uuid', 'from', 'to']);

        // withQueryString(): page links keep the active filters.
        $paginated = $this->auditLogs->list($filters, 25)->withQueryString();

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
            'filters' => $filters,
            'logs' => $this->paginatorProps($paginated),
            'tables' => self::AUDITED_TABLES,
            'users' => $this->tenantUsers(),
        ]);
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
