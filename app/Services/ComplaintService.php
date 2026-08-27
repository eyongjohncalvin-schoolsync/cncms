<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\ComplaintData;
use App\DataTransferObjects\ResolveComplaintData;
use App\Models\Complaint;
use App\Models\Customer;
use App\Models\User;
use App\Repositories\Contracts\ComplaintRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Complaint Desk core service — see references/complaint-desk.md sections
 * 2-4 and 6. The escalation engine itself (section 3's 48h clock/levels,
 * the Level 3 human gate, the resolution/de-escalation notice) lives in
 * App\Services\ComplaintEscalationService, injected below and called from
 * resolve() and notifyInvestors() — kept as a separate class rather than
 * folded in here since it has its own distinct responsibility (threshold
 * math, fixed notification templates, the complaint_escalations log) that
 * the scheduler's task type also depends on independently of this service.
 */
class ComplaintService
{
    public function __construct(
        private readonly ComplaintRepositoryInterface $complaints,
        private readonly CustomerRepositoryInterface $customers,
        private readonly ComplaintEscalationService $escalations,
    ) {}

    /**
     * @param  array<string, mixed>  $filters  See ComplaintRepositoryInterface::paginate().
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->complaints->paginate($filters, $perPage);
    }

    public function findOrFail(string $uuid): Complaint
    {
        $complaint = $this->complaints->findByUuid($uuid, [
            'submittedBy', 'assignedTo', 'resolvedBy', 'customer.zone', 'zone', 'duplicateOf', 'escalations',
        ]);

        if (! $complaint) {
            throw new ModelNotFoundException("Complaint [{$uuid}] not found.");
        }

        return $complaint;
    }

    /**
     * customer_id/zone_id are never taken from client input (see
     * ComplaintData's class doc) — derived here per
     * references/complaint-desk.md section 2: the customer's own zone for
     * `category = 'customer'`, or the submitting agent's own zone (via
     * TenantContext::currentZoneId(), null for every non-agent role) for
     * `category = 'operational'`.
     */
    public function create(ComplaintData $data, int $submittedByUserId): Complaint
    {
        $customerId = null;
        $zoneId = null;

        if ($data->category === 'customer') {
            $customer = $this->resolveCustomer($data->customerUuid);
            $customerId = $customer->id;
            $zoneId = $customer->zone_id;
        } else {
            $zoneId = TenantContext::currentZoneId();
        }

        $complaint = $this->complaints->create($submittedByUserId, [
            ...$data->toAttributes(),
            'customer_id' => $customerId,
            'zone_id' => $zoneId,
        ]);

        return $complaint->load(['submittedBy', 'customer.zone', 'zone']);
    }

    /**
     * Submission-time soft duplicate guard (references/complaint-desk.md
     * section 4.1) — best-effort triage, never blocking. Derives the same
     * zone_id/customer_id create() would, so the candidates shown match
     * exactly what would actually get flagged.
     *
     * @return Collection<int, Complaint>
     */
    public function possibleDuplicates(string $category, ?string $customerUuid): Collection
    {
        $customerId = null;
        $zoneId = null;

        if ($category === 'customer') {
            if (! $customerUuid) {
                return collect();
            }

            $customer = $this->customers->findByUuid($customerUuid);
            $customerId = $customer?->id;

            if ($customerId === null) {
                return collect();
            }
        } else {
            $zoneId = TenantContext::currentZoneId();
        }

        return $this->complaints->possibleDuplicates($category, $zoneId, $customerId)->load('submittedBy');
    }

