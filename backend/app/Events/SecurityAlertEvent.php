<?php

namespace App\Events;

use App\Models\SecurityEvent;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityAlertEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SecurityEvent $securityEvent
    ) {}

    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel(config('attack_detection.alerts.broadcast_channel')),
        ];

        // Also broadcast to specific admin channels
        foreach (config('attack_detection.alerts.admin_user_ids') as $adminId) {
            $channels[] = new PrivateChannel("admin.{$adminId}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'security.alert';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->securityEvent->uuid,
            'type' => $this->securityEvent->event_type->value,
            'type_label' => $this->securityEvent->event_type->label(),
            'severity' => $this->securityEvent->severity->value,
            'severity_label' => $this->securityEvent->severity->label(),
            'user_id' => $this->securityEvent->user_id,
            'ip_address' => $this->securityEvent->ip_address,
            'symbol' => $this->securityEvent->symbol,
            'endpoint' => $this->securityEvent->endpoint,
            'action_taken' => $this->securityEvent->action_taken->value,
            'risk_score' => $this->securityEvent->risk_score,
            'metrics' => $this->securityEvent->detection_metrics,
            'timestamp' => $this->securityEvent->created_at->toIso8601String(),
        ];
    }
}
