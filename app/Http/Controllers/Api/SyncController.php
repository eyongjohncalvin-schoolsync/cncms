<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SyncPushRequest;
use App\Models\Agent;
use App\Services\SyncService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Offline sync endpoints for the (not-yet-built) mobile agent app — see
 * .ai/skills/cncms/cncms-context/references/offline-sync-strategy.md and
 * references/api-spec.md section 7. Sync isn't a CRUD resource, so rather
 * than a dedicated SyncPolicy this gates on the same "any authenticated
 * tenant member with an agent+ role" check inline, via TenantContext —
 * agents are the primary users, but admin/manager can exercise it too.
 */
class SyncController extends Controller
{
    public function __construct(
        private readonly SyncService $sync,
        private readonly TenantContext $context,
    ) {}

    public function push(SyncPushRequest $request): JsonResponse
    {
        $this->authorizeSync();

        $data = $request->validated();

        $result = $this->sync->push(
            $data['changes'] ?? [],
            $data['device_id'],
            $data['last_sync_at'] ?? null,
            $request->user(),
        );

        return response()->json($result);
    }

    public function pull(Request $request): JsonResponse
    {
        $this->authorizeSync();

        $result = $this->sync->pull($request->query('since'), $request->user());

        return response()->json($result);
    }

    public function uploadReceipt(Request $request): JsonResponse
    {
        $this->authorizeSync();

        $validated = $request->validate([
            'entity_type' => ['required', 'string', 'in:payment,expenditure'],
            'entity_uuid' => ['required', 'uuid'],
            'receipt' => ['required', 'image', 'max:2048'],
        ]);

        $result = $this->sync->uploadReceipt(
            $validated['entity_type'],
            $validated['entity_uuid'],
            $request->file('receipt'),
        );

        return response()->json($result);
    }

    /**
     * `GET /sync/status` doesn't take a device_id in api-spec.md section
     * 7.4's documented request shape — it's resolved from the caller's own
     * Agent record (agents.sync_token, set by the last push()). An explicit
     * `?device_id=` query param is also accepted for the admin "check any
     * device" case. If neither resolves to anything (e.g. an admin/manager
     * with no Agent row and no query param), an all-zero response is
     * returned rather than a 404/422 — there is simply nothing to report.
     */
    public function status(Request $request): JsonResponse
    {
        $this->authorizeSync();

        $deviceId = $request->query('device_id');

        if (! $deviceId) {
            $deviceId = Agent::query()->where('user_id', $request->user()->id)->value('sync_token');
        }

        if (! $deviceId) {
            return response()->json([
                'device_id' => null,
                'last_sync_at' => null,
                'pending_push' => 0,
                'pending_pull' => 0,
                'failed_items' => 0,
            ]);
        }

        return response()->json($this->sync->status($deviceId));
    }

    private function authorizeSync(): void
    {
        abort_unless(
            $this->context->isAnyOf('super', 'admin', 'manager', 'agent'),
            403,
            'You do not have access to sync.',
        );
    }
}
