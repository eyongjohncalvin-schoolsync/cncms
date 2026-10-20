<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CashAccount;
use App\Services\CashAlertService;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cash alerts page (docs/plans/cash-controls.md, wave 6) — everything
 * in the cash controls that needs someone to act, computed live by
 * App\Services\CashAlertService. Same audience as the cash book (`cash.view`).
 */
class CashAlertController extends Controller
{
    public function index(CashAlertService $alerts): Response
    {
        $this->authorize('viewAny', CashAccount::class);

        return Inertia::render('Cash/Alerts', [
            'alerts' => $alerts->alerts(),
        ]);
    }
}
