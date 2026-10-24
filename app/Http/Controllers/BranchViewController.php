<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBranchViewRequest;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;

/**
 * The branch switcher (docs/plans/branches-v2.md §8): an unfenced user
 * (owner, HQ-level staff, investor) narrows every screen to one branch, or
 * back to all branches. Remembered in the session;
 * App\Http\Middleware\ResolveTenantWeb applies it to the request's
 * TenantContext.
 */
class BranchViewController extends Controller
{
    public const string SESSION_KEY = 'branch_view';

    public function update(UpdateBranchViewRequest $request): RedirectResponse
    {
        $branchUuid = $request->validated('branch_uuid');

        if ($branchUuid === null) {
            $request->session()->forget(self::SESSION_KEY);
        } else {
            $request->session()->put(self::SESSION_KEY, (int) Branch::query()->where('uuid', $branchUuid)->value('id'));
        }

        return back();
    }
}
