<?php

namespace App\Notifications;

use App\Models\SecurityEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class SecurityAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public SecurityEvent $securityEvent
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'security_event_id' => $this->securityEvent->id,
            'security_event_uuid' => $this->securityEvent->uuid,
            'type' => $this->securityEvent->event_type->value,
            'type_label' => $this->securityEvent->event_type->label(),
            'severity' => $this->securityEvent->severity->value,
            'severity_label' => $this->securityEvent->severity->label(),
            'user_id' => $this->securityEvent->user_id,
            'ip_address' => $this->securityEvent->ip_address,
            'symbol' => $this->securityEvent->symbol,
            'action_taken' => $this->securityEvent->action_taken->value,
            'message' => $this->buildMessage(),
        ];
    }

    private function buildMessage(): string
    {
        $type = $this->securityEvent->event_type->label();
        $severity = strtoupper($this->securityEvent->severity->value);
        $userId = $this->securityEvent->user_id ?? 'Unknown';
        $ip = $this->securityEvent->ip_address;

        return "[{$severity}] {$type} detected from user #{$userId} (IP: {$ip})";
    }
}
