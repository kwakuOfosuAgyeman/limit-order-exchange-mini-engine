<?php

namespace Tests\Feature\Security;

use App\Enums\SecurityAction;
use App\Enums\SecurityEventType;
use App\Models\RateLimitCounter;
use App\Models\SecurityEvent;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttackDetectionMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear rate limit counters before each test
        RateLimitCounter::query()->delete();

        // Create a user and token for authenticated requests
        $this->user = User::factory()->create([
            'balance' => '100000.00000000',
            'locked_balance' => '0.00000000',
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        // Create a trading symbol
        Symbol::factory()->create([
            'symbol' => 'BTC/USD',
            'is_active' => true,
            'trading_enabled' => true,
            'min_trade_amount' => '0.0001',
        ]);
    }

    protected function tearDown(): void
    {
        RateLimitCounter::query()->delete();
        parent::tearDown();
    }

    public function test_middleware_allows_normal_requests(): void
    {
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->postJson('/api/orders', [
            'symbol' => 'BTC/USD',
            'side' => 'buy',
            'price' => '50000.00',
            'amount' => '0.001',
        ]);

        // Should either succeed or fail for business reasons, not security block
        $this->assertNotEquals(429, $response->status());
    }

    public function test_middleware_blocks_rapid_fire_spam(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 5]);

        // Send requests rapidly to trigger spam detection
        for ($i = 0; $i < 10; $i++) {
            $response = $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        // After threshold exceeded, should detect spam
        $this->assertTrue(
            SecurityEvent::where('user_id', $this->user->id)
                ->where('event_type', SecurityEventType::RAPID_FIRE_SPAM)
                ->exists()
        );
    }

    public function test_middleware_logs_security_events(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 2]);

        // Trigger spam detection
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        $this->assertDatabaseHas('security_events', [
            'user_id' => $this->user->id,
            'event_type' => SecurityEventType::RAPID_FIRE_SPAM->value,
            'endpoint' => 'api/orders',
            'http_method' => 'POST',
        ]);
    }

    public function test_middleware_updates_user_risk_score(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 2]);

        $initialRiskScore = $this->user->risk_score ?? 0;

        // Trigger detection
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        $this->user->refresh();
        $this->assertGreaterThan($initialRiskScore, $this->user->risk_score ?? 0);
    }

    public function test_middleware_increments_security_event_count(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 2]);

        $initialCount = $this->user->security_event_count ?? 0;

        // Trigger detection
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        $this->user->refresh();
        $this->assertGreaterThan($initialCount, $this->user->security_event_count ?? 0);
    }

    public function test_middleware_sets_last_security_event_timestamp(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 2]);

        $this->assertNull($this->user->last_security_event_at);

        // Trigger detection
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        $this->user->refresh();
        $this->assertNotNull($this->user->last_security_event_at);
    }

    public function test_middleware_does_not_affect_non_protected_endpoints(): void
    {
        // GET /api/orders is not a protected endpoint
        $response = $this->withHeaders([
            'Authorization' => "Bearer {$this->token}",
        ])->getJson('/api/orders');

        $response->assertStatus(200);
        $this->assertEquals(0, SecurityEvent::count());
    }

    public function test_middleware_records_ip_address(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 1]);

        // Trigger detection - need 3 requests since detection triggers when count > threshold
        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        $event = SecurityEvent::first();
        $this->assertNotNull($event, 'SecurityEvent should be created when spam threshold is exceeded');
        $this->assertNotEmpty($event->ip_address);
    }

    public function test_middleware_records_action_taken(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 1]);

        // Trigger detection
        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        $event = SecurityEvent::first();
        $this->assertNotNull($event);
        $this->assertContains($event->action_taken, [
            SecurityAction::LOGGED,
            SecurityAction::THROTTLED,
            SecurityAction::BLOCKED,
        ]);
    }

    public function test_middleware_auto_flags_account_at_threshold(): void
    {
        config([
            'attack_detection.thresholds.spam.orders_per_minute' => 1,
            'attack_detection.risk_scoring.auto_flag_threshold' => 10,
        ]);

        $this->assertFalse((bool) $this->user->security_review_required);

        // Trigger multiple detections to exceed flag threshold
        for ($i = 0; $i < 20; $i++) {
            $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        $this->user->refresh();
        $this->assertTrue($this->user->security_review_required);
    }

    public function test_middleware_disabled_does_not_detect(): void
    {
        config([
            'attack_detection.enabled' => false,
            'attack_detection.thresholds.spam.orders_per_minute' => 1,
        ]);

        // Send many requests
        for ($i = 0; $i < 10; $i++) {
            $this->withHeaders([
                'Authorization' => "Bearer {$this->token}",
            ])->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.001',
            ]);
        }

        $this->assertEquals(0, SecurityEvent::count());

        config(['attack_detection.enabled' => true]);
    }
}
