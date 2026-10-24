<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use App\Models\Concerns\HasUuid;
use App\Models\Scopes\BranchScope;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['customer_id', 'content', 'sid', 'status', 'type', 'channel'])]
#[RouteKey('uuid')]
class Message extends Model
{
    use Auditable, BelongsToBranch, HasUuid;

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScope(BranchScope::class);
    }

    /** Its branch comes from its customer (BelongsToBranch). */
    protected function branchParent(): ?string
    {
        return 'customer';
    }

    /** Customer history: stays whole for its customer; fenced on lists and links instead (BelongsToBranch). */
    protected static function fencedByBranchScope(): bool
    {
        return false;
    }
}
