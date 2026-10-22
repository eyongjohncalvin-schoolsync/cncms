<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A pending second-factor login (central schema) — see the migration's
 * docblock and App\Services\LoginChallengeService.
 *
 * @property int $user_id
 * @property string $first_factor
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 * @property-read User $user
 */
#[Connection('pgsql')]
#[Fillable(['user_id', 'token_hash', 'first_factor', 'attempts', 'expires_at', 'consumed_at'])]
class LoginChallenge extends Model
{
    /** The first factor was the account password (or Google). */
    public const string FACTOR_PASSWORD = 'password';

    /** The first factor was a code sent to the account's email. */
    public const string FACTOR_EMAIL_CODE = 'email_code';

    public const string METHOD_TOTP = 'totp';

    public const string METHOD_RECOVERY = 'recovery';

    public const string METHOD_EMAIL = 'email';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * Second-factor methods this challenge accepts. An email code can only
     * ever be ONE of the two factors: after an email-code first factor the
     * second must be the authenticator app (or a recovery code).
     *
     * @return list<string>
     */
    public function methods(): array
    {
        return self::methodsFor($this->first_factor);
    }

    /**
     * @return list<string>
     */
    public static function methodsFor(string $firstFactor): array
    {
        return $firstFactor === self::FACTOR_EMAIL_CODE
            ? [self::METHOD_TOTP, self::METHOD_RECOVERY]
            : [self::METHOD_TOTP, self::METHOD_RECOVERY, self::METHOD_EMAIL];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
