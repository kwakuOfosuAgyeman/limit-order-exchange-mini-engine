<?php

namespace Tests\Feature\Security;

use App\Enums\SecurityAction;
use App\Enums\SecurityEventType;
use App\Enums\SecuritySeverity;
use App\Events\SecurityAlertEvent;
use App\Jobs\ProcessSecurityAlert;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Notifications\SecurityAlertNotification;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class ProcessSecurityAlertJobTest extends TestCase
{
    use DatabaseMigrations;

    private SecurityEvent $securityEvent;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();

        $this->securityEvent = SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::HIGH,
            'user_id' => $user->id,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => ['cancel_rate' => 0.85],
            'action_taken' => SecurityAction::THROTTLED,
        ]);

        // Clear any cached cooldowns
        Cache::flush();
    }

    public function test_job_broadcasts_security_alert_event(): void
    {
        Event::fake([SecurityAlertEvent::class]);

        $job = new ProcessSecurityAlert($this->securityEvent);
        $job->handle();

        Event::assertDispatched(SecurityAlertEvent::class, function ($event) {
            return $event->securityEvent->id === $this->securityEvent->id;
        });
    }

    public function test_job_sends_notification_to_admin_users(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        config(['attack_detection.alerts.admin_user_ids' => [$admin->id]]);

        $job = new ProcessSecurityAlert($this->securityEvent);
        $job->handle();

        Notification::assertSentTo($admin, SecurityAlertNotification::class);
    }

    public function test_job_does_not_send_notification_to_non_admins(): void
    {
        Notification::fake();

        $regularUser = User::factory()->create();
        $admin = User::factory()->create();
        config(['attack_detection.alerts.admin_user_ids' => [$admin->id]]);

        $job = new ProcessSecurityAlert($this->securityEvent);
        $job->handle();

        Notification::assertNotSentTo($regularUser, SecurityAlertNotification::class);
        Notification::assertSentTo($admin, SecurityAlertNotification::class);
    }

    public function test_job_marks_alert_as_sent(): void
    {
        Event::fake();
        Notification::fake();

        $this->assertFalse((bool) $this->securityEvent->alert_sent);
        $this->assertNull($this->securityEvent->alert_sent_at);

        $job = new ProcessSecurityAlert($this->securityEvent);
        $job->handle();

        $this->securityEvent->refresh();
        $this->assertTrue($this->securityEvent->alert_sent);
        $this->assertNotNull($this->securityEvent->alert_sent_at);
    }

    public function test_job_respects_cooldown_period(): void
    {
        Event::fake();
        Notification::fake();
        config(['attack_detection.alerts.cooldown_minutes' => 5]);

        // First alert should go through
        $job1 = new ProcessSecurityAlert($this->securityEvent);
        $job1->handle();

        Event::assertDispatchedTimes(SecurityAlertEvent::class, 1);

        // Create a second similar event
        $secondEvent = SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::HIGH,
            'user_id' => $this->securityEvent->user_id,
            'ip_address' => $this->securityEvent->ip_address,
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => ['cancel_rate' => 0.90],
            'action_taken' => SecurityAction::THROTTLED,
        ]);

        // Second alert within cooldown should be skipped
        $job2 = new ProcessSecurityAlert($secondEvent);
        $job2->handle();

        // Should still only have 1 dispatch due to cooldown
        Event::assertDispatchedTimes(SecurityAlertEvent::class, 1);
    }

    public function test_different_event_types_have_separate_cooldowns(): void
    {
        Event::fake();
        Notification::fake();
        config(['attack_detection.alerts.cooldown_minutes' => 5]);

        // First alert for spoofing
        $job1 = new ProcessSecurityAlert($this->securityEvent);
        $job1->handle();

        // Create a different type of event
        $layeringEvent = SecurityEvent::create([
            'uuid' => (string) Str::uuid(),
            'event_type' => SecurityEventType::LAYERING,
            'severity' => SecuritySeverity::HIGH,
            'user_id' => $this->securityEvent->user_id,
            'ip_address' => $this->securityEvent->ip_address,
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => ['batch_cancels' => 5],
            'action_taken' => SecurityAction::THROTTLED,
        ]);

        // Different event type should not be affected by cooldown
        $job2 = new ProcessSecurityAlert($layeringEvent);
        $job2->handle();

        Event::assertDispatchedTimes(SecurityAlertEvent::class, 2);
    }

    public function test_job_has_correct_tags(): void
    {
        $job = new ProcessSecurityAlert($this->securityEvent);
        $tags = $job->tags();

        $this->assertContains('security', $tags);
        $this->assertContains('alert', $tags);
        $this->assertContains('severity:high', $tags);
        $this->assertContains('type:order_spoofing', $tags);
    }

    public function test_notification_contains_correct_data(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        config(['attack_detection.alerts.admin_user_ids' => [$admin->id]]);

        $job = new ProcessSecurityAlert($this->securityEvent);
        $job->handle();

        Notification::assertSentTo($admin, SecurityAlertNotification::class, function ($notification) {
            $data = $notification->toDatabase($this->securityEvent->user);

            return $data['security_event_id'] === $this->securityEvent->id
                && $data['type'] === 'order_spoofing'
                && $data['severity'] === 'high'
                && str_contains($data['message'], 'Order Spoofing');
        });
    }

    public function test_job_handles_no_admin_users_gracefully(): void
    {
        Event::fake();
        Notification::fake();

        // No admin users configured
        config(['attack_detection.alerts.admin_user_ids' => []]);

        $job = new ProcessSecurityAlert($this->securityEvent);
        $job->handle();

        // Should still broadcast event
        Event::assertDispatched(SecurityAlertEvent::class);

        // No notifications sent
        Notification::assertNothingSent();

        // Alert should still be marked as sent
        $this->securityEvent->refresh();
        $this->assertTrue($this->securityEvent->alert_sent);
    }

    public function test_broadcast_event_contains_correct_payload(): void
    {
        Event::fake();

        $job = new ProcessSecurityAlert($this->securityEvent);
        $job->handle();

        Event::assertDispatched(SecurityAlertEvent::class, function ($event) {
            $payload = $event->broadcastWith();

            return $payload['id'] === $this->securityEvent->uuid
                && $payload['type'] === 'order_spoofing'
                && $payload['type_label'] === 'Order Spoofing'
                && $payload['severity'] === 'high'
                && $payload['severity_label'] === 'High'
                && $payload['user_id'] === $this->securityEvent->user_id
                && $payload['ip_address'] === '192.168.1.1'
                && isset($payload['metrics']['cancel_rate']);
        });
    }

    public function test_broadcast_event_name_is_correct(): void
    {
        $event = new SecurityAlertEvent($this->securityEvent);

        $this->assertEquals('security.alert', $event->broadcastAs());
    }

    public function test_broadcast_channels_include_security_alerts(): void
    {
        $admin = User::factory()->create();
        config(['attack_detection.alerts.admin_user_ids' => [$admin->id]]);

        $event = new SecurityAlertEvent($this->securityEvent);
        $channels = $event->broadcastOn();

        $channelNames = array_map(fn ($channel) => $channel->name, $channels);

        $this->assertContains('private-security-alerts', $channelNames);
        $this->assertContains("private-admin.{$admin->id}", $channelNames);
    }
}
