<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Services\MoneyBalanceService;
use App\Support\BusinessTimezone;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Money balance page (owner, 2026-10-28, wave 5): one branch's month,
 * proving every franc collected, held, moved and spent adds up. Seen by
 * whoever sees the whole cash book (CashAccountPolicy::viewAny).
 */
class MoneyBalanceController extends Controller
{
    public function index(Request $request, MoneyBalanceService $balance): Response
    {
        $this->authorize('viewAny', CashAccount::class);

        $period = $request->query('period');
        $current = BusinessTimezone::currentPeriod();

        if (! is_string($period) || ! BusinessTimezone::isValidPeriod($period) || $period > $current) {
            $period = $current;
        }

        return Inertia::render('Cash/Balance', $balance->forMonth($period) + [
            'previous_period' => BusinessTimezone::previousPeriod($period),
            'next_period' => $period < $current ? BusinessTimezone::periodStart($period)->addMonthNoOverflow()->format('Y-m') : null,
        ]);
    }
}
