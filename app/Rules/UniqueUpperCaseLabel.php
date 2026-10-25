<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\UpperCaseLabel;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Case-insensitive uniqueness for an upper-case label column (zone and
 * branch names): compares upper(column) = upper(normalised value), so a
 * legacy mixed-case row ("Thr01") still collides with "thr01"/"THR01".
 * Reads the raw table (no global scopes such as BranchScope), like the
 * `unique:table,column` rule it replaces. `$ignore` is the row being updated,
 * so renaming it to its own name in another case is allowed.
 */
class UniqueUpperCaseLabel implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly string $column = 'name',
        private readonly ?Model $ignore = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $taken = DB::table($this->table)
            ->whereRaw("upper({$this->column}) = upper(?)", [UpperCaseLabel::normalise($value)])
            ->when($this->ignore?->getKey() !== null, fn ($query) => $query->where($this->ignore->getKeyName(), '!=', $this->ignore->getKey()))
            ->exists();

        if ($taken) {
            $fail('validation.unique')->translate();
        }
    }
}
