<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Support\GeneratesUsername;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Grants (or revokes) platform-level landlord access for a central user —
 * `users.is_landlord`. This flag is deliberately NOT mass-assignable (see
 * App\Models\User) and there is no UI to set it, so a fresh install /
 * production deploy has zero landlords: this command is how the first one
 * is bootstrapped.
 *
 *   # existing user (registered via /register)
 *   php artisan cncms:grant-landlord you@example.com
 *
 *   # fresh box — create the user AND grant in one step, no registration
 *   php artisan cncms:grant-landlord you@example.com --create --name="Your Name" --password="a-strong-one"
 *
 *   php artisan cncms:grant-landlord you@example.com --revoke
 *
 * A `--create`d landlord has NO workspace — that's fine, the landlord area
 * (/landlord/tenants) never resolves a tenant. Log in and you're taken
 * straight there (AuthController::store). On Laravel Cloud run this from
 * the environment's command runner.
 */
class GrantLandlord extends Command
{
    use GeneratesUsername;

    protected $signature = 'cncms:grant-landlord
        {email : The central user\'s email address}
        {--revoke : Remove landlord access instead of granting it}
        {--create : Create the user first if they do not exist}
        {--name= : Display name for --create (default: the email local-part)}
        {--password= : Password for --create (default: a random one, printed)}';

    protected $description = 'Grant or revoke platform landlord access (users.is_landlord); optionally create the user';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $revoke = (bool) $this->option('revoke');

        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            if ($revoke || ! $this->option('create')) {
                $this->error("No user with email [{$email}]. Register at /register first, or pass --create.");

                return self::FAILURE;
            }

            $name = trim((string) ($this->option('name') ?: mb_strstr($email, '@', true))) ?: 'Landlord';
            $plainPassword = (string) ($this->option('password') ?: bin2hex(random_bytes(6)));

            $user = new User([
                'name' => $name,
                'email' => $email,
                'status' => 'active',
            ]);
            $user->username = $this->generateUsername($email, $name);
            $user->password = $plainPassword; // hashed by the model cast
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->info("Created user {$name} <{$email}>".(
                $this->option('password') ? '.' : " with password: {$plainPassword}"
            ));
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

        $this->info("Granted landlord access to {$user->name} <{$email}>. Log in and you'll land on /landlord/tenants.");

        return self::SUCCESS;
    }
}
