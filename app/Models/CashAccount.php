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
 * Somewhere money sits: a person's till, the office safe, petty cash, the
 * bank, or a mobile-money account. `balance` is a cache of the ledger
 * (CashLedgerService keeps it in step and re-checks it nightly).
 */
#[Fillable(['type', 'name', 'holder_user_id', 'branch_id', 'max_hold', 'is_active'])]
#[RouteKey('uuid')]
class CashAccount extends Model
{
    use Auditable, HasUuid;

    public const string TYPE_TILL = 'till';

    public const string TYPE_SAFE = 'safe';

    public const string TYPE_PETTY_CASH = 'petty_cash';

    public const string TYPE_BANK = 'bank';

    public const string TYPE_MTN_MOMO = 'mtn_momo';

    public const string TYPE_ORANGE_MONEY = 'orange_money';

    public const array TYPES = [self::TYPE_TILL, self::TYPE_SAFE, self::TYPE_PETTY_CASH, self::TYPE_BANK, self::TYPE_MTN_MOMO, self::TYPE_ORANGE_MONEY];

    /** Physical cash someone is holding (vs money already in the bank / MoMo). */
    public const array CASH_TYPES = [self::TYPE_TILL, self::TYPE_SAFE, self::TYPE_PETTY_CASH];

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'max_hold' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function isCash(): bool
    {
        return in_array($this->type, self::CASH_TYPES, true);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function entries(): HasMany
    {
        return $this->hasMany(CashLedgerEntry::class, 'account_id');
    }

    public function closes(): HasMany
    {
        return $this->hasMany(CashClose::class, 'account_id');
    }
}
