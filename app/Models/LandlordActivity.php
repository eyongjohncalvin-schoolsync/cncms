<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\Impersonation;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One platform-level landlord action (central schema) — see the migration's
 * docblock. Written only through record(), from the landlord controllers
 * and the CLI commands that act as a landlord (cncms:grant-landlord,
 * tenant:import). A null user means the action came from the command line.
 */
#[Connection('pgsql')]
#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'summary', 'details', 'ip_address'])]
class LandlordActivity extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $details
     */
    public static function record(
        string $action,
        string $summary,
        ?string $subjectType = null,
        string|int|null $subjectId = null,
        ?array $details = null,
        ?User $user = null,
    ): self {
        // A web action is attributed to the signed-in landlord and their IP;
        // the CLI has neither. While impersonating, the signed-in user is
        // the workspace user — the real actor is the impersonating landlord.
        $actor = $user ?? Impersonation::impersonator() ?? auth()->user();

        return self::query()->create([
            'user_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId === null ? null : (string) $subjectId,
            'summary' => mb_substr($summary, 0, 255),
            'details' => $details,
            'ip_address' => auth()->check() ? request()->ip() : null,
        ]);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
