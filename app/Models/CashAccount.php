<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuid;
use App\Traits\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\RouteKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place company money sits (docs/plans/cash-controls.md, wave 4): a
 * cashier's till, the safe, a bank account, or one of the mobile-money
 * wallets. Its balance is never stored — it is the sum of its
 * CashMovement rows (App\Services\CashBookService::balance()).
 */
#[Fillable(['kind', 'name', 'provider', 'account_number', 'holder_user_id', 'is_active', 'branch_id'])]
#[RouteKey('uuid')]
class CashAccount extends Model
{
    use Auditable, HasUuid;

    public const string KIND_TILL = 'till';

    public const string KIND_SAFE = 'safe';

    public const string KIND_BANK = 'bank';

    public const string KIND_MOBILE_MONEY = 'mobile_money';

    /** @var list<string> */
    public const array KINDS = [self::KIND_TILL, self::KIND_SAFE, self::KIND_BANK, self::KIND_MOBILE_MONEY];

    /** Payment::METHODS value → wallet provider. */
    public const array WALLET_PROVIDERS = ['mtn_momo' => 'mtn', 'orange_money' => 'orange'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    // Cross-schema — User is pinned to the central connection.
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /** A till's branch is its holder's when it was opened; wallets, safe and bank are headquarters'. */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isTill(): bool
    {
        return $this->kind === self::KIND_TILL;
    }
}
