<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only — audit_logs is append-only (audit-strategy.md section 2), so
 * there is deliberately no create/update/destroy here. Gated by
 * AuditLogPolicy::viewAny (super/admin/manager only).
 */
class AuditLogController extends Controller
{
    public function __construct(
        private readonly AuditLogService $auditLogs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $perPage = (int) $request->integer('per_page', 25);
        $filters = $request->only(['table_name', 'action', 'user_uuid', 'search', 'record_uuid', 'from', 'to']);

        $logs = $this->auditLogs->list($filters, $perPage);

        return AuditLogResource::collection($logs)->response();
    }
}
