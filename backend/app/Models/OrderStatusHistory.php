<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderStatusHistory extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'order_status_history';

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
        'order_id',
        'status_from',
        'status_to',
        'changed_by',
        'reason',
        'metadata',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status_from' => 'integer',
            'status_to' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Changed by constants.
     */
    public const CHANGED_BY_SYSTEM = 'system';
    public const CHANGED_BY_USER = 'user';
    public const CHANGED_BY_ADMIN = 'admin';
    public const CHANGED_BY_EXPIRY = 'expiry';

    /**
     * Get the order this history belongs to.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * Check if this is the initial creation record.
     */
    public function isInitialCreation(): bool
    {
        return $this->status_from === null;
    }
}
