<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'balance',
        'locked_balance',
        'fee_tier_id',
        'is_active',
        'suspended_at',
        'suspension_reason',
        'risk_score',
        'risk_score_updated_at',
        'security_event_count',
        'last_security_event_at',
        'security_review_required',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'version',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'balance' => 'decimal:8',
            'locked_balance' => 'decimal:8',
            'is_active' => 'boolean',
            'suspended_at' => 'datetime',
            'version' => 'integer',
            'risk_score' => 'decimal:2',
            'risk_score_updated_at' => 'datetime',
            'security_event_count' => 'integer',
            'last_security_event_at' => 'datetime',
            'security_review_required' => 'boolean',
        ];
    }

    /**
     * Get the fee tier associated with the user.
     */
    public function feeTier(): BelongsTo
    {
        return $this->belongsTo(FeeTier::class);
    }

    /**
     * Get all orders for this user.
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * Get all assets for this user.
     */
    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }

    /**
     * Get all trades where user is buyer.
     */
    public function buyTrades(): HasMany
    {
        return $this->hasMany(Trade::class, 'buyer_id');
    }

    /**
     * Get all trades where user is seller.
     */
    public function sellTrades(): HasMany
    {
        return $this->hasMany(Trade::class, 'seller_id');
    }

    /**
     * Get the total balance (available + locked).
     */
    public function getTotalBalanceAttribute(): string
    {
        return bcadd($this->balance, $this->locked_balance, 8);
    }

    /**
     * Check if user is suspended.
     */
    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /**
     * Check if user can trade.
     */
    public function canTrade(): bool
    {
        return $this->is_active && !$this->isSuspended();
    }
}
