<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\BranchData;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\StoreBranchZonesRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\TenantUser;
use App\Models\Zone;
use App\Services\BranchService;
use App\Services\ZoneService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Manage Branches" surface — see
 * .ai/skills/cncms/cncms-context/references/branches-and-locations.md
 * section 6's rollout notes. Full CRUD: branches are new infrastructure and
 * the doc's section 8 recommends branch-first creation (a Zone picks an
 * existing Branch) rather than inline branch management from within the
 * Zone form.
 */
class BranchController extends Controller
{
    public function __construct(
        private readonly BranchService $branches,
        private readonly ZoneService $zones,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Branch::class);

        $paginator = $this->branches->list(15);

        return Inertia::render('Branches/Index', [
            'branches' => [
                'data' => collect($paginator->items())
                    ->map(fn (Branch $branch) => $this->shapeBranch($branch))
                    ->all(),
                'links' => $paginator->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                ],
            ],
            // Setup links ("Set up", "Add zones") only for whoever may edit branches.
            'can_manage' => request()->user()->can('create', Branch::class),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Branch::class);

        return Inertia::render('Branches/Create');
    }

    /**
     * A new branch goes straight to its setup page (zones, manager,
     * settings) rather than back to the list.
     */
    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $branch = $this->branches->create(BranchData::fromArray($request->validated()));

        return redirect()->route('branches.setup', $branch)->with('success', "{$branch->name} created.");
    }

    /**
     * GET /branches/{branch}/setup: quick-add zones plus a checklist of what
     * the branch still needs. Reachable any time from the branch list, for
     * whoever may edit the branch.
     */
    public function setup(Request $request, Branch $branch): Response
    {
        $this->authorize('update', $branch);

        $user = $request->user();

        return Inertia::render('Branches/Setup', [
            'branch' => $this->shapeBranch($branch),
            'checklist' => $this->branches->setupChecklist($branch),
            'can' => [
                'add_zones' => $user->can('create', Zone::class),
                'manage_users' => $user->can('viewAny', TenantUser::class),
                'manage_settings' => $user->can('manageSettings', $branch),
            ],
            'max_names' => StoreBranchZonesRequest::MAX_NAMES,
        ]);
    }

    /**
     * POST /branches/{branch}/zones: the setup page's quick-add — every
     * name validated by StoreBranchZonesRequest, then all created in one
     * transaction.
     */
    public function storeZones(StoreBranchZonesRequest $request, Branch $branch): RedirectResponse
    {
        $created = $this->zones->createManyInBranch($branch->id, $request->validated('names'));

        $count = $created->count();

        return redirect()->route('branches.setup', $branch)
            ->with('success', $count === 1 ? '1 zone added.' : "{$count} zones added.");
    }

    public function edit(Branch $branch): Response
    {
        $this->authorize('update', $branch);

        return Inertia::render('Branches/Edit', [
            'branch' => $this->shapeBranch($branch),
        ]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->branches->update($branch, BranchData::fromArray($request->validated()));

        return redirect()->route('branches.index')->with('success', 'Branch updated.');
    }

    public function destroy(Branch $branch): RedirectResponse
    {
        $this->authorize('delete', $branch);

        try {
            $this->branches->delete($branch);
        } catch (ValidationException $e) {
            return redirect()->route('branches.index')->with('error', collect($e->errors())->flatten()->first());
        }

        return redirect()->route('branches.index')->with('success', 'Branch deleted.');
    }

    /**
     * @return array{uuid: string, name: string, is_headquarters: bool, zone_count: int|null}
     */
    private function shapeBranch(Branch $branch): array
    {
        return [
            'uuid' => $branch->uuid,
            'name' => $branch->name,
            'is_headquarters' => $branch->is_headquarters,
            'zone_count' => $branch->zones_count ?? null,
        ];
    }
}
