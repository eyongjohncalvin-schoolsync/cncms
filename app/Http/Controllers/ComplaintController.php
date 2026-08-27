<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\DataTransferObjects\ComplaintData;
use App\DataTransferObjects\ResolveComplaintData;
use App\Http\Requests\AssignComplaintRequest;
use App\Http\Requests\LinkDuplicateComplaintRequest;
use App\Http\Requests\NotifyInvestorsComplaintRequest;
use App\Http\Requests\ReopenComplaintRequest;
use App\Http\Requests\ResolveComplaintRequest;
use App\Http\Requests\StoreComplaintRequest;
use App\Models\Complaint;
use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Services\ComplaintEscalationService;
use App\Services\ComplaintService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Complaint Desk (references/complaint-desk.md). One route
 * (`/complaints`) serves both audiences via server-side role-based payload
 * shaping — same "same route, server decides payload/scope" idiom
 * ReportController already established (see that class's doc comment) —
 * rather than two separate controllers/routes per role.
 *
 * The escalation engine (the 48h clock, level 0-2 automatic broadcasts, the
 * Level 3 human gate) lives in App\Services\ComplaintEscalationService; this
 * controller's only involvement is show()'s "is Notify Investors visible"
 * flag and notifyInvestors()'s actual trigger.
 */
class ComplaintController extends Controller
{
    public function __construct(
        private readonly ComplaintService $complaints,
        private readonly CustomerRepositoryInterface $customers,
        private readonly TenantContext $context,
        private readonly ComplaintEscalationService $escalations,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Complaint::class);

        $perPage = (int) $request->integer('per_page', 20);
        $filters = $request->only(['status', 'category', 'urgent', 'sort', 'direction']);

        $complaints = $this->complaints->list($filters, $perPage);
        $raw = $complaints->toArray();

        // super/admin/manager land on the dashboard view (StatCard row +
        // full list); agent/worker land on the submission-first view (the
        // same list, but the page leads with "Log a Complaint" rather than
        // the counts row) — references/complaint-desk.md section 6.
        $isDashboardView = $this->context->isAnyOf('super', 'admin', 'manager');

        return Inertia::render('Complaints/Index', [
            'view' => $isDashboardView ? 'dashboard' : 'submission',
            'complaints' => [
                'data' => $complaints->getCollection()
                    ->map(fn (Complaint $complaint): array => $this->formatComplaint($complaint))
                    ->values()
                    ->all(),
                'links' => $raw['links'],
                'meta' => [
                    'current_page' => $raw['current_page'],
                    'per_page' => $raw['per_page'],
                    'total' => $raw['total'],
                    'last_page' => $raw['last_page'],
                ],
            ],
            'filters' => [
                'status' => $filters['status'] ?? null,
                'category' => $filters['category'] ?? null,
                'urgent' => $filters['urgent'] ?? null,
                'sort' => $filters['sort'] ?? 'created_at',
                'direction' => $filters['direction'] ?? 'desc',
            ],
            'stats' => $isDashboardView ? $this->complaints->dashboard() : null,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Complaint::class);

        // Same "small enough to hand the whole list to a client-side
        // searchable dropdown" rationale as PaymentController::create().
        $customers = $this->customers->allMatching([]);
        $customers->load('zone');

        return Inertia::render('Complaints/Create', [
            'customers' => $customers
                ->map(fn (Customer $customer): array => [
                    'uuid' => $customer->uuid,
                    'name' => $customer->name,
                    'phone' => $customer->phone,
                    'zone_name' => $customer->zone?->name,
                ])
                ->values(),
        ]);
    }

    public function store(StoreComplaintRequest $request): RedirectResponse
    {
        $this->complaints->create(ComplaintData::fromArray($request->validated()), $request->user()->id);

        return redirect()->route('complaints.index')->with('success', 'Complaint submitted.');
    }

    /**
     * GET /complaints/duplicates — the live, non-blocking duplicate check
     * behind the submission form's inline warning
     * (references/complaint-desk.md section 4.1). Plain background
     * fetch(), not an Inertia visit — same convention as
     * CustomerController::lastPayment(), which Payments/Create.tsx already
     * consumes the same way.
     */
    public function duplicates(Request $request): JsonResponse
    {
        $this->authorize('create', Complaint::class);

        $category = (string) $request->query('category', '');

        if (! in_array($category, ['operational', 'customer'], true)) {
            return response()->json(['complaints' => []]);
        }

        $customerUuid = $request->query('customer_uuid');
        $candidates = $this->complaints->possibleDuplicates($category, is_string($customerUuid) ? $customerUuid : null);

        return response()->json([
            'complaints' => $candidates->map(fn (Complaint $complaint): array => [
                'uuid' => $complaint->uuid,
                'title' => $complaint->title,
                'status' => $complaint->status,
                'submitted_by_name' => $complaint->submittedBy?->name,
                'created_at' => $complaint->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function show(Complaint $complaint): Response
    {
        $this->authorize('view', $complaint);

        $complaint = $this->complaints->findOrFail($complaint->uuid);

        // Single flag covers both Resolve and Reopen — ComplaintPolicy's
        // resolve() and reopen() are the identical rule (super/admin/
        // manager, never the submitter), just named for each action.
        $canManage = $this->context->isAnyOf('super', 'admin', 'manager')
            && $complaint->submitted_by !== $this->context->tenantUser->user_id;

        // The Level 3 human gate (references/complaint-desk.md section 3):
        // the button is only ever shown to super/admin, and only once the
        // complaint is genuinely armed (48h old, still unresolved) AND
        // hasn't already been fired — investorNoticeSentAt below tells the
        // frontend which of "not armed yet" / "armed, show the button" /
        // "already sent" to render.
        $investorNoticeRow = $complaint->escalations->firstWhere('level', 3);

        return Inertia::render('Complaints/Show', [
            'complaint' => $this->formatComplaint($complaint),
            'can_manage' => $canManage,
            'can_link_duplicate' => $this->context->isAnyOf('super', 'admin', 'manager'),
            'can_notify_investors' => $this->context->isAnyOf('super', 'admin')
                && $investorNoticeRow === null
                && $this->escalations->isInvestorNoticeArmed($complaint),
            'investor_notice_sent_at' => $investorNoticeRow?->escalated_at?->toIso8601String(),
        ]);
    }

    /**
     * The Level 3 human gate's actual trigger — see ComplaintPolicy::
     * notifyInvestors() (super/admin only) and ComplaintEscalationService::
     * notifyInvestors() (the 48h-armed business rule + idempotency).
     */
    public function notifyInvestors(NotifyInvestorsComplaintRequest $request, Complaint $complaint): RedirectResponse
    {
        $this->complaints->notifyInvestors($complaint);

        return back()->with('success', 'Investors notified.');
    }

    public function resolve(ResolveComplaintRequest $request, Complaint $complaint): RedirectResponse
    {
        $this->complaints->resolve($complaint, ResolveComplaintData::fromArray($request->validated()), $request->user()->id);

        return back()->with('success', 'Complaint resolved.');
    }

    public function reopen(ReopenComplaintRequest $request, Complaint $complaint): RedirectResponse
    {
        $this->complaints->reopen($complaint);

        return back()->with('success', 'Complaint reopened.');
    }

    public function linkDuplicate(LinkDuplicateComplaintRequest $request, Complaint $complaint): RedirectResponse
    {
        $this->complaints->linkDuplicate($complaint, $request->validated('duplicate_of_uuid'));

        return back()->with('success', 'Marked as a duplicate.');
    }

    public function assign(AssignComplaintRequest $request, Complaint $complaint): RedirectResponse
    {
        $this->complaints->assign($complaint, $request->validated('assignee_uuid'));

        return back()->with('success', 'Complaint assigned.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formatComplaint(Complaint $complaint): array
    {
        return [
            'uuid' => $complaint->uuid,
            'category' => $complaint->category,
            'title' => $complaint->title,
            'description' => $complaint->description,
            'urgent' => $complaint->urgent,
            'status' => $complaint->status,
            'customer_uuid' => $complaint->customer?->uuid,
            'customer_name' => $complaint->customer?->name,
            'zone_name' => $complaint->zone?->name ?? $complaint->customer?->zone?->name,
            'submitted_by_name' => $complaint->submittedBy?->name,
            'assigned_to_name' => $complaint->assignedTo?->name,
            'resolved_by_name' => $complaint->resolvedBy?->name,
            'resolved_at' => $complaint->resolved_at?->toIso8601String(),
            'resolution_notes' => $complaint->resolution_notes,
            'escalated_at' => $complaint->escalated_at?->toIso8601String(),
            'duplicate_of_uuid' => $complaint->duplicateOf?->uuid,
            'duplicate_of_title' => $complaint->duplicateOf?->title,
            'created_at' => $complaint->created_at?->toIso8601String(),
        ];
    }
}
