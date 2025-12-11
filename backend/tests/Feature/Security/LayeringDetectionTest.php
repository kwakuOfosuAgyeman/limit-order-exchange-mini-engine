<?php

namespace Tests\Feature\Security;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\SecuritySeverity;
use App\Models\Order;
use App\Models\RateLimitCounter;
use App\Models\Symbol;
use App\Models\User;
use App\Services\Security\MarketManipulationDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LayeringDetectionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private MarketManipulationDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'balance' => '100000.00000000',
            'locked_balance' => '0.00000000',
        ]);

        Symbol::factory()->create([
            'symbol' => 'BTC/USD',
            'is_active' => true,
            'trading_enabled' => true,
        ]);

        $this->detector = new MarketManipulationDetector();

        // Set layering thresholds
        config([
            'attack_detection.thresholds.layering.min_orders_same_price' => 3,
            'attack_detection.thresholds.layering.batch_cancel_threshold' => 3,
            'attack_detection.thresholds.layering.batch_window_seconds' => 10,
            'attack_detection.thresholds.layering.price_level_tolerance' => 0.0001,
            'attack_detection.thresholds.spam.orders_per_minute' => 1000, // Disable spam detection
        ]);
    }

    protected function tearDown(): void
    {
        RateLimitCounter::query()->delete();
        parent::tearDown();
    }

    public function test_detects_multiple_orders_at_same_price(): void
    {
        // Create 4 orders at the same price (above threshold of 3)
        $this->createOrdersAtPrice('50000.00', 4, OrderStatus::OPEN);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('layering'));
    }

    public function test_no_detection_below_same_price_threshold(): void
    {
        // Create only 2 orders at the same price (below threshold of 3)
        $this->createOrdersAtPrice('50000.00', 2, OrderStatus::OPEN);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('layering'));
    }

    public function test_detects_batch_cancellations(): void
    {
        // Create orders and cancel them within the batch window
        for ($i = 0; $i < 5; $i++) {
            Order::create([
                'user_id' => $this->user->id,
                'symbol' => 'BTC/USD',
                'side' => OrderSide::BUY,
                'type' => 'limit',
                'price' => (string) (50000 + $i * 100), // Different prices
                'amount' => '1.0',
                'filled_amount' => '0',
                'locked_funds' => '50000.00',
                'status' => OrderStatus::CANCELLED,
                'cancelled_at' => now()->subSeconds(rand(1, 5)), // Within 10 second window
            ]);
        }

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('layering'));
    }

    public function test_batch_cancellations_outside_window_not_detected(): void
    {
        // Create orders cancelled outside the batch window (> 10 seconds ago)
        for ($i = 0; $i < 5; $i++) {
            Order::create([
                'user_id' => $this->user->id,
                'symbol' => 'BTC/USD',
                'side' => OrderSide::BUY,
                'type' => 'limit',
                'price' => (string) (50000 + $i * 100),
                'amount' => '1.0',
                'filled_amount' => '0',
                'locked_funds' => '50000.00',
                'status' => OrderStatus::CANCELLED,
                'cancelled_at' => now()->subSeconds(30), // Outside 10 second window
            ]);
        }

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        // Should not detect batch cancellation since it's outside the window
        $threats = collect($result->threats)->filter(function ($threat) {
            return $threat['type'] === \App\Enums\SecurityEventType::LAYERING
                && isset($threat['metrics']['batch_cancels']);
        });

        $this->assertTrue($threats->isEmpty());
    }

    public function test_groups_orders_within_price_tolerance(): void
    {
        // Create orders at very similar prices (within 0.01% tolerance)
        Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '1.0',
            'filled_amount' => '0',
            'locked_funds' => '50000.00',
            'status' => OrderStatus::OPEN,
        ]);

        Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000001', // Tiny difference
            'amount' => '1.0',
            'filled_amount' => '0',
            'locked_funds' => '50000.00',
            'status' => OrderStatus::OPEN,
        ]);

        Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000002', // Tiny difference
            'amount' => '1.0',
            'filled_amount' => '0',
            'locked_funds' => '50000.00',
            'status' => OrderStatus::OPEN,
        ]);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('layering'));
    }

    public function test_filled_orders_not_counted_for_layering(): void
    {
        // Create filled orders at same price - should not trigger
        $this->createOrdersAtPrice('50000.00', 5, OrderStatus::FILLED);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        // Filled orders should not count toward layering
        $samePriceThreats = collect($result->threats)->filter(function ($threat) {
            return $threat['type'] === \App\Enums\SecurityEventType::LAYERING
                && isset($threat['metrics']['orders_at_price']);
        });

        $this->assertTrue($samePriceThreats->isEmpty());
    }

    public function test_partially_filled_orders_count_for_layering(): void
    {
        // Create partially filled orders at same price
        $this->createOrdersAtPrice('50000.00', 4, OrderStatus::PARTIALLY_FILLED);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('layering'));
    }

    public function test_layering_detection_includes_related_orders(): void
    {
        $this->createOrdersAtPrice('50000.00', 4, OrderStatus::OPEN);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $relatedOrders = $result->getAllRelatedOrders();
        $this->assertCount(4, $relatedOrders);
    }

    public function test_batch_cancel_has_higher_severity(): void
    {
        // Create batch cancellations (high severity)
        for ($i = 0; $i < 5; $i++) {
            Order::create([
                'user_id' => $this->user->id,
                'symbol' => 'BTC/USD',
                'side' => OrderSide::BUY,
                'type' => 'limit',
                'price' => (string) (50000 + $i * 100),
                'amount' => '1.0',
                'filled_amount' => '0',
                'locked_funds' => '50000.00',
                'status' => OrderStatus::CANCELLED,
                'cancelled_at' => now()->subSeconds(2),
            ]);
        }

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertEquals(SecuritySeverity::HIGH, $result->highestSeverity);
    }

    public function test_same_price_layering_has_medium_severity(): void
    {
        // Create same-price layering only (medium severity)
        $this->createOrdersAtPrice('50000.00', 4, OrderStatus::OPEN);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $threat = collect($result->threats)->firstWhere('type', \App\Enums\SecurityEventType::LAYERING);
        $this->assertNotNull($threat);
        $this->assertEquals(SecuritySeverity::MEDIUM, $threat['severity']);
    }

    public function test_detection_metrics_include_total_amount(): void
    {
        $this->createOrdersAtPrice('50000.00', 4, OrderStatus::OPEN);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $threat = collect($result->threats)->filter(function ($threat) {
            return $threat['type'] === \App\Enums\SecurityEventType::LAYERING
                && isset($threat['metrics']['orders_at_price']);
        })->first();

        $this->assertNotNull($threat);
        $this->assertArrayHasKey('total_amount', $threat['metrics']);
        $this->assertEquals(4.0, (float) $threat['metrics']['total_amount']); // 4 orders * 1.0 each
    }

    private function createOrdersAtPrice(string $price, int $count, OrderStatus $status): void
    {
        for ($i = 0; $i < $count; $i++) {
            Order::create([
                'user_id' => $this->user->id,
                'symbol' => 'BTC/USD',
                'side' => OrderSide::BUY,
                'type' => 'limit',
                'price' => $price,
                'amount' => '1.0',
                'filled_amount' => $status === OrderStatus::FILLED ? '1.0' : '0',
                'locked_funds' => $status === OrderStatus::FILLED ? '0' : $price,
                'status' => $status,
            ]);
        }
    }
}
