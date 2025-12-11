<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Symbol extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'symbol',
        'name',
        'base_asset',
        'quote_asset',
        'min_trade_amount',
        'max_trade_amount',
        'tick_size',
        'lot_size',
        'price_precision',
        'amount_precision',
        'is_active',
        'trading_enabled',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_trade_amount' => 'decimal:8',
            'max_trade_amount' => 'decimal:8',
            'tick_size' => 'decimal:8',
            'lot_size' => 'decimal:8',
            'price_precision' => 'integer',
            'amount_precision' => 'integer',
            'is_active' => 'boolean',
            'trading_enabled' => 'boolean',
        ];
    }

    /**
     * Get all orders for this symbol.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Check if trading is allowed for this symbol.
     */
    public function canTrade(): bool
    {
        return $this->is_active && $this->trading_enabled;
    }

    /**
     * Get the full trading pair name (e.g., BTC/USD).
     */
    public function getPairAttribute(): string
    {
        return "{$this->base_asset}/{$this->quote_asset}";
    }
}
