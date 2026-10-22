<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A platform-wide maintenance window (central schema) — see the migration's
 * docblock and App\Support\MaintenanceMode.
 *
 * @property CarbonInterface $starts_at
 * @property CarbonInterface $ends_at
 * @property CarbonInterface|null $cancelled_at
 * @property CarbonInterface|null $ended_at
 */
#[Connection('pgsql')]
#[Fillable(['starts_at', 'ends_at', 'message', 'created_by', 'cancelled_at', 'ended_at'])]
class MaintenanceWindow extends Model
{
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scheduled or running: not cancelled, not ended early, and not yet past
     * its end time.
     *
     * @param  Builder<MaintenanceWindow>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('cancelled_at')
            ->whereNull('ended_at')
            ->where('ends_at', '>', now());
    }

    public function isActive(): bool
    {
        return $this->cancelled_at === null
            && $this->ended_at === null
            && $this->starts_at->lessThanOrEqualTo(now())
            && $this->ends_at->greaterThan(now());
    }

    /**
     * @return 'scheduled'|'active'|'finished'|'cancelled'
     */
    public function status(): string
    {
        return match (true) {
            $this->cancelled_at !== null => 'cancelled',
            $this->ended_at !== null, $this->ends_at->lessThanOrEqualTo(now()) => 'finished',
            $this->starts_at->greaterThan(now()) => 'scheduled',
            default => 'active',
        };
    }

    /**
     * The shape the Inertia `maintenance` prop, the maintenance page and
     * the API's 503 body all share.
     *
     * @return array{id: int, starts_at: string, ends_at: string, message: string|null, active: bool}
     */
    public function toNotice(): array
    {
        return [
            'id' => $this->id,
            'starts_at' => $this->starts_at->toIso8601String(),
            'ends_at' => $this->ends_at->toIso8601String(),
            'message' => $this->message,
            'active' => $this->isActive(),
        ];
    }
}
