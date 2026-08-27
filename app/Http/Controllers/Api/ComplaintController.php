<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\DataTransferObjects\ComplaintData;
use App\DataTransferObjects\ResolveComplaintData;
use App\Http\Controllers\Controller;
use App\Http\Requests\AssignComplaintRequest;
use App\Http\Requests\LinkDuplicateComplaintRequest;
use App\Http\Requests\ReopenComplaintRequest;
use App\Http\Requests\ResolveComplaintRequest;
use App\Http\Requests\StoreComplaintRequest;
use App\Http\Resources\ComplaintResource;
use App\Models\Complaint;
use App\Services\ComplaintService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * JSON API counterpart of the web Complaint Desk controller
 * (App\Http\Controllers\ComplaintController) — same ComplaintService, same
 * FormRequests/Policy, just returning JSON instead of Inertia responses.
 * Mirrors Api\ExpenditureController's shape. This is also the surface a
 * future mobile submission screen (references/complaint-desk.md section 7,
 * out of scope for this pass) would call.
 */
class ComplaintController extends Controller
{
    public function __construct(
        private readonly ComplaintService $complaints,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Complaint::class);

        $perPage = (int) $request->integer('per_page', 25);
        $filters = $request->only(['status', 'category', 'urgent', 'sort', 'direction']);

        $complaints = $this->complaints->list($filters, $perPage);
        $complaints->getCollection()->load(['submittedBy', 'assignedTo', 'customer.zone', 'zone']);

        return ComplaintResource::collection($complaints)->response();
    }

    public function store(StoreComplaintRequest $request): JsonResponse
    {
        $complaint = $this->complaints->create(ComplaintData::fromArray($request->validated()), $request->user()->id);

        return (new ComplaintResource($complaint))->response()->setStatusCode(201);
    }

    public function show(Complaint $complaint): JsonResponse
    {
        $this->authorize('view', $complaint);

        return (new ComplaintResource($this->complaints->findOrFail($complaint->uuid)))->response();
    }

    public function resolve(ResolveComplaintRequest $request, Complaint $complaint): JsonResponse
    {
        $complaint = $this->complaints->resolve(
            $complaint,
            ResolveComplaintData::fromArray($request->validated()),
            $request->user()->id,
        );

        return (new ComplaintResource($complaint->load(['submittedBy', 'resolvedBy'])))->response();
    }

    public function reopen(ReopenComplaintRequest $request, Complaint $complaint): JsonResponse
    {
        $complaint = $this->complaints->reopen($complaint);

        return (new ComplaintResource($complaint->load(['submittedBy'])))->response();
    }

    public function linkDuplicate(LinkDuplicateComplaintRequest $request, Complaint $complaint): JsonResponse
    {
        $complaint = $this->complaints->linkDuplicate($complaint, $request->validated('duplicate_of_uuid'));

        return (new ComplaintResource($complaint->load(['duplicateOf'])))->response();
    }

    public function assign(AssignComplaintRequest $request, Complaint $complaint): JsonResponse
    {
        $complaint = $this->complaints->assign($complaint, $request->validated('assignee_uuid'));

        return (new ComplaintResource($complaint->load(['assignedTo'])))->response();
    }

    /**
     * GET /api/v1/complaints/duplicates — see the web controller's
     * duplicates() for the full rationale (references/complaint-desk.md
     * section 4.1).
     */
    public function duplicates(Request $request): JsonResponse
    {
        $this->authorize('create', Complaint::class);

        $category = (string) $request->query('category', '');

        if (! in_array($category, ['operational', 'customer'], true)) {
            return response()->json(['data' => []]);
        }

        $customerUuid = $request->query('customer_uuid');
        $candidates = $this->complaints->possibleDuplicates($category, is_string($customerUuid) ? $customerUuid : null);

        return ComplaintResource::collection($candidates)->response();
    }
}
