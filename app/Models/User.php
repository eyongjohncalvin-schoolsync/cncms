<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasUuid;
use App\Support\BusinessTimezone;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

// Central model — always lives in the `public` schema, regardless of which
// tenant schema is currently active on the default connection (see
// PostgreSQLSchemaManager: tenant search_path does not include `public`).
//
// `is_landlord`/`landlord_granted_by`/`landlord_granted_at` are
// deliberately NOT in the Fillable list below — this is a platform-wide
// authority flag (see app/Http/Middleware/EnsureLandlord.php) and must
// never be settable via ordinary mass-assignment (registration, profile
// updates, etc.). Grant it only via direct property assignment + save()
// in a dedicated, explicitly-audited admin action.
//
// The two_factor_* columns are likewise not fillable: only
// App\Services\TwoFactorService writes them, via forceFill().
#[Connection('pgsql')]
#[Fillable(['name', 'username', 'email', 'status', 'password', 'locale'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
#[RouteKey('uuid')]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuid, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'landlord_granted_at' => 'datetime',
            'is_landlord' => 'boolean',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_used_step' => 'integer',
        ];
    }

    /**
     * Authenticator-app two-factor is ON only once a code from the app has
     * confirmed the secret (App\Services\TwoFactorService::confirm()).
     */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * The account a sign-in form's "username or email" field names: an
     * email when it contains "@", a username otherwise.
     */
    public static function findForLogin(string $identifier): ?self
    {
        $identifier = trim($identifier);

        if ($identifier === '') {
            return null;
        }

        return self::query()
            ->where(str_contains($identifier, '@') ? 'email' : 'username', $identifier)
            ->first();
    }

    /**
     * The email with most of the local part hidden, e.g. "ke•••@example.com",
     * for telling someone where a code went without revealing the address.
     */
    public function maskedEmail(): string
    {
        [$local, $domain] = array_pad(explode('@', (string) $this->email, 2), 2, '');

        return mb_substr($local, 0, min(2, mb_strlen($local))).'•••@'.$domain;
    }

    /**
     * Passive (deactivated) accounts cannot sign in by any path.
     */
    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * Inside this person's quiet hours for phone alerts (Cameroon time;
     * the window may wrap past midnight, e.g. 22:00–06:00).
     */
    public function inQuietHours(?CarbonInterface $at = null): bool
    {
        if ($this->alert_quiet_start === null || $this->alert_quiet_end === null || $this->alert_quiet_start === $this->alert_quiet_end) {
            return false;
        }

        $now = ($at ?? now())->copy()->setTimezone(BusinessTimezone::WAT)->format('H:i');

        return $this->alert_quiet_start < $this->alert_quiet_end
            ? $now >= $this->alert_quiet_start && $now < $this->alert_quiet_end
            : $now >= $this->alert_quiet_start || $now < $this->alert_quiet_end;
    }
}
