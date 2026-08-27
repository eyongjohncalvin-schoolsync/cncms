<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Complaint;
use App\Repositories\Contracts\ComplaintRepositoryInterface;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ComplaintRepository implements ComplaintRepositoryInterface
{
    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        $sort = in_array($filters['sort'] ?? null, ['created_at', 'title'], true) ? $filters['sort'] : 'created_at';
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';

        return $this->scoped($filters)
            ->with(['submittedBy', 'assignedTo', 'customer.zone', 'zone'])
            // Escalated complaints pin to the top of any list regardless of
            // the chosen sort — references/complaint-desk.md section 6.
            // `escalated_at IS NULL` sorts non-escalated (true=1) after
            // escalated (false=0) ascending.
            ->orderByRaw('(escalated_at IS NULL) asc')
            ->orderBy($sort, $direction)
            ->paginate($perPage);
    }

    public function findByUuid(string $uuid, array $with = []): ?Complaint
    {
        return Complaint::query()->with($with)->where('uuid', $uuid)->first();
    }

    /**
     * `submitted_by` is deliberately absent from Complaint's #[Fillable]
     * list (see the model's class doc) — mass-assigning it through
     * Complaint::query()->create([...]) would silently drop it (Eloquent
     * mass assignment ignores non-fillable keys rather than throwing), so
     * it's set via direct property assignment instead, same shape as
     * PaymentVerification's verified_by writes elsewhere in this codebase.
     */
    public function create(int $submittedBy, array $attributes): Complaint
    {
        $complaint = new Complaint($attributes);
        $complaint->submitted_by = $submittedBy;
        $complaint->save();

        return $complaint;
    }

    public function update(Complaint $complaint, array $attributes): Complaint
    {
        $complaint->update($attributes);

        return $complaint;
    }

    public function dashboardCounts(): array
    {
        $open = Complaint::query()->where('status', '!=', 'resolved')->count();

        // Real query, zero rows today — nothing writes escalated_at yet
        // (the escalation engine is a separate, later build). Once it does,
        // this count updates with no code change here.
        $escalated = Complaint::query()->whereNotNull('escalated_at')->where('status', '!=', 'resolved')->count();

        // "Approaching deadline" per references/complaint-desk.md section
        // 6's table = the same 36h+ threshold the yellow Badge state uses
        // (see resources/tsx/lib/complaintState.ts), applied here in SQL:
        // open/in_progress, not yet escalated, created 36h+ ago.
        $approachingDeadline = Complaint::query()
            ->where('status', '!=', 'resolved')
            ->whereNull('escalated_at')
            ->where('created_at', '<=', Carbon::now()->subHours(36))
            ->count();

        $resolvedThisWeek = Complaint::query()
            ->where('status', 'resolved')
            ->where('resolved_at', '>=', Carbon::now()->startOfWeek())
            ->count();

        return [
            'open' => $open,
            'approaching_deadline' => $approachingDeadline,
            'escalated' => $escalated,
            'resolved_this_week' => $resolvedThisWeek,
        ];
    }

    public function openForEscalationSweep(): Collection
    {
        return Complaint::query()
            ->where('status', '!=', 'resolved')
            ->whereNull('duplicate_of_id')
            ->orderBy('created_at')
            ->get();
    }

    public function possibleDuplicates(string $category, ?int $zoneId, ?int $customerId): Collection
    {
        return Complaint::query()
            ->where('status', '!=', 'resolved')
            ->whereNull('duplicate_of_id')
            ->where('category', $category)
            ->where('created_at', '>=', Carbon::now()->subDays(7))
            ->when(
                $category === 'customer',
                fn (Builder $query) => $query->when($customerId, fn (Builder $inner) => $inner->where('customer_id', $customerId), fn (Builder $inner) => $inner->whereRaw('1 = 0')),
                fn (Builder $query) => $query->when($zoneId, fn (Builder $inner) => $inner->where('zone_id', $zoneId), fn (Builder $inner) => $inner->whereRaw('1 = 0')),
            )
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function scoped(array $filters): Builder
    {
        return Complaint::query()
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('category', $category))
            ->when(
                array_key_exists('urgent', $filters) && $filters['urgent'] !== null,
                fn (Builder $query) => $query->where('urgent', (bool) $filters['urgent'])
            );
    }
}
