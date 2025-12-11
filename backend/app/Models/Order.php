<?php

namespace App\Models;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'symbol',
        'side',
        'type',
        'price',
        'amount',
        'filled_amount',
        'locked_funds',
        'status',
        'client_order_id',
        'ip_address',
        'user_agent',
        'expires_at',
        'cancelled_at',
        'filled_at',
    ];

    protected $casts = [
        'side' => OrderSide::class,
        'status' => OrderStatus::class,
        'price' => 'decimal:8',
        'amount' => 'decimal:8',
        'filled_amount' => 'decimal:8',
        'locked_funds' => 'decimal:8',
        'remaining_amount' => 'decimal:8',
        'filled_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order) {
            if (empty($order->uuid)) {
                $order->uuid = (string) Str::uuid();
            }
        });
    }

    // ==================== RELATIONSHIPS ====================

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function buyTrades(): HasMany
    {
        return $this->hasMany(Trade::class, 'buy_order_id');
    }

    public function sellTrades(): HasMany
    {
        return $this->hasMany(Trade::class, 'sell_order_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }

    // ==================== SCOPES ====================

    /**
     * Scope for open orders (can be matched)
     */
    public function scopeOpen($query)
    {
        return $query->where('status', OrderStatus::OPEN);
    }

    /**
     * Scope for active orders (open or partially filled)
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', [
            OrderStatus::OPEN,
            OrderStatus::PARTIALLY_FILLED,
        ]);
    }

    /**
     * Optimized scope for finding matching SELL orders for a BUY order
     */
    public function scopeMatchingSellOrders($query, string $symbol, string $maxPrice)
    {
        return $query->where('symbol', $symbol)
            ->where('side', OrderSide::SELL)
            ->where('status', OrderStatus::OPEN)
            ->where('price', '<=', $maxPrice)
            ->orderBy('price', 'asc')      // Best price first
            ->orderBy('created_at', 'asc'); // FIFO for same price
    }

    /**
     * Optimized scope for finding matching BUY orders for a SELL order
     */
    public function scopeMatchingBuyOrders($query, string $symbol, string $minPrice)
    {
        return $query->where('symbol', $symbol)
            ->where('side', OrderSide::BUY)
            ->where('status', OrderStatus::OPEN)
            ->where('price', '>=', $minPrice)
            ->orderBy('price', 'desc')     // Best price first
            ->orderBy('created_at', 'asc'); // FIFO for same price
    }

    // ==================== COMPUTED PROPERTIES ====================

    /**
     * Get total value of the order in quote currency (USD)
     */
    public function getTotalValueAttribute(): string
    {
        return bcmul($this->price, $this->amount, 8);
    }

    /**
     * Get filled value in quote currency
     */
    public function getFilledValueAttribute(): string
    {
        return bcmul($this->price, $this->filled_amount, 8);
    }

    /**
     * Check if order can be cancelled
     */
    public function canBeCancelled(): bool
    {
        return $this->status->isActive();
    }

    /**
     * Check if order can be matched
     */
    public function canBeMatched(): bool
    {
        return $this->status === OrderStatus::OPEN
            || $this->status === OrderStatus::PARTIALLY_FILLED;
    }

    // ==================== BUSINESS LOGIC HELPERS ====================

    /**
     * Calculate required locked funds for this order
     * For BUY: lock USD (price * amount)
     * For SELL: lock asset amount
     */
    public function calculateRequiredLock(): string
    {
        if ($this->side === OrderSide::BUY) {
            return bcmul($this->price, $this->amount, 8);
        }
        return $this->amount;
    }

    /**
     * Get the remaining amount to be filled
     */
    public function getRemainingAmount(): string
    {
        return bcsub($this->amount, $this->filled_amount, 8);
    }
}
