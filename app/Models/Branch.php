<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUpperCaseLabels;
use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A town the company operates in. Exactly one branch is headquarters
 * (is_headquarters, docs/plans/branches-v2.md): HQ costs, staff and accounts
 * belong to it, and it can never be deleted.
 *
 * A branch may override headquarters' contact details, MoMo number and fees
 * (OVERRIDABLE — null = inherit); App\Models\Company::forBranch() applies
 * them.
 */
#[Fillable([
    'name', 'code', 'location', 'phone', 'email', 'tech_number', 'momo_number', 'momo_name',
    'reconnection_fine', 'relocation_fee', 'reinstallation_fee', 'sends_to_hq', 'agent_customer_limit',
])]
#[RouteKey('uuid')]
class Branch extends Model
{
    use Auditable, HasUpperCaseLabels, HasUuid;

    /** Company settings a branch may override; same column names on both tables. */
    /** How often a branch sends its money to headquarters (branches v2 D6). */
    public const array SENDS_TO_HQ = ['off', 'weekly', 'monthly'];

    public const array OVERRIDABLE = [
        'location', 'phone', 'email', 'tech_number', 'momo_number', 'momo_name',
        'reconnection_fine', 'relocation_fee', 'reinstallation_fee',
        // Customers per field agent before the Zone coverage screen says "busy".
        'agent_customer_limit',
    ];

    /**
     * Per-process memo of the tenant's branches (a handful of rows), keyed by
     * tenant — read on many writes (headquartersId()) and on every document
     * (Company::forBranch()). Dropped when a branch is saved or deleted and
     * on every tenant switch (AppServiceProvider).
     *
     * @var array<string, Collection<int, Branch>>
     */
    private static array $memo = [];

    protected static function booted(): void
    {
        // Company::forBranch() views are built from these rows.
        static::saved(static fn () => [self::flushMemo(), Company::flushMemo()]);
        static::deleted(static fn () => [self::flushMemo(), Company::flushMemo()]);
    }

    /** Branch names are stored upper-case (see HasUpperCaseLabels); `location` is not. */
    protected function upperCaseLabels(): array
    {
        return ['name'];
    }

    protected function casts(): array
    {
        return [
            'is_headquarters' => 'boolean',
            'reconnection_fine' => 'decimal:2',
            'relocation_fee' => 'decimal:2',
            'reinstallation_fee' => 'decimal:2',
        ];
    }

    /** @return Collection<int, Branch> keyed by id */
    public static function allMemoized(): Collection
    {
        $key = (string) (tenant()?->getTenantKey() ?? 'central');

        return self::$memo[$key] ??= static::query()->get()->keyBy('id');
    }

    public static function flushMemo(): void
    {
        self::$memo = [];
    }

    public static function headquartersId(): ?int
    {
        $id = self::allMemoized()->firstWhere('is_headquarters', true)?->id;

        return $id !== null ? (int) $id : null;
    }

    /** @return array<string, mixed> the overrides this branch sets (non-empty values only) */
    public function overrides(): array
    {
        return array_filter($this->only(self::OVERRIDABLE), fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function zones(): HasMany
    {
        return $this->hasMany(Zone::class);
    }

    public function companies(): HasMany
    {
        return $this->hasMany(Company::class);
    }
}
