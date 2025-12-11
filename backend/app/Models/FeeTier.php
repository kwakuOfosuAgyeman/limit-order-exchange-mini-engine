<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeTier extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'description',
        'maker_fee_rate',
        'taker_fee_rate',
        'min_30d_volume',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'maker_fee_rate' => 'decimal:6',
            'taker_fee_rate' => 'decimal:6',
            'min_30d_volume' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the users in this fee tier.
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Get maker fee rate as percentage.
     */
    public function getMakerFeePercentageAttribute(): string
    {
        return bcmul($this->maker_fee_rate, '100', 4);
    }

    /**
     * Get taker fee rate as percentage.
     */
    public function getTakerFeePercentageAttribute(): string
    {
        return bcmul($this->taker_fee_rate, '100', 4);
    }
}
