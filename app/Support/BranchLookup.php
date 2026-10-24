<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Parent → branch_id memo for App\Models\Concerns\BelongsToBranch, so stamping
 * the branch on new rows never becomes an N+1 (docs/plans/branches-v2.md §6).
 *
 * A row's parent branch is looked up at most once per process; a bulk writer
 * that already holds the parents (or one query's worth of ids) calls
 * remember() / rememberIds() up front and pays nothing per row. Flushed when
 * the tenant changes or a zone moves branch; a customer or agent moving branch
 * forgets just its own entry — so a lookup is never stale.
 */
final class BranchLookup
{
    /** @var array<class-string<Model>, array<int, int>> */
    private static array $branchIds = [];

    /** @param  iterable<Model>  $parents  rows carrying their own branch_id */
    public static function remember(iterable $parents): void
    {
        foreach ($parents as $parent) {
            if ($parent->getAttribute('branch_id') !== null) {
                self::$branchIds[$parent::class][(int) $parent->getKey()] = (int) $parent->getAttribute('branch_id');
            }
        }
    }

    /**
     * @param  class-string<Model>  $parentClass
     * @param  array<int, int|string>  $branchIdsByKey  parent key => branch_id
     */
    public static function rememberIds(string $parentClass, array $branchIdsByKey): void
    {
        foreach ($branchIdsByKey as $key => $branchId) {
            self::$branchIds[$parentClass][(int) $key] = (int) $branchId;
        }
    }

    /**
     * Loads the branch of many parents known only by id — one query per
     * 5,000 ids (soft-deleted parents included: their history still needs
     * its branch).
     *
     * @param  class-string<Model>  $parentClass
     * @param  array<int, int|string>  $keys
     */
    public static function rememberFor(string $parentClass, array $keys): void
    {
        foreach (array_chunk(array_values(array_unique(array_map('intval', $keys))), 5000) as $chunk) {
            self::rememberIds($parentClass, $parentClass::query()->withoutGlobalScopes()->whereKey($chunk)->pluck('branch_id', 'id')->all());
        }
    }

    /** @param  class-string<Model>  $parentClass */
    public static function get(string $parentClass, int $key): ?int
    {
        return self::$branchIds[$parentClass][$key] ?? null;
    }

    /** @param  class-string<Model>  $parentClass */
    public static function forget(string $parentClass, int $key): void
    {
        unset(self::$branchIds[$parentClass][$key]);
    }

    public static function flush(): void
    {
        self::$branchIds = [];
    }
}
