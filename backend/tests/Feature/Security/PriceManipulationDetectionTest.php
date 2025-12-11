<?php

namespace Tests\Feature\Security;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
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

class PriceManipulationDetectionTest extends TestCase
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

        // Set price manipulation thresholds
        config([
            'attack_detection.thresholds.price_manipulation.deviation_from_market' => 0.05, // 5%
            'attack_detection.thresholds.price_manipulation.extreme_deviation' => 0.20, // 20%
            'attack_detection.thresholds.spam.orders_per_minute' => 1000, // Disable spam detection
        ]);
    }

    protected function tearDown(): void
    {
        RateLimitCounter::query()->delete();
        parent::tearDown();
    }

    public function test_detects_price_manipulation_above_threshold(): void
    {
        // Create a last trade at $50,000
        $this->createTradeAtPrice('50000.00');

        // Try to place order at $55,000 (10% deviation, above 5% threshold)
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '55000.00',
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('price_manipulation'));
    }

    public function test_detects_extreme_price_manipulation_as_critical(): void
    {
        // Create a last trade at $50,000
        $this->createTradeAtPrice('50000.00');

        // Try to place order at $65,000 (30% deviation, above 20% extreme threshold)
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '65000.00',
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('price_manipulation'));
        $this->assertEquals(SecuritySeverity::CRITICAL, $result->highestSeverity);
    }

    public function test_no_detection_within_acceptable_deviation(): void
    {
        // Create a last trade at $50,000
        $this->createTradeAtPrice('50000.00');

        // Try to place order at $51,000 (2% deviation, below 5% threshold)
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '51000.00',
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('price_manipulation'));
    }

    public function test_detects_low_price_manipulation(): void
    {
        // Create a last trade at $50,000
        $this->createTradeAtPrice('50000.00');

        // Try to place order at $40,000 (20% below, extreme deviation)
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '40000.00',
            'amount' => '1.0',
            'side' => 'sell',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('price_manipulation'));
    }

    public function test_uses_orderbook_midprice_when_no_trades(): void
    {
        // Create buy orders (bids) at $49,000
        Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '49000.00',
            'amount' => '1.0',
            'filled_amount' => '0',
            'locked_funds' => '49000.00',
            'status' => OrderStatus::OPEN,
        ]);

        // Create sell orders (asks) at $51,000
        $seller = User::factory()->create();
        Order::create([
            'user_id' => $seller->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::SELL,
            'type' => 'limit',
            'price' => '51000.00',
            'amount' => '1.0',
            'filled_amount' => '0',
            'locked_funds' => '1.0',
            'status' => OrderStatus::OPEN,
        ]);

        // Mid price should be $50,000
        // Try to place order at $60,000 (20% deviation)
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '60000.00',
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);
        $this->assertTrue($result->hasThreatType('price_manipulation'));
    }

    public function test_no_detection_without_market_reference(): void
    {
        // No trades, no orders - no market reference
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '999999.00',
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        // Cannot detect price manipulation without market reference
        $this->assertFalse($result->hasThreatType('price_manipulation'));
    }

    public function test_no_detection_without_price_in_request(): void
    {
        $this->createTradeAtPrice('50000.00');

        // Request without price parameter
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('price_manipulation'));
    }

    public function test_no_detection_without_symbol_in_request(): void
    {
        $this->createTradeAtPrice('50000.00');

        // Request without symbol parameter
        $request = Request::create('/api/orders', 'POST', [
            'price' => '999999.00',
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertFalse($result->hasThreatType('price_manipulation'));
    }

    public function test_detection_metrics_include_deviation_info(): void
    {
        $this->createTradeAtPrice('50000.00');

        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '60000.00', // 20% deviation
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->detected);

        $threat = collect($result->threats)->firstWhere('type', \App\Enums\SecurityEventType::PRICE_MANIPULATION);
        $this->assertNotNull($threat);
        $this->assertArrayHasKey('order_price', $threat['metrics']);
        $this->assertArrayHasKey('market_price', $threat['metrics']);
        $this->assertArrayHasKey('deviation_percent', $threat['metrics']);
    }

    public function test_uses_best_bid_when_only_bids_exist(): void
    {
        // Create only buy orders
        Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00',
            'amount' => '1.0',
            'filled_amount' => '0',
            'locked_funds' => '50000.00',
            'status' => OrderStatus::OPEN,
        ]);

        // Try extreme price order
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '65000.00', // 30% above best bid
            'amount' => '1.0',
            'side' => 'buy',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->hasThreatType('price_manipulation'));
    }

    public function test_uses_best_ask_when_only_asks_exist(): void
    {
        // Create only sell orders
        $seller = User::factory()->create();
        Order::create([
            'user_id' => $seller->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::SELL,
            'type' => 'limit',
            'price' => '50000.00',
            'amount' => '1.0',
            'filled_amount' => '0',
            'locked_funds' => '1.0',
            'status' => OrderStatus::OPEN,
        ]);

        // Try extreme low price order
        $request = Request::create('/api/orders', 'POST', [
            'symbol' => 'BTC/USD',
            'price' => '35000.00', // 30% below best ask
            'amount' => '1.0',
            'side' => 'sell',
        ]);

        $result = $this->detector->analyze($request, $this->user);

        $this->assertTrue($result->hasThreatType('price_manipulation'));
    }

    private function createTradeAtPrice(string $price): void
    {
        $buyer = User::factory()->create();
        $seller = User::factory()->create();

        $buyOrder = Order::create([
            'user_id' => $buyer->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => $price,
            'amount' => '1.0',
            'filled_amount' => '1.0',
            'locked_funds' => '0',
            'status' => OrderStatus::FILLED,
        ]);

        $sellOrder = Order::create([
            'user_id' => $seller->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::SELL,
            'type' => 'limit',
            'price' => $price,
            'amount' => '1.0',
            'filled_amount' => '1.0',
            'locked_funds' => '0',
            'status' => OrderStatus::FILLED,
        ]);

        Trade::create([
            'buy_order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol' => 'BTC/USD',
            'price' => $price,
            'amount' => '1.0',
            'buyer_fee' => '0.015',
            'seller_fee' => '0',
            'is_buyer_maker' => false,
        ]);
    }
}
