<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\BranchData;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use App\Services\BranchService;
use Illuminate\Http\RedirectResponse;
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
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Branch::class);

        return Inertia::render('Branches/Create');
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        $this->branches->create(BranchData::fromArray($request->validated()));

        return redirect()->route('branches.index')->with('success', 'Branch created.');
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
     * @return array{uuid: string, name: string, zone_count: int|null}
     */
    private function shapeBranch(Branch $branch): array
    {
        return [
            'uuid' => $branch->uuid,
            'name' => $branch->name,
            'zone_count' => $branch->zones_count ?? null,
        ];
    }
}
