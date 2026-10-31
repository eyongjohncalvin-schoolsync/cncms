<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One refused password sign-in (central schema) — written only by
 * App\Services\LoginGuard. `reason` is `password` (wrong password or
 * unknown account; these count towards a block) or `blocked` (tried while
 * blocked; never counted). Pruned after 90 days.
 */
#[Connection('pgsql')]
#[Fillable(['user_id', 'identifier', 'reason', 'channel', 'ip_address', 'user_agent', 'created_at', 'cleared_at'])]
class LoginFailure extends Model
{
    use Prunable;

    public const string WRONG_PASSWORD = 'password';

    public const string WHILE_BLOCKED = 'blocked';

    public const string WEB = 'web';

    public const string MOBILE = 'mobile';

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return Builder<self>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(90));
    }
}
