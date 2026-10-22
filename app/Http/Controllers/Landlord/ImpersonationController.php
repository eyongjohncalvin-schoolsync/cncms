<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Starts and stops landlord impersonation (App\Support\Impersonation).
 * start() sits behind the `landlord` middleware; stop() only needs `auth`,
 * because during impersonation the signed-in user is the workspace user.
 */
class ImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        try {
            Impersonation::start($request->user(), $user);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('dashboard');
    }

    public function stop(): RedirectResponse
    {
        if (! Impersonation::active()) {
            return redirect()->route('dashboard');
        }

        Impersonation::stop();

        return redirect()->route('landlord.tenants.index')->with('success', 'Back to your own account.');
    }
}
