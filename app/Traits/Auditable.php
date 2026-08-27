<?php

declare(strict_types=1);

namespace App\Traits;

use App\Observers\AuditableObserver;
use Illuminate\Database\Eloquent\Model;

/**
 * Add `use Auditable;` to any tenant model that should be tracked in the
 * comprehensive audit trail (audit-strategy.md sections 2/4.2). Registers
 * App\Observers\AuditableObserver so every created/updated/deleted event on
 * the model writes an immutable row to audit_logs — no call site ever needs
 * to remember to log manually.
 *
 * Deliberately NOT `static::observe(AuditableObserver::class)`: Eloquent's
 * observe() internally does `$instance = new static` to call the instance
 * method registerObserver() — and since this boots from bootAuditable(),
 * which runs during bootTraits() inside boot(), that `new static` re-enters
 * bootIfNotBooted() on the SAME model while static::$booting[static::class]
 * is still set, which Illuminate\Database\Eloquent\Model::bootIfNotBooted()
 * throws a LogicException for ("... may not be called on model [...] while
 * it is being booted."). Registering each event directly via
 * static::created()/updated()/deleted() (which only pushes a dispatcher
 * listener, no model instantiation) reaches the exact same
 * AuditableObserver methods without ever re-entering boot().
 */
trait Auditable
{
    public static function bootAuditable(): void
    {
        static::created(fn (Model $model) => app(AuditableObserver::class)->created($model));
        static::updated(fn (Model $model) => app(AuditableObserver::class)->updated($model));
        static::deleted(fn (Model $model) => app(AuditableObserver::class)->deleted($model));
    }
}
