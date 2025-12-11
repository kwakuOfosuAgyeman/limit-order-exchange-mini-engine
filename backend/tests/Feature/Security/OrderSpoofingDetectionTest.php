<?php

namespace Tests\Feature\Security;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\SecurityEventType;
use App\Models\Order;
use App\Models\RateLimitCounter;
use App\Models\SecurityEvent;
use App\Models\Symbol;
use App\Models\User;
use App\Services\Security\MarketManipulationDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class OrderSpoofingDetectionTest extends TestCase
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

        // Set spoofing thresholds for testing
        config([
            'attack_detection.thresholds.spoofing.cancel_rate_threshold' => 0.7,
            'attack_detection.thresholds.spoofing.min_orders_for_detection' => 5,
            'attack_detection.thresholds.spoofing.quick_cancel_seconds' => 30,
            'attack_detection.thresholds.spoofing.large_order_multiplier' => 3.0,
            'attack_detection.thresholds.spoofing.lookback_minutes' => 60,
            'attack_detection.thresholds.spam.orders_per_minute' => 1000, // Disable spam detection
        ]);
    }

    protected function tearDown(): void
    {
        RateLimitCounter::query()->delete();
        parent::tearDown();
    }

    public function test_detects_high_cancel_rate_with_quick_cancellations(): void
    {
        // Create 10 orders, cancel 8 of them quickly (80% cancel rate)
        $this->createOrdersWithCancelPattern(
            totalOrders: 10,
            cancelledOrders: 8,
            quickCancels: 5,
            quickCancelSeconds: 10
        );

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('order_spoofing'));
    }

    public function test_no_detection_below_cancel_rate_threshold(): void
    {
        // Create 10 orders, cancel only 5 (50% cancel rate, below 70% threshold)
        $this->createOrdersWithCancelPattern(
            totalOrders: 10,
            cancelledOrders: 5,
            quickCancels: 3,
            quickCancelSeconds: 10
        );

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('order_spoofing'));
    }

    public function test_no_detection_with_insufficient_orders(): void
    {
        // Create only 3 orders (below min_orders_for_detection of 5)
        $this->createOrdersWithCancelPattern(
            totalOrders: 3,
            cancelledOrders: 3,
            quickCancels: 3,
            quickCancelSeconds: 10
        );

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('order_spoofing'));
    }

    public function test_detects_large_orders_cancelled_quickly(): void
    {
        // Create orders with varying amounts
        // Average will be (5*1.0 + 2*10.0) / 7 = 25/7 = 3.57
        // Large threshold = 3.57 * 3.0 = 10.7
        // So we need orders > 10.7 to be "large"
        // Let's use smaller base orders to make the math work:
        // Average = (5*1.0 + 2*5.0) / 7 = 15/7 = 2.14 with multiplier 3.0 = 6.43 threshold
        // Instead, let's lower the multiplier in config for this test
        config(['attack_detection.thresholds.spoofing.large_order_multiplier' => 2.0]);

        $this->createOrderWithAmount('1.0', OrderStatus::FILLED);
        $this->createOrderWithAmount('1.0', OrderStatus::FILLED);
        $this->createOrderWithAmount('1.0', OrderStatus::OPEN);
        $this->createOrderWithAmount('1.0', OrderStatus::OPEN);
        $this->createOrderWithAmount('1.0', OrderStatus::OPEN);

        // Create 2 large orders (5.0 each) and cancel them quickly
        // Average = 15/7 = 2.14, threshold = 2.14 * 2.0 = 4.28
        // 5.0 > 4.28, so these count as "large"
        $this->createLargeOrderCancelledQuickly('5.0', 5);
        $this->createLargeOrderCancelledQuickly('5.0', 5);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('order_spoofing'));
    }

    public function test_no_detection_for_slow_cancellations(): void
    {
        // Create 10 orders, cancel 8 but with slow cancellation times (> 30 seconds)
        $this->createOrdersWithCancelPattern(
            totalOrders: 10,
            cancelledOrders: 8,
            quickCancels: 2, // Only 2 quick cancels, need 3 to trigger
            quickCancelSeconds: 10
        );

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('order_spoofing'));
    }

    public function test_spoofing_detection_includes_related_orders(): void
    {
        $this->createOrdersWithCancelPattern(
            totalOrders: 10,
            cancelledOrders: 8,
            quickCancels: 5,
            quickCancelSeconds: 10
        );

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $relatedOrders = $result->getAllRelatedOrders();
        $this->assertNotEmpty($relatedOrders);
    }

    public function test_spoofing_detection_severity_increases_with_cancel_rate(): void
    {
        // Create pattern with 90%+ cancel rate
        $this->createOrdersWithCancelPattern(
            totalOrders: 10,
            cancelledOrders: 9,
            quickCancels: 8,
            quickCancelSeconds: 5
        );

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        // High cancel rate should result in HIGH severity
        $this->assertGreaterThanOrEqual(
            \App\Enums\SecuritySeverity::HIGH->numericValue(),
            $result->highestSeverity->numericValue()
        );
    }

    public function test_old_orders_outside_lookback_window_not_considered(): void
    {
        // Create orders outside the lookback window (61 minutes ago)
        for ($i = 0; $i < 10; $i++) {
            $order = Order::create([
                'user_id' => $this->user->id,
                'symbol' => 'BTC/USD',
                'side' => OrderSide::BUY,
                'type' => 'limit',
                'price' => '50000.00',
                'amount' => '1.0',
                'filled_amount' => '0',
                'locked_funds' => '50000.00',
                'status' => OrderStatus::CANCELLED,
                'cancelled_at' => now()->subMinutes(61)->addSeconds(5),
                'created_at' => now()->subMinutes(61),
                'updated_at' => now()->subMinutes(61),
            ]);
        }

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        // Old orders should not trigger spoofing detection
        $this->assertFalse($result->hasThreatType('order_spoofing'));
    }

    private function createOrdersWithCancelPattern(
        int $totalOrders,
        int $cancelledOrders,
        int $quickCancels,
        int $quickCancelSeconds
    ): void {
        $quickCancelCount = 0;

        for ($i = 0; $i < $totalOrders; $i++) {
            $isCancelled = $i < $cancelledOrders;
            $isQuickCancel = $isCancelled && $quickCancelCount < $quickCancels;

            $status = $isCancelled ? OrderStatus::CANCELLED : OrderStatus::OPEN;
            $cancelledAt = null;

            if ($isCancelled) {
                if ($isQuickCancel) {
                    $cancelledAt = now()->subSeconds(rand(1, $quickCancelSeconds));
                    $quickCancelCount++;
                } else {
                    $cancelledAt = now()->subMinutes(rand(5, 30));
                }
            }

            Order::create([
                'user_id' => $this->user->id,
                'symbol' => 'BTC/USD',
                'side' => OrderSide::BUY,
                'type' => 'limit',
                'price' => '50000.00',
                'amount' => '1.0',
                'filled_amount' => '0',
                'locked_funds' => '50000.00',
                'status' => $status,
                'cancelled_at' => $cancelledAt,
            ]);
        }
    }

    private function createOrderWithAmount(string $amount, OrderStatus $status): void
    {
        Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00',
            'amount' => $amount,
            'filled_amount' => '0',
            'locked_funds' => bcmul('50000.00', $amount, 8),
            'status' => $status,
        ]);
    }

    private function createLargeOrderCancelledQuickly(string $amount, int $secondsBeforeCancel): void
    {
        // Create order that was created and then cancelled quickly
        // Set created_at to be before cancelled_at
        $createdAt = now()->subSeconds($secondsBeforeCancel + 2);
        $cancelledAt = now()->subSeconds(1); // Cancelled 1 second ago

        $order = Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00',
            'amount' => $amount,
            'filled_amount' => '0',
            'locked_funds' => bcmul('50000.00', $amount, 8),
            'status' => OrderStatus::CANCELLED,
            'cancelled_at' => $cancelledAt,
        ]);

        // Update created_at using query builder to bypass timestamp handling
        Order::where('id', $order->id)->update(['created_at' => $createdAt]);
    }
}