    /**
     * ComplaintPolicy::resolve() has already checked the caller is
     * super/admin/manager AND not the original submitter — this method
     * trusts that check happened (same "authorization is the Policy's job,
     * not the Service's" split as every other Service in this app) and only
     * enforces the data-shape rule: resolution_notes is required non-empty,
     * already validated by ResolveComplaintRequest before this runs.
     */
    public function resolve(Complaint $complaint, ResolveComplaintData $data, int $resolvedByUserId): Complaint
    {
        $resolved = $this->complaints->update($complaint, [
            'status' => 'resolved',
            'resolved_at' => now(),
            'resolved_by' => $resolvedByUserId,
            'resolution_notes' => $data->resolutionNotes,
        ]);

        // De-escalation notice (references/complaint-desk.md section 3) —
        // sent AFTER the resolve write commits, addressed only to whatever
        // audiences complaint_escalations actually shows were notified for
        // this complaint (nobody, if it never escalated at all).
        $this->escalations->sendResolutionNotice($resolved);

        return $resolved;
    }

    /**
     * The Level 3 human gate's actual trigger (ComplaintPolicy::
     * notifyInvestors() — super/admin only, narrower than resolve()/
     * reopen()'s super/admin/manager). ComplaintEscalationService enforces
     * the 48h-armed business rule and idempotency; this method's only job is
     * to hand off to it, same "authorization is the Policy's job" split as
     * every other action in this service.
     */
    public function notifyInvestors(Complaint $complaint): void
    {
        $this->escalations->notifyInvestors($complaint);
    }

    /**
     * Deliberately does NOT touch `created_at` — the 48h escalation clock
     * (references/complaint-desk.md section 3) is never reset by reopening,
     * so a wrongly-resolved complaint that gets reopened immediately shows
     * as already-overdue rather than getting a fresh grace period. Clears
     * resolution_notes back to null (the column is nullable specifically
     * for this) rather than leaving stale notes on a now-open complaint.
     */
    public function reopen(Complaint $complaint): Complaint
    {
        return $this->complaints->update($complaint, [
            'status' => 'open',
            'resolved_at' => null,
            'resolved_by' => null,
            'resolution_notes' => null,
        ]);
    }

    /**
     * Manager-tier action (ComplaintPolicy::linkDuplicate()) — links this
     * complaint as a duplicate of an existing one. A linked duplicate is
     * excluded from ComplaintRepositoryInterface::openForEscalationSweep()
     * (it rides on the original's clock instead) but stays fully visible
     * and audit-tracked, per references/complaint-desk.md section 4.2.
     */
    public function linkDuplicate(Complaint $complaint, string $duplicateOfUuid): Complaint
    {
        $original = $this->complaints->findByUuid($duplicateOfUuid);

        if (! $original) {
            throw ValidationException::withMessages(['duplicate_of_uuid' => ['The selected complaint does not exist.']]);
        }

        if ($original->id === $complaint->id) {
            throw ValidationException::withMessages(['duplicate_of_uuid' => ['A complaint cannot be marked a duplicate of itself.']]);
        }

        return $this->complaints->update($complaint, ['duplicate_of_id' => $original->id]);
    }

    /**
     * Purely metadata ("I've got this" — see Complaint's class doc);
     * nothing behaves differently based on whether assigned_to is set,
     * except that the escalation engine's Level 0 audience
     * (references/complaint-desk.md section 3's table) is the assignee +
     * their manager, once that work lands.
     */
    public function assign(Complaint $complaint, string $assigneeUuid): Complaint
    {
        $assignee = User::query()->where('uuid', $assigneeUuid)->first();

        if (! $assignee) {
            throw ValidationException::withMessages(['assignee_uuid' => ['The selected user does not exist.']]);
        }

        return $this->complaints->update($complaint, ['assigned_to' => $assignee->id]);
    }

    /**
     * @return array{open: int, approaching_deadline: int, escalated: int, resolved_this_week: int}
     */
    public function dashboard(): array
    {
        return $this->complaints->dashboardCounts();
    }

    private function resolveCustomer(?string $customerUuid): Customer
    {
        if (! $customerUuid) {
            throw ValidationException::withMessages(['customer_uuid' => ['The customer_uuid field is required for a customer complaint.']]);
        }

        $customer = $this->customers->findByUuid($customerUuid);

        if (! $customer) {
            throw ValidationException::withMessages(['customer_uuid' => ['The selected customer does not exist.']]);
        }

        return $customer;
    }
}
