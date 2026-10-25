<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\UpdateZoneCoverageRequest;
use App\Models\Agent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Scopes\BranchScope;
use App\Services\ZoneCoverageService;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Zone coverage: which field agents cover which zones of a branch — each
 * zone's responsible collector (printed on its bills) and any helpers — with
 * every agent's workload against the branch's customer limit. One branch at
 * a time; a fenced user only ever sees their own.
 *
 * TenantContext is resolved per call, not injected in the constructor (the
 * router may reuse a controller instance across requests).
 */
class ZoneCoverageController extends Controller
{
    public function index(Request $request, TenantContext $context, ZoneCoverageService $coverage): Response
    {
        $this->authorize('viewAny', Agent::class);

        $branch = $this->branchFor($request->string('branch')->toString() ?: null, $context);

        return Inertia::render('Zones/Coverage', [
            'branch' => ['uuid' => $branch->uuid, 'name' => $branch->name, 'is_headquarters' => (bool) $branch->is_headquarters],
            // Only someone who works for more than one branch picks one.
            'branches' => $context->isFenced() ? [] : Branch::allMemoized()->sortBy('name')
                ->map(fn (Branch $option): array => ['uuid' => $option->uuid, 'name' => $option->name])->values()->all(),
            ...$coverage->overview($branch),
            'can_manage' => $request->user()->can('update', Agent::class),
            'can_edit_limit' => $this->canEditLimit($request, $branch),
        ]);
    }

    public function update(UpdateZoneCoverageRequest $request, TenantContext $context, ZoneCoverageService $coverage): RedirectResponse
    {
        $data = $request->validated();
        $branch = $this->branchFor($data['branch_uuid'], $context, strict: true);
        $agent = isset($data['agent_uuid'])
            ? Agent::query()->withoutGlobalScope(BranchScope::class)->where('uuid', $data['agent_uuid'])->first()
            : null;

        $changed = $coverage->apply($branch, $data['action'], $agent, $data['zone_uuids'], (bool) ($data['keep_previous_as_helper'] ?? false), $request->user());
        Cache::forget('agents:all');

        return redirect()->route('zones.coverage', ['branch' => $branch->uuid])
            ->with('success', $changed === 0 ? 'Nothing to change.' : ($changed === 1 ? '1 zone updated.' : "{$changed} zones updated."));
    }

    /**
     * The customer limit per agent. Headquarters' value is everyone's
     * default (company settings); another branch may set its own, or clear
     * it to use headquarters'.
     */
    public function updateLimit(Request $request, TenantContext $context): RedirectResponse
    {
        $data = $request->validate([
            'branch_uuid' => ['required', 'uuid'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100000'],
        ]);
        $branch = $this->branchFor($data['branch_uuid'], $context, strict: true);
        abort_unless($this->canEditLimit($request, $branch), 403);

        $limit = $data['limit'] ?? null;

        if ($branch->is_headquarters) {
            Company::query()->firstOrFail()->update(['agent_customer_limit' => $limit]);
            Company::forgetCache();
        } else {
            // Saving a branch drops the branch and company memos itself.
            $branch->update(['agent_customer_limit' => $limit]);
        }

        return redirect()->route('zones.coverage', ['branch' => $branch->uuid])->with('success', 'Customer limit saved.');
    }

    private function canEditLimit(Request $request, Branch $branch): bool
    {
        return $branch->is_headquarters
            ? $request->user()->can('update', Company::class)
            : $request->user()->can('manageSettings', $branch);
    }

    /**
     * A fenced user's own branch — always. Anyone else: the branch asked for,
     * else the one they are viewing in the branch switcher, else headquarters.
     * With $strict, an unknown or out-of-reach branch is a 404, never a
     * silent fallback.
     */
    private function branchFor(?string $uuid, TenantContext $context, bool $strict = false): Branch
    {
        $branches = Branch::allMemoized();

        if ($context->isFenced()) {
            $own = $branches->get($context->branchId);
            abort_if($own === null || ($strict && $uuid !== $own->uuid), 404);

            return $own;
        }

        $asked = $uuid !== null ? $branches->firstWhere('uuid', $uuid) : null;
        abort_if($strict && $asked === null, 404);

        return $asked ?? $branches->get($context->branchId) ?? $branches->get(Branch::headquartersId());
    }
}
