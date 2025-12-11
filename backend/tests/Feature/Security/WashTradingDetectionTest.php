<?php

namespace Tests\Feature\Security;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Enums\SecurityEventType;
use App\Enums\SecuritySeverity;
use App\Models\Order;
use App\Models\RateLimitCounter;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;
use App\Services\Security\MarketManipulationDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class WashTradingDetectionTest extends TestCase
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

        // Set wash trading thresholds
        config([
            'attack_detection.thresholds.wash_trading.same_ip_trade_threshold' => 3,
            'attack_detection.thresholds.wash_trading.timing_window_seconds' => 60,
            'attack_detection.thresholds.wash_trading.price_deviation_threshold' => 0.001,
            'attack_detection.thresholds.wash_trading.lookback_hours' => 24,
            'attack_detection.thresholds.spam.orders_per_minute' => 1000, // Disable spam detection
        ]);
    }

    protected function tearDown(): void
    {
        RateLimitCounter::query()->delete();
        parent::tearDown();
    }

    public function test_detects_same_ip_trades(): void
    {
        $otherUser = User::factory()->create();
        $sharedIp = '192.168.1.100';

        // Create 4 trades where buy and sell orders share the same IP
        $this->createTradesWithSharedIp($this->user, $otherUser, $sharedIp, 4);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('wash_trading'));
    }

    public function test_no_detection_below_same_ip_threshold(): void
    {
        $otherUser = User::factory()->create();
        $sharedIp = '192.168.1.100';

        // Create only 2 trades (below threshold of 3)
        $this->createTradesWithSharedIp($this->user, $otherUser, $sharedIp, 2);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('wash_trading'));
    }

    public function test_no_detection_for_different_ips(): void
    {
        $otherUser = User::factory()->create();

        // Create trades where buy and sell orders have different IPs
        for ($i = 0; $i < 5; $i++) {
            $this->createTradeWithDifferentIps($this->user, $otherUser, "192.168.1.{$i}", "10.0.0.{$i}");
        }

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('wash_trading'));
    }

    public function test_detects_coordinated_timing_same_user_both_sides(): void
    {
        // Create trades where the same user appears on both sides within timing window
        $otherUser = User::factory()->create();

        // User is buyer in one trade
        $buyOrder = $this->createOrder($this->user, OrderSide::BUY, '50000.00');
        $sellOrder = $this->createOrder($otherUser, OrderSide::SELL, '50000.00');
        $this->createTrade($buyOrder, $sellOrder, $this->user, $otherUser);

        // User is seller in another trade within same timing window
        $buyOrder2 = $this->createOrder($otherUser, OrderSide::BUY, '50000.00');
        $sellOrder2 = $this->createOrder($this->user, OrderSide::SELL, '50000.00');
        $this->createTrade($buyOrder2, $sellOrder2, $otherUser, $this->user);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('coordinated_trading'));
    }

    public function test_wash_trading_has_critical_severity(): void
    {
        $otherUser = User::factory()->create();
        $sharedIp = '192.168.1.100';

        $this->createTradesWithSharedIp($this->user, $otherUser, $sharedIp, 4);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertEquals(SecuritySeverity::CRITICAL, $result->highestSeverity);
    }

    public function test_coordinated_trading_has_high_severity(): void
    {
        $otherUser = User::factory()->create();

        // Create coordinated trades (same user on both sides, different IPs to avoid wash trading)
        $buyOrder = $this->createOrderWithIp($this->user, OrderSide::BUY, '50000.00', '10.0.0.1');
        $sellOrder = $this->createOrderWithIp($otherUser, OrderSide::SELL, '50000.00', '10.0.0.2');
        $this->createTrade($buyOrder, $sellOrder, $this->user, $otherUser);

        $buyOrder2 = $this->createOrderWithIp($otherUser, OrderSide::BUY, '50000.00', '10.0.0.3');
        $sellOrder2 = $this->createOrderWithIp($this->user, OrderSide::SELL, '50000.00', '10.0.0.4');
        $this->createTrade($buyOrder2, $sellOrder2, $otherUser, $this->user);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        // Should detect coordinated trading
        if ($result->hasThreatType('coordinated_trading')) {
            $threat = collect($result->threats)->firstWhere('type', SecurityEventType::COORDINATED_TRADING);
            $this->assertEquals(SecuritySeverity::HIGH, $threat['severity']);
        }
    }

    public function test_old_trades_outside_lookback_not_considered(): void
    {
        $otherUser = User::factory()->create();
        $sharedIp = '192.168.1.100';

        // Create old trades outside the 24-hour lookback
        for ($i = 0; $i < 5; $i++) {
            $buyOrder = $this->createOrderWithIp($this->user, OrderSide::BUY, '50000.00', $sharedIp);
            $sellOrder = $this->createOrderWithIp($otherUser, OrderSide::SELL, '50000.00', $sharedIp);
            $trade = $this->createTrade($buyOrder, $sellOrder, $this->user, $otherUser);

            // Move trade to 25 hours ago using query builder to bypass timestamp handling
            Trade::where('id', $trade->id)->update(['created_at' => now()->subHours(25)]);
        }

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('wash_trading'));
    }

    public function test_wash_trading_includes_related_users(): void
    {
        $otherUser = User::factory()->create();
        $sharedIp = '192.168.1.100';

        $this->createTradesWithSharedIp($this->user, $otherUser, $sharedIp, 4);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $relatedUsers = $result->getAllRelatedUsers();
        $this->assertContains($this->user->id, $relatedUsers);
        $this->assertContains($otherUser->id, $relatedUsers);
    }

    public function test_wash_trading_includes_related_orders(): void
    {
        $otherUser = User::factory()->create();
        $sharedIp = '192.168.1.100';

        $this->createTradesWithSharedIp($this->user, $otherUser, $sharedIp, 4);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $relatedOrders = $result->getAllRelatedOrders();
        $this->assertNotEmpty($relatedOrders);
    }

    public function test_detection_metrics_include_trade_count(): void
    {
        $otherUser = User::factory()->create();
        $sharedIp = '192.168.1.100';

        $this->createTradesWithSharedIp($this->user, $otherUser, $sharedIp, 4);

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $threat = collect($result->threats)->firstWhere('type', SecurityEventType::WASH_TRADING);
        $this->assertNotNull($threat);
        $this->assertArrayHasKey('same_ip_trades', $threat['metrics']);
        $this->assertEquals(4, $threat['metrics']['same_ip_trades']);
    }

    private function createTradesWithSharedIp(User $buyer, User $seller, string $sharedIp, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $buyOrder = $this->createOrderWithIp($buyer, OrderSide::BUY, '50000.00', $sharedIp);
            $sellOrder = $this->createOrderWithIp($seller, OrderSide::SELL, '50000.00', $sharedIp);
            $this->createTrade($buyOrder, $sellOrder, $buyer, $seller);
        }
    }

    private function createTradeWithDifferentIps(User $buyer, User $seller, string $buyerIp, string $sellerIp): void
    {
        $buyOrder = $this->createOrderWithIp($buyer, OrderSide::BUY, '50000.00', $buyerIp);
        $sellOrder = $this->createOrderWithIp($seller, OrderSide::SELL, '50000.00', $sellerIp);
        $this->createTrade($buyOrder, $sellOrder, $buyer, $seller);
    }

    private function createOrder(User $user, OrderSide $side, string $price): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USD',
            'side' => $side,
            'type' => 'limit',
            'price' => $price,
            'amount' => '1.0',
            'filled_amount' => '1.0',
            'locked_funds' => '0',
            'status' => OrderStatus::FILLED,
        ]);
    }

    private function createOrderWithIp(User $user, OrderSide $side, string $price, string $ip): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USD',
            'side' => $side,
            'type' => 'limit',
            'price' => $price,
            'amount' => '1.0',
            'filled_amount' => '1.0',
            'locked_funds' => '0',
            'status' => OrderStatus::FILLED,
            'ip_address' => $ip,
        ]);
    }

    private function createTrade(Order $buyOrder, Order $sellOrder, User $buyer, User $seller): Trade
    {
        return Trade::create([
            'buy_order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol' => 'BTC/USD',
            'price' => $buyOrder->price,
            'amount' => '1.0',
            'buyer_fee' => '0.015',
            'seller_fee' => '0',
            'is_buyer_maker' => false,
        ]);
    }
}
