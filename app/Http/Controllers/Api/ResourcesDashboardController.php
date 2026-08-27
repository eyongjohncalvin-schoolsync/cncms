<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Expenditure;
use App\Services\ResourcesDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResourcesDashboardController extends Controller
{
    public function __construct(
        private readonly ResourcesDashboardService $dashboard,
    ) {}

    public function index(Request $request): JsonResponse
    {
        // Gated via ExpenditurePolicy::viewDashboard() rather than a
        // dedicated Dashboard model — see business-rules.md section 10's
        // "View P&L dashboard": super/admin/manager.
        $this->authorize('viewDashboard', Expenditure::class);

        $period = $request->query('period');

        return response()->json($this->dashboard->dashboard($period !== null ? (string) $period : null));
    }
}
