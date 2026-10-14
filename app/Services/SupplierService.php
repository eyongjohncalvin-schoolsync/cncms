<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Suppliers (payees). A new supplier stays `pending` until someone other
 * than the person who added it approves it — nobody can create a payee
 * and pay it on their own. A supplier whose phone or MoMo number belongs
 * to a staff member is flagged, because paying "a supplier" that is really
 * an employee is a classic way to move money out.
 */
class SupplierService
{
    /**
     * @return Collection<int, Supplier>
     */
    public function list(bool $activeOnly = false): Collection
    {
        return Supplier::query()
            ->with(['creator', 'approver'])
            ->withCount('expenditures')
            ->when($activeOnly, fn ($query) => $query->where('status', Supplier::STATUS_ACTIVE))
            ->orderByRaw("case status when 'pending' then 0 when 'active' then 1 else 2 end")
            ->orderBy('name')
            ->get();
    }

    /**
     * @param  array{name: string, phone?: ?string, momo_number?: ?string, location?: ?string, notes?: ?string}  $attributes
     */
    public function create(array $attributes, User $actor): Supplier
    {
        $this->assertNameFree($attributes['name']);

        return Supplier::query()->create([
            ...$attributes,
            'status' => Supplier::STATUS_PENDING,
            'created_by' => $actor->id,
        ]);
    }

    /**
     * Editing contact details sends an active supplier back for approval:
     * a changed MoMo number is exactly how a payee gets redirected.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function update(Supplier $supplier, array $attributes, User $actor): Supplier
    {
        if (isset($attributes['name']) && mb_strtolower($attributes['name']) !== mb_strtolower($supplier->name)) {
            $this->assertNameFree($attributes['name'], $supplier->id);
        }

        $supplier->fill($attributes);

        if ($supplier->isDirty(['phone', 'momo_number', 'name']) && $supplier->status === Supplier::STATUS_ACTIVE) {
            $supplier->forceFill(['status' => Supplier::STATUS_PENDING, 'approved_by' => null, 'approved_at' => null, 'created_by' => $actor->id]);
        }

        $supplier->save();

        return $supplier;
    }

    public function approve(Supplier $supplier, User $actor): Supplier
    {
        if ($supplier->status !== Supplier::STATUS_PENDING) {
            throw ValidationException::withMessages(['supplier' => ['This supplier is not waiting for approval.']]);
        }

        if ($supplier->created_by === $actor->id) {
            throw ValidationException::withMessages(['supplier' => ['A different person must approve a supplier you added or changed.']]);
        }

        $supplier->forceFill(['status' => Supplier::STATUS_ACTIVE, 'approved_by' => $actor->id, 'approved_at' => Carbon::now()])->save();

        return $supplier;
    }

    public function deactivate(Supplier $supplier): Supplier
    {
        $supplier->forceFill(['status' => Supplier::STATUS_INACTIVE])->save();

        return $supplier;
    }

    /**
     * Staff names whose phone matches this supplier's phone or MoMo number.
     *
     * @return list<string>
     */
    public function staffMatches(Supplier $supplier): array
    {
        $numbers = array_values(array_filter(array_map(
            fn (?string $number): ?string => $this->digits($number),
            [$supplier->phone, $supplier->momo_number],
        )));

        if ($numbers === []) {
            return [];
        }

        return Agent::query()
            ->whereNotNull('phone')
            ->get(['name', 'phone'])
            ->filter(fn (Agent $agent): bool => in_array($this->digits($agent->phone), $numbers, true))
            ->pluck('name')
            ->values()
            ->all();
    }

    /** Last 9 digits — Cameroon numbers with or without +237. */
    private function digits(?string $number): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $number);

        return $digits === '' ? null : substr($digits, -9);
    }

    private function assertNameFree(string $name, ?int $ignoreId = null): void
    {
        $taken = Supplier::query()
            ->whereRaw('lower(name) = ?', [mb_strtolower(trim($name))])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => ['A supplier with this name already exists.']]);
        }
    }
}
