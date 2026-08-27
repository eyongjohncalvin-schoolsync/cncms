<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\ManuscriptService;
use App\Services\PaymentService;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
        private readonly PaymentService $payments,
    ) {}

    public function index(Request $request): Response
    {
        $period = (string) $request->query('period', now()->format('Y-m'));

        $summary = $this->manuscripts->summary(['period' => $period]);

        $branchId = TenantContext::currentBranchId();

        $counts = Cache::remember(
            'dashboard:counts:'.($branchId ?? 'all'),
            now()->addSeconds(60),
            fn (): array => [
                'total_customers' => Customer::query()
                    ->when($branchId !== null, fn ($query) => $query->whereHas('zone', fn ($inner) => $inner->where('branch_id', $branchId)))
                    ->count(),
                'active_customers' => Customer::query()
                    ->where('status', 'active')
                    ->when($branchId !== null, fn ($query) => $query->whereHas('zone', fn ($inner) => $inner->where('branch_id', $branchId)))
                    ->count(),
            ],
        );

        return Inertia::render('Dashboard', [
            'period' => $period,
            'stats' => [
                'total_customers' => $counts['total_customers'],
                'active_customers' => $counts['active_customers'],
                // Cached separately (short 15s TTL) via PaymentService since
                // this figure changes far more often than the customer
                // counts above (new payments, verify/reject actions).
                'pending_verifications' => $this->payments->pendingVerificationCount(),
                'monthly_income' => $summary['total_collected'],
                'total_arrears' => $summary['total_arrears'],
                'collection_rate' => $summary['collection_rate'],
            ],
        ]);
    }
}
