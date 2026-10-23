<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A one-time 6-digit code sent by email (central schema) — see the
 * migration's docblock. Issued and checked only by
 * App\Services\SecurityCodeService.
 *
 * @property string $purpose
 * @property string|null $email
 * @property int $attempts
 * @property Carbon $expires_at
 * @property Carbon|null $consumed_at
 */
#[Connection('pgsql')]
#[Fillable(['user_id', 'purpose', 'code_hash', 'email', 'attempts', 'expires_at', 'consumed_at'])]
class SecurityCode extends Model
{
    public const string PURPOSE_LOGIN = 'login';

    public const string PURPOSE_TWO_FACTOR = 'two_factor';

    public const string PURPOSE_PASSWORD_RESET = 'password_reset';

    public const string PURPOSE_EMAIL_CHANGE = 'email_change';

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'attempts' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
