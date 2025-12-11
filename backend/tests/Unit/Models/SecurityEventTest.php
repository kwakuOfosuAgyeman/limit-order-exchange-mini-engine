<?php

namespace Tests\Unit\Models;

use App\Enums\SecurityAction;
use App\Enums\SecurityEventType;
use App\Enums\SecuritySeverity;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SecurityEventTest extends TestCase
{
    use RefreshDatabase;

    public function test_uuid_is_generated_on_creation(): void
    {
        $event = SecurityEvent::create([
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::MEDIUM,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => ['cancel_rate' => 0.8],
            'action_taken' => SecurityAction::THROTTLED,
        ]);

        $this->assertNotNull($event->uuid);
        $this->assertEquals(36, strlen($event->uuid)); // UUID format
    }

    public function test_event_type_is_cast_to_enum(): void
    {
        $event = SecurityEvent::create([
            'event_type' => SecurityEventType::WASH_TRADING,
            'severity' => SecuritySeverity::CRITICAL,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::BLOCKED,
        ]);

        $this->assertInstanceOf(SecurityEventType::class, $event->event_type);
        $this->assertEquals(SecurityEventType::WASH_TRADING, $event->event_type);
    }

    public function test_severity_is_cast_to_enum(): void
    {
        $event = SecurityEvent::create([
            'event_type' => SecurityEventType::LAYERING,
            'severity' => SecuritySeverity::HIGH,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
        ]);

        $this->assertInstanceOf(SecuritySeverity::class, $event->severity);
        $this->assertEquals(SecuritySeverity::HIGH, $event->severity);
    }

    public function test_detection_metrics_is_cast_to_array(): void
    {
        $metrics = [
            'cancel_rate' => 0.85,
            'quick_cancels' => 5,
            'total_orders' => 10,
        ];

        $event = SecurityEvent::create([
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::MEDIUM,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => $metrics,
            'action_taken' => SecurityAction::THROTTLED,
        ]);

        $this->assertIsArray($event->detection_metrics);
        $this->assertEquals(0.85, $event->detection_metrics['cancel_rate']);
    }

    public function test_related_orders_is_cast_to_array(): void
    {
        $orders = ['uuid-1', 'uuid-2', 'uuid-3'];

        $event = SecurityEvent::create([
            'event_type' => SecurityEventType::LAYERING,
            'severity' => SecuritySeverity::HIGH,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'related_orders' => $orders,
            'action_taken' => SecurityAction::LOGGED,
        ]);

        $this->assertIsArray($event->related_orders);
        $this->assertCount(3, $event->related_orders);
        $this->assertContains('uuid-1', $event->related_orders);
    }

    public function test_user_relationship(): void
    {
        $user = User::factory()->create();

        $event = SecurityEvent::create([
            'event_type' => SecurityEventType::RAPID_FIRE_SPAM,
            'severity' => SecuritySeverity::LOW,
            'user_id' => $user->id,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
        ]);

        $this->assertEquals($user->id, $event->user->id);
    }

    public function test_scope_unreviewed(): void
    {
        SecurityEvent::create([
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::MEDIUM,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
            'reviewed' => false,
        ]);

        SecurityEvent::create([
            'event_type' => SecurityEventType::LAYERING,
            'severity' => SecuritySeverity::HIGH,
            'ip_address' => '192.168.1.2',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
            'reviewed' => true,
        ]);

        $unreviewed = SecurityEvent::unreviewed()->get();

        $this->assertCount(1, $unreviewed);
        $this->assertEquals(SecurityEventType::ORDER_SPOOFING, $unreviewed->first()->event_type);
    }

    public function test_scope_by_severity(): void
    {
        SecurityEvent::create([
            'event_type' => SecurityEventType::RAPID_FIRE_SPAM,
            'severity' => SecuritySeverity::LOW,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
        ]);

        SecurityEvent::create([
            'event_type' => SecurityEventType::WASH_TRADING,
            'severity' => SecuritySeverity::CRITICAL,
            'ip_address' => '192.168.1.2',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::BLOCKED,
        ]);

        $criticalEvents = SecurityEvent::bySeverity(SecuritySeverity::CRITICAL)->get();

        $this->assertCount(1, $criticalEvents);
        $this->assertEquals(SecurityEventType::WASH_TRADING, $criticalEvents->first()->event_type);
    }

    public function test_scope_by_type(): void
    {
        SecurityEvent::create([
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::MEDIUM,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
        ]);

        SecurityEvent::create([
            'event_type' => SecurityEventType::WASH_TRADING,
            'severity' => SecuritySeverity::CRITICAL,
            'ip_address' => '192.168.1.2',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::BLOCKED,
        ]);

        $spoofingEvents = SecurityEvent::byType(SecurityEventType::ORDER_SPOOFING)->get();

        $this->assertCount(1, $spoofingEvents);
    }

    public function test_scope_for_user(): void
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        SecurityEvent::create([
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::MEDIUM,
            'user_id' => $user1->id,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
        ]);

        SecurityEvent::create([
            'event_type' => SecurityEventType::LAYERING,
            'severity' => SecuritySeverity::HIGH,
            'user_id' => $user2->id,
            'ip_address' => '192.168.1.2',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
        ]);

        $user1Events = SecurityEvent::forUser($user1->id)->get();

        $this->assertCount(1, $user1Events);
        $this->assertEquals(SecurityEventType::ORDER_SPOOFING, $user1Events->first()->event_type);
    }

    public function test_scope_recent(): void
    {
        // Create an old event
        $oldEvent = SecurityEvent::create([
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::MEDIUM,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
        ]);

        // Use query builder to bypass Eloquent's timestamp handling
        SecurityEvent::where('id', $oldEvent->id)
            ->update(['created_at' => now()->subHours(2)]);

        // Create a recent event
        SecurityEvent::create([
            'event_type' => SecurityEventType::LAYERING,
            'severity' => SecuritySeverity::HIGH,
            'ip_address' => '192.168.1.2',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
        ]);

        $recentEvents = SecurityEvent::recent(60)->get(); // Last 60 minutes

        $this->assertCount(1, $recentEvents);
        $this->assertEquals(SecurityEventType::LAYERING, $recentEvents->first()->event_type);
    }

    public function test_scope_for_ip(): void
    {
        SecurityEvent::create([
            'event_type' => SecurityEventType::RAPID_FIRE_SPAM,
            'severity' => SecuritySeverity::MEDIUM,
            'ip_address' => '192.168.1.100',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::THROTTLED,
        ]);

        SecurityEvent::create([
            'event_type' => SecurityEventType::RAPID_FIRE_SPAM,
            'severity' => SecuritySeverity::MEDIUM,
            'ip_address' => '192.168.1.200',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::THROTTLED,
        ]);

        $eventsFromIp = SecurityEvent::forIp('192.168.1.100')->get();

        $this->assertCount(1, $eventsFromIp);
    }

    public function test_scope_pending(): void
    {
        SecurityEvent::create([
            'event_type' => SecurityEventType::ORDER_SPOOFING,
            'severity' => SecuritySeverity::MEDIUM,
            'ip_address' => '192.168.1.1',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
            'resolution' => 'pending',
        ]);

        SecurityEvent::create([
            'event_type' => SecurityEventType::LAYERING,
            'severity' => SecuritySeverity::HIGH,
            'ip_address' => '192.168.1.2',
            'endpoint' => '/api/orders',
            'http_method' => 'POST',
            'detection_metrics' => [],
            'action_taken' => SecurityAction::LOGGED,
            'resolution' => 'confirmed',
        ]);

        $pendingEvents = SecurityEvent::pending()->get();

        $this->assertCount(1, $pendingEvents);
        $this->assertEquals(SecurityEventType::ORDER_SPOOFING, $pendingEvents->first()->event_type);
    }
}
