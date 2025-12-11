<?php

namespace App\Jobs;

use App\Events\SecurityAlertEvent;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

class ProcessSecurityAlert implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public SecurityEvent $securityEvent
    ) {}

    public function handle(): void
    {
        // Check cooldown to prevent alert spam
        $cooldownKey = sprintf(
            'security_alert:%s:%s:%s',
            $this->securityEvent->event_type->value,
            $this->securityEvent->user_id ?? 'guest',
            $this->securityEvent->ip_address
        );
        $cooldownMinutes = config('attack_detection.alerts.cooldown_minutes');

        if (Cache::has($cooldownKey)) {
            return; // Skip this alert due to cooldown
        }

        // Set cooldown
        Cache::put($cooldownKey, true, now()->addMinutes($cooldownMinutes));

        // Broadcast real-time alert via Pusher
        event(new SecurityAlertEvent($this->securityEvent));

        // Send database notifications to admins
        $adminIds = config('attack_detection.alerts.admin_user_ids');
        $admins = User::whereIn('id', $adminIds)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new SecurityAlertNotification($this->securityEvent));
        }

        // Mark alert as sent
        $this->securityEvent->update([
            'alert_sent' => true,
            'alert_sent_at' => now(),
        ]);
    }

    public function tags(): array
    {
        return [
            'security',
            'alert',
            "severity:{$this->securityEvent->severity->value}",
            "type:{$this->securityEvent->event_type->value}",
        ];
    }
}
