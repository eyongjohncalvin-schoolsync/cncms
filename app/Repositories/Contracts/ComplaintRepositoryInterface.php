<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Complaint;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface ComplaintRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $filters  Supported keys: 'status',
     *                                         'category', 'urgent' (bool),
     *                                         'sort' ('created_at'|'title',
     *                                         default 'created_at'), 'direction'
     *                                         ('asc'|'desc', default 'desc').
     *                                         Escalated complaints (escalated_at
     *                                         not null) always sort first,
     *                                         regardless of 'sort'/'direction' —
     *                                         see references/complaint-desk.md
     *                                         section 6's "pins to top of any
     *                                         list regardless of sort" rule.
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;

    public function findByUuid(string $uuid, array $with = []): ?Complaint;

    /**
     * @param  array<string, mixed>  $attributes  Everything from
     *                                            ComplaintData::toAttributes()
     *                                            plus the service-resolved
     *                                            customer_id/zone_id.
     */
    public function create(int $submittedBy, array $attributes): Complaint;

    public function update(Complaint $complaint, array $attributes): Complaint;

    /**
     * Counts backing the manager/admin dashboard's StatCard row
     * (references/complaint-desk.md section 6). 'approaching_deadline' and
     * 'escalated' are real queries over columns that already exist
     * (escalated_at, created_at) — they read as 0 today only because
     * nothing yet WRITES escalated_at (the escalation engine is out of
     * scope for this pass), not because the query itself is a placeholder.
     *
     * @return array{open: int, approaching_deadline: int, escalated: int, resolved_this_week: int}
     */
    public function dashboardCounts(): array;

    /**
     * Open/in_progress complaints not linked as a duplicate of another
     * complaint, oldest first — the exact result set the
     * `complaint_escalation_check` scheduler task type
     * (references/task-scheduler.md section 5, not built in this pass) will
     * sweep once it exists. Ordered/filterable via the (status, created_at)
     * composite index created alongside this table.
     *
     * @return Collection<int, Complaint>
     */
    public function openForEscalationSweep(): Collection;

    /**
     * Existing OPEN complaints matching the submission-time duplicate guard
     * (references/complaint-desk.md section 4.1): same zone_id + category
     * for 'operational', or same customer_id for 'customer', opened within
     * the last 7 days. Complaints already linked as someone else's
     * duplicate are excluded — linking rides on the original, not a fresh
     * chain of its own duplicates.
     *
     * @return Collection<int, Complaint>
     */
    public function possibleDuplicates(string $category, ?int $zoneId, ?int $customerId): Collection;
}
