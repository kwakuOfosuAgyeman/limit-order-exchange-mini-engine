<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BalanceLedger extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'balance_ledger';

    /**
     * Indicates if the model should be timestamped.
     */
    public $timestamps = false;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'currency',
        'amount',
        'balance_before',
        'balance_after',
        'locked_amount',
        'locked_before',
        'locked_after',
        'reference_type',
        'reference_id',
        'description',
        'metadata',
        'idempotency_key',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:8',
            'balance_before' => 'decimal:8',
            'balance_after' => 'decimal:8',
            'locked_amount' => 'decimal:8',
            'locked_before' => 'decimal:8',
            'locked_after' => 'decimal:8',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Reference type constants.
     */
    public const TYPE_DEPOSIT = 'deposit';
    public const TYPE_WITHDRAWAL = 'withdrawal';
    public const TYPE_ORDER_LOCK = 'order_lock';
    public const TYPE_ORDER_UNLOCK = 'order_unlock';
    public const TYPE_TRADE_DEBIT = 'trade_debit';
    public const TYPE_TRADE_CREDIT = 'trade_credit';
    public const TYPE_FEE_DEBIT = 'fee_debit';
    public const TYPE_REFUND = 'refund';
    public const TYPE_ADJUSTMENT = 'adjustment';

    /**
     * Get the user that owns this ledger entry.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if this is a credit (positive amount).
     */
    public function isCredit(): bool
    {
        return bccomp($this->amount, '0', 8) > 0;
    }

    /**
     * Check if this is a debit (negative amount).
     */
    public function isDebit(): bool
    {
        return bccomp($this->amount, '0', 8) < 0;
    }
}
