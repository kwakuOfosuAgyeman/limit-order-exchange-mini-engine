<?php

namespace Tests\Feature;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderBookTest extends TestCase
{
    use RefreshDatabase;

    private Symbol $symbol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->symbol = Symbol::factory()->btcUsd()->create();
    }

    // ==================== BASIC ORDERBOOK STRUCTURE ====================

    public function test_orderbook_returns_correct_structure(): void
    {
        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'symbol',
                'bids',
                'asks',
                'timestamp',
            ]);
    }

    public function test_orderbook_returns_symbol_name(): void
    {
        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $response->assertStatus(200)
            ->assertJson([
                'symbol' => 'BTC/USD',
            ]);
    }

    public function test_orderbook_returns_timestamp(): void
    {
        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $response->assertStatus(200);
        $this->assertNotNull($response->json('timestamp'));
    }

    // ==================== BID (BUY) ORDERS ====================

    public function test_orderbook_shows_buy_orders_as_bids(): void
    {
        $user = $this->createUserWithBalance();
        $this->createOrder($user, OrderSide::BUY, '50000.00', '0.1');

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('bids'));
        $this->assertCount(0, $response->json('asks'));
    }

    public function test_bids_are_sorted_by_price_descending(): void
    {
        $user = $this->createUserWithBalance('100000.00000000');

        $this->createOrder($user, OrderSide::BUY, '48000.00', '0.1');
        $this->createOrder($user, OrderSide::BUY, '50000.00', '0.1');
        $this->createOrder($user, OrderSide::BUY, '49000.00', '0.1');

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $bids = $response->json('bids');
        $this->assertEquals('50000.00000000', $bids[0]['price']);
        $this->assertEquals('49000.00000000', $bids[1]['price']);
        $this->assertEquals('48000.00000000', $bids[2]['price']);
    }

    public function test_bids_show_remaining_amount(): void
    {
        $user = $this->createUserWithBalance();
        $this->createOrder($user, OrderSide::BUY, '50000.00', '0.15');

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $this->assertEquals('0.15000000', $response->json('bids.0.amount'));
    }

    public function test_bids_show_total_value(): void
    {
        $user = $this->createUserWithBalance();
        $this->createOrder($user, OrderSide::BUY, '50000.00', '0.1');

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        // 50000 * 0.1 = 5000
        $this->assertEquals('5000.00000000', $response->json('bids.0.total'));
    }

    // ==================== ASK (SELL) ORDERS ====================

    public function test_orderbook_shows_sell_orders_as_asks(): void
    {
        $user = $this->createUserWithAsset();
        $this->createOrder($user, OrderSide::SELL, '50000.00', '0.1');

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('bids'));
        $this->assertCount(1, $response->json('asks'));
    }

    public function test_asks_are_sorted_by_price_ascending(): void
    {
        $user = $this->createUserWithAsset('10.00000000');

        $this->createOrder($user, OrderSide::SELL, '52000.00', '0.1');
        $this->createOrder($user, OrderSide::SELL, '50000.00', '0.1');
        $this->createOrder($user, OrderSide::SELL, '51000.00', '0.1');

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $asks = $response->json('asks');
        $this->assertEquals('50000.00000000', $asks[0]['price']);
        $this->assertEquals('51000.00000000', $asks[1]['price']);
        $this->assertEquals('52000.00000000', $asks[2]['price']);
    }

    public function test_asks_show_remaining_amount(): void
    {
        $user = $this->createUserWithAsset();
        $this->createOrder($user, OrderSide::SELL, '50000.00', '0.25');

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $this->assertEquals('0.25000000', $response->json('asks.0.amount'));
    }

    // ==================== ORDER STATUS FILTERING ====================

    public function test_orderbook_excludes_filled_orders(): void
    {
        $user = $this->createUserWithBalance();

        // Create an open order
        $this->createOrder($user, OrderSide::BUY, '50000.00', '0.1');

        // Create a filled order directly
        Order::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '51000.00000000',
            'amount' => '0.1',
            'filled_amount' => '0.1',
            'locked_funds' => '0',
            'status' => OrderStatus::FILLED,
            'filled_at' => now(),
        ]);

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        // Only the open order should appear
        $this->assertCount(1, $response->json('bids'));
        $this->assertEquals('50000.00000000', $response->json('bids.0.price'));
    }

    public function test_orderbook_excludes_cancelled_orders(): void
    {
        $user = $this->createUserWithBalance();

        $this->createOrder($user, OrderSide::BUY, '50000.00', '0.1');

        Order::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '51000.00000000',
            'amount' => '0.1',
            'filled_amount' => '0',
            'locked_funds' => '5100.00000000',
            'status' => OrderStatus::CANCELLED,
            'cancelled_at' => now(),
        ]);

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $this->assertCount(1, $response->json('bids'));
    }

    public function test_orderbook_excludes_expired_orders(): void
    {
        $user = $this->createUserWithBalance();

        $this->createOrder($user, OrderSide::BUY, '50000.00', '0.1');

        Order::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '51000.00000000',
            'amount' => '0.1',
            'filled_amount' => '0',
            'locked_funds' => '5100.00000000',
            'status' => OrderStatus::EXPIRED,
            'expires_at' => now()->subHour(),
        ]);

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $this->assertCount(1, $response->json('bids'));
    }

    public function test_orderbook_includes_partially_filled_orders(): void
    {
        $user = $this->createUserWithBalance();

        Order::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '1.00000000',
            'filled_amount' => '0.50000000', // Half filled
            'locked_funds' => '25000.00000000',
            'status' => OrderStatus::PARTIALLY_FILLED,
        ]);

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $this->assertCount(1, $response->json('bids'));
        // Should show remaining amount (1.0 - 0.5 = 0.5)
        $this->assertEquals('0.50000000', $response->json('bids.0.amount'));
    }

    // ==================== SYMBOL FILTERING ====================

    public function test_orderbook_only_shows_orders_for_requested_symbol(): void
    {
        Symbol::factory()->create([
            'symbol' => 'ETH/USD',
            'base_asset' => 'ETH',
            'quote_asset' => 'USD',
        ]);

        $user = $this->createUserWithBalance('200000.00000000');

        // BTC order
        Order::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '0.1',
            'filled_amount' => '0',
            'locked_funds' => '5000.00000000',
            'status' => OrderStatus::OPEN,
        ]);

        // ETH order
        Order::create([
            'user_id' => $user->id,
            'symbol' => 'ETH/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '3000.00000000',
            'amount' => '1.0',
            'filled_amount' => '0',
            'locked_funds' => '3000.00000000',
            'status' => OrderStatus::OPEN,
        ]);

        $btcResponse = $this->getJson('/api/orderbook/BTC%2FUSD');
        $ethResponse = $this->getJson('/api/orderbook/ETH%2FUSD');

        $this->assertCount(1, $btcResponse->json('bids'));
        $this->assertEquals('50000.00000000', $btcResponse->json('bids.0.price'));

        $this->assertCount(1, $ethResponse->json('bids'));
        $this->assertEquals('3000.00000000', $ethResponse->json('bids.0.price'));
    }

    // ==================== FIFO ORDERING ====================

    public function test_orders_at_same_price_are_sorted_by_creation_time(): void
    {
        $user1 = $this->createUserWithBalance();
        $user2 = $this->createUserWithBalance();

        // First order at this price
        Order::create([
            'user_id' => $user1->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '0.1',
            'filled_amount' => '0',
            'locked_funds' => '5000.00000000',
            'status' => OrderStatus::OPEN,
            'created_at' => now()->subMinutes(5),
        ]);

        // Second order at same price (created later)
        Order::create([
            'user_id' => $user2->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '0.2',
            'filled_amount' => '0',
            'locked_funds' => '10000.00000000',
            'status' => OrderStatus::OPEN,
            'created_at' => now(),
        ]);

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $bids = $response->json('bids');
        $this->assertCount(2, $bids);
        // First order (older) should come first at same price level
        $this->assertEquals('0.10000000', $bids[0]['amount']);
        $this->assertEquals('0.20000000', $bids[1]['amount']);
    }

    // ==================== EMPTY ORDERBOOK ====================

    public function test_empty_orderbook_returns_empty_arrays(): void
    {
        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $response->assertStatus(200)
            ->assertJson([
                'symbol' => 'BTC/USD',
                'bids' => [],
                'asks' => [],
            ]);
    }

    // ==================== PUBLIC ACCESS ====================

    public function test_orderbook_is_publicly_accessible(): void
    {
        // No authentication token
        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        $response->assertStatus(200);
    }

    // ==================== LIMIT ====================

    public function test_orderbook_limits_results_to_50_per_side(): void
    {
        $user = $this->createUserWithBalance('10000000.00000000');

        // Create 60 buy orders at different prices
        for ($i = 0; $i < 60; $i++) {
            Order::create([
                'user_id' => $user->id,
                'symbol' => 'BTC/USD',
                'side' => OrderSide::BUY,
                'type' => 'limit',
                'price' => (string)(40000 + $i * 100) . '.00000000',
                'amount' => '0.01000000',
                'filled_amount' => '0',
                'locked_funds' => (string)((40000 + $i * 100) * 0.01) . '.00000000',
                'status' => OrderStatus::OPEN,
            ]);
        }

        $response = $this->getJson('/api/orderbook/BTC%2FUSD');

        // Should be limited to 50
        $this->assertCount(50, $response->json('bids'));
    }

    // ==================== HELPER METHODS ====================

    private function createUserWithBalance(string $balance = '100000.00000000'): User
    {
        return User::factory()->create([
            'balance' => $balance,
            'locked_balance' => '0.00000000',
            'is_active' => true,
        ]);
    }

    private function createUserWithAsset(string $amount = '10.00000000'): User
    {
        $user = User::factory()->create([
            'balance' => '0.00000000',
            'locked_balance' => '0.00000000',
            'is_active' => true,
        ]);

        Asset::create([
            'user_id' => $user->id,
            'symbol' => 'BTC/USD',
            'amount' => $amount,
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        return $user;
    }

    private function createOrder(User $user, OrderSide $side, string $price, string $amount): Order
    {
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => $side->value,
                'price' => $price,
                'amount' => $amount,
            ]);

        $response->assertStatus(201);

        // The API returns uuid as 'id', so we need to find by uuid
        return Order::where('uuid', $response->json('order.id'))->first();
    }
}
