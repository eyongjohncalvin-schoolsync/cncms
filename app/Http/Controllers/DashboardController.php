<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Payment;
use App\Services\ManuscriptService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __construct(
        private readonly ManuscriptService $manuscripts,
    ) {}

    public function index(Request $request): Response
    {
        $period = (string) $request->query('period', now()->format('Y-m'));

        $summary = $this->manuscripts->summary(['period' => $period]);

        return Inertia::render('Dashboard', [
            'period' => $period,
            'stats' => [
                'total_customers' => Customer::query()->count(),
                'active_customers' => Customer::query()->where('status', 'active')->count(),
                'pending_verifications' => Payment::query()->where('verification_status', 'pending')->count(),
                'monthly_income' => $summary['total_collected'],
                'total_arrears' => $summary['total_arrears'],
                'collection_rate' => $summary['collection_rate'],
            ],
        ]);
    }
}
