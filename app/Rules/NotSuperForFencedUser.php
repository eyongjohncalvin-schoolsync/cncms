<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Role;
use App\Support\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Branches v2 (D7): someone fenced to a branch may hire and promote within it,
 * but never hand out the owner (super) role — it bypasses every check,
 * other branches included.
 */
class NotSuperForFencedUser implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! app(TenantContext::class)->isFenced()) {
            return;
        }

        if (Role::query()->where('name', $value)->where('is_super', true)->exists()) {
            $fail('Only headquarters can give someone the owner role.');
        }
    }
}
