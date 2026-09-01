<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants (or revokes) platform-level landlord access for a central user —
 * `users.is_landlord`. This flag is deliberately NOT mass-assignable (see
 * App\Models\User) and there is no UI to set it, so a fresh install /
 * production deploy has zero landlords: this command is how the first one
 * is bootstrapped.
 *
 *   php artisan cncms:grant-landlord you@example.com
 *   php artisan cncms:grant-landlord you@example.com --revoke
 *
 * The user must already exist (register normally via /register first). On
 * Laravel Cloud, run it from the environment's command runner once after
 * the first deploy.
 */
class GrantLandlord extends Command
{
    protected $signature = 'cncms:grant-landlord
        {email : The central user\'s email address}
        {--revoke : Remove landlord access instead of granting it}';

    protected $description = 'Grant or revoke platform landlord access (users.is_landlord) for a user';

    public function handle(): int
    {
        $email = trim((string) $this->argument('email'));
        $revoke = (bool) $this->option('revoke');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error("No user with email [{$email}]. They must register at /register first.");

            return self::FAILURE;
        }

        if ($revoke) {
            if (! $user->is_landlord) {
                $this->info("{$user->name} <{$email}> is not a landlord — nothing to do.");

                return self::SUCCESS;
            }

            $user->forceFill([
                'is_landlord' => false,
                'landlord_granted_at' => null,
                'landlord_granted_by' => null,
            ])->save();

            $this->info("Revoked landlord access from {$user->name} <{$email}>.");

            return self::SUCCESS;
        }

        if ($user->is_landlord) {
            $this->info("{$user->name} <{$email}> already has landlord access.");

            return self::SUCCESS;
        }

        $user->forceFill([
            'is_landlord' => true,
            'landlord_granted_at' => now(),
            // No granting user in a CLI context — the audit trail is "granted
            // via the bootstrap command", which is what null here means.
            'landlord_granted_by' => null,
        ])->save();

        $this->info("Granted landlord access to {$user->name} <{$email}>. They can now reach /landlord/tenants.");

        return self::SUCCESS;
    }
}
