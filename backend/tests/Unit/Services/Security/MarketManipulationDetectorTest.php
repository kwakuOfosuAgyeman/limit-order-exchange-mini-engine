<?php

namespace Tests\Unit\Services\Security;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\SecurityEventType;
use App\Enums\SecuritySeverity;
use App\Models\Order;
use App\Models\RateLimitCounter;
use App\Models\Trade;
use App\Models\User;
use App\Services\Security\MarketManipulationDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class MarketManipulationDetectorTest extends TestCase
{
    use RefreshDatabase;

    private MarketManipulationDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();
        $this->detector = new MarketManipulationDetector();
    }

    protected function tearDown(): void
    {
        // Clean up rate limit counters between tests
        RateLimitCounter::query()->delete();
        parent::tearDown();
    }

    public function test_clean_result_when_detection_disabled(): void
    {
        config(['attack_detection.enabled' => false]);

        $request = Request::create('/api/orders', 'POST');
        $result = $this->detector->analyze($request, null);

        $this->assertFalse($result->detected);

        config(['attack_detection.enabled' => true]);
    }

    public function test_clean_result_for_whitelisted_ip(): void
    {
        config(['attack_detection.ip_whitelist' => ['192.168.1.100']]);

        $request = Request::create('/api/orders', 'POST', [], [], [], [
            'REMOTE_ADDR' => '192.168.1.100',
        ]);

        $result = $this->detector->analyze($request, null);

        $this->assertFalse($result->detected);

        config(['attack_detection.ip_whitelist' => []]);
    }

    public function test_clean_result_for_whitelisted_user(): void
    {
        $user = User::factory()->create();
        config(['attack_detection.user_whitelist' => [$user->id]]);

        $request = Request::create('/api/orders', 'POST');
        $result = $this->detector->analyze($request, $user);

        $this->assertFalse($result->detected);

        config(['attack_detection.user_whitelist' => []]);
    }

    public function test_detects_rapid_fire_spam_over_threshold(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 5]);
        // Recreate detector to pick up new config
        $detector = new MarketManipulationDetector();

        $user = User::factory()->create();
        $request = Request::create('/api/orders', 'POST');

        // Simulate 6 requests (over the threshold of 5)
        for ($i = 0; $i < 6; $i++) {
            $result = $detector->analyze($request, $user);
        }

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('rapid_fire_spam'));
    }

    public function test_no_detection_below_spam_threshold(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 10]);
        // Recreate detector to pick up new config
        $detector = new MarketManipulationDetector();

        $user = User::factory()->create();
        $request = Request::create('/api/orders', 'POST');

        // Only 3 requests, below threshold
        for ($i = 0; $i < 3; $i++) {
            $result = $detector->analyze($request, $user);
        }

        // Should not detect spam with only 3 requests
        $this->assertFalse($result->hasThreatType('rapid_fire_spam'));
    }

    public function test_builds_correct_detection_context(): void
    {
        $user = User::factory()->create();
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '50000.00',
            'amount' => '1.5',
            'side' => 'buy',
        ], [], [], [
            'REMOTE_ADDR' => '10.0.0.1',
        ]);

        // We need to access the context, so we'll check via the result
        $result = $this->detector->analyze($request, $user);

        // The context should be populated in the result
        $this->assertNotNull($result->context);
        $this->assertEquals('10.0.0.1', $result->context->ipAddress);
        $this->assertEquals('BTC/USD', $result->context->symbol);
        $this->assertEquals('50000.00', $result->context->orderPrice);
        $this->assertEquals('1.5', $result->context->orderAmount);
        $this->assertEquals('buy', $result->context->orderSide);
    }

    public function test_context_user_key_for_authenticated_user(): void
    {
        $user = User::factory()->create(['id' => 42]);
        $request = Request::create('/api/orders', 'POST');

        $result = $this->detector->analyze($request, $user);

        $this->assertEquals('user:42', $result->context->getUserKey());
    }

    public function test_context_user_key_for_unauthenticated_request(): void
    {
        $request = Request::create('/api/orders', 'POST', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.50',
        ]);

        $result = $this->detector->analyze($request, null);

        $this->assertEquals('ip:203.0.113.50', $result->context->getUserKey());
    }

    public function test_highest_severity_is_selected_from_multiple_threats(): void
    {
        config([
            'attack_detection.thresholds.spam.orders_per_minute' => 2,
            'attack_detection.thresholds.spam.requests_per_second' => 1,
        ]);
        // Recreate detector to pick up new config
        $detector = new MarketManipulationDetector();

        $user = User::factory()->create();
        $request = Request::create('/api/orders', 'POST');

        // Trigger multiple spam detections
        for ($i = 0; $i < 15; $i++) {
            $result = $detector->analyze($request, $user);
        }

        $this->assertTrue($result->detected);
        // Should have the highest severity from all detected threats
        $this->assertGreaterThanOrEqual(
            SecuritySeverity::MEDIUM->numericValue(),
            $result->highestSeverity->numericValue()
        );
    }

    public function test_risk_score_accumulates_from_threats(): void
    {
        config(['attack_detection.thresholds.spam.orders_per_minute' => 1]);
        // Recreate detector to pick up new config
        $detector = new MarketManipulationDetector();

        $user = User::factory()->create();
        $request = Request::create('/api/orders', 'POST');

        // Trigger detection
        $detector->analyze($request, $user);
        $result = $detector->analyze($request, $user);

        $this->assertTrue($result->detected);
        $this->assertGreaterThan(0, $result->riskScore);
    }

    public function test_risk_score_capped_at_max(): void
    {
        config([
            'attack_detection.thresholds.spam.orders_per_minute' => 1,
            'attack_detection.risk_scoring.max_score' => 100,
        ]);
        // Recreate detector to pick up new config
        $detector = new MarketManipulationDetector();

        $user = User::factory()->create();
        $request = Request::create('/api/orders', 'POST');

        // Trigger many detections
        for ($i = 0; $i < 50; $i++) {
            $result = $detector->analyze($request, $user);
        }

        $this->assertLessThanOrEqual(100, $result->riskScore);
    }
}
