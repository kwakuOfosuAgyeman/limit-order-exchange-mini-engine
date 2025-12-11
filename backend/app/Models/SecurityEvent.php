<?php

namespace App\Models;

use App\Enums\SecurityAction;
use App\Enums\SecurityEventType;
use App\Enums\SecuritySeverity;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class SecurityEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'event_type',
        'severity',
        'user_id',
        'ip_address',
        'user_agent',
        'symbol',
        'endpoint',
        'http_method',
        'detection_metrics',
        'related_orders',
        'related_users',
        'action_taken',
        'throttle_delay_ms',
        'risk_score',
        'alert_sent',
        'alert_sent_at',
        'reviewed',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'resolution',
    ];

    protected $casts = [
        'event_type' => SecurityEventType::class,
        'severity' => SecuritySeverity::class,
        'action_taken' => SecurityAction::class,
        'detection_metrics' => 'array',
        'related_orders' => 'array',
        'related_users' => 'array',
        'alert_sent' => 'boolean',
        'alert_sent_at' => 'datetime',
        'reviewed' => 'boolean',
        'reviewed_at' => 'datetime',
        'risk_score' => 'decimal:2',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SecurityEvent $event) {
            if (empty($event->uuid)) {
                $event->uuid = (string) Str::uuid();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeUnreviewed($query)
    {
        return $query->where('reviewed', false);
    }

    public function scopeBySeverity($query, SecuritySeverity $severity)
    {
        return $query->where('severity', $severity);
    }

    public function scopeByType($query, SecurityEventType $type)
    {
        return $query->where('event_type', $type);
    }

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeRecent($query, int $minutes = 60)
    {
        return $query->where('created_at', '>=', now()->subMinutes($minutes));
    }

    public function scopeForIp($query, string $ipAddress)
    {
        return $query->where('ip_address', $ipAddress);
    }

    public function scopePending($query)
    {
        return $query->where('resolution', 'pending');
    }
}
