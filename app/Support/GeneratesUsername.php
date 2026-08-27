<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * Self-service signup (classic and Google) never asks for a username —
 * only Settings > Users (App\Http\Requests\StoreTenantUserRequest) collects
 * one explicitly from an admin. `users.username` is required+unique
 * (database/migrations/2026_08_20_040701_add_uuid_username_status_to_users_table.php),
 * so both App\Http\Controllers\RegisterController and
 * App\Http\Controllers\GoogleAuthController need to derive one automatically
 * when creating a central User row. Shared here so the derivation logic
 * (and its collision-handling) lives in exactly one place.
 */
trait GeneratesUsername
{
    private function generateUsername(string $email, string $name): string
    {
        $base = Str::slug(Str::before($email, '@'), '');

        if ($base === '') {
            $base = Str::slug($name, '');
        }

        if ($base === '') {
            $base = 'user';
        }

        $base = substr($base, 0, 45);

        $username = $base;
        $suffix = 1;

        while (User::query()->where('username', $username)->exists()) {
            $username = substr($base, 0, 45).$suffix;
            $suffix++;
        }

        return $username;
    }
}
