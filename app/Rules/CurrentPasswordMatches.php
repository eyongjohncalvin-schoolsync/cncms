<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Hash;

/**
 * The value is the signed-in user's current password. Works for both the
 * web session and Sanctum tokens (Laravel's built-in `current_password`
 * rule only checks one named guard).
 */
class CurrentPasswordMatches implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = request()->user();

        if (! $user || ! is_string($value) || ! Hash::check($value, $user->password)) {
            $fail('The password is incorrect.');
        }
    }
}
