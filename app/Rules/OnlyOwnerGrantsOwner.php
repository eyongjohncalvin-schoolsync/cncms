<?php

declare(strict_types=1);

namespace App\Rules;

use App\Models\Role;
use App\Support\TenantContext;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The owner (super) role bypasses every check, so only an owner hands it
 * out (security fix, 2026-11-02 — before, anyone with `users.manage` could
 * make themselves or someone else owner). An owner fenced to one branch
 * still can't (branches v2 D7): it reaches other branches too.
 */
class OnlyOwnerGrantsOwner implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! Role::query()->where('name', $value)->where('is_super', true)->exists()) {
            return;
        }

        $context = app(TenantContext::class);

        if ($context->isFenced()) {
            $fail('Only headquarters can give someone the owner role.');
        } elseif (! $context->isSuper()) {
            $fail('Only an owner can give someone the owner role.');
        }
    }
}
