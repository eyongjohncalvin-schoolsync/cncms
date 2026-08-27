<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Services\NotificationService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The three recipient-facing actions the bell dropdown / emergency banner
 * trigger (in-app-notifications.md section 4) — nothing here renders a
 * page of its own. Every action redirects back rather than returning
 * JSON: the `notifications` shared Inertia prop
 * (App\Http\Middleware\HandleInertiaRequests) is re-evaluated on the
 * resulting page render, which is what actually refreshes the bell/badge/
 * banner client-side. There is deliberately no index()/store() here —
 * see App\Policies\NotificationPolicy's class doc for why notifications
 * are only ever created by other backend code, never via HTTP.
 */
class NotificationController extends Controller
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly TenantContext $context,
    ) {}

    public function markRead(Request $request, Notification $notification): RedirectResponse
    {
        $this->authorize('view', $notification);

        $this->notifications->markRead($notification, $request->user());

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $this->authorize('viewAny', Notification::class);

        $this->notifications->markAllRead($request->user(), $this->context);

        return back();
    }

    public function acknowledge(Request $request, Notification $notification): RedirectResponse
    {
        $this->authorize('acknowledge', $notification);

        $this->notifications->acknowledge($notification, $request->user());

        return back();
    }
}
