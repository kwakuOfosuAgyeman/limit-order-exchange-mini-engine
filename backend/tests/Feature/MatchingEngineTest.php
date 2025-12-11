<?php

namespace Tests\Feature;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Models\Asset;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MatchingEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;
    private User $seller;
    private string $buyerToken;
    private string $sellerToken;
    private Symbol $symbol;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable attack detection for core functionality tests
        config(['attack_detection.enabled' => false]);

        // Create buyer with USD
        $this->buyer = User::factory()->create([
            'balance' => '100000.00000000',
            'locked_balance' => '0.00000000',
            'is_active' => true,
        ]);
        $this->buyerToken = $this->buyer->createToken('test-token')->plainTextToken;

        // Create seller with BTC
        $this->seller = User::factory()->create([
            'balance' => '0.00000000',
            'locked_balance' => '0.00000000',
            'is_active' => true,
        ]);
        $this->sellerToken = $this->seller->createToken('test-token')->plainTextToken;

        // Give seller 10 BTC
        Asset::create([
            'user_id' => $this->seller->id,
            'symbol' => 'BTC/USD',
            'amount' => '10.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $this->symbol = Symbol::factory()->btcUsd()->create();
    }

    /**
     * Helper to clear auth cache between requests with different users.
     */
    private function resetAuth(): void
    {
        auth()->guard('sanctum')->forgetUser();
        $this->app['auth']->forgetGuards();
    }

    // ==================== BASIC MATCHING ====================

    public function test_buy_order_matches_with_existing_sell_order(): void
    {
        // Seller places sell order first (resting order)
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Reset auth cache before switching users
        $this->resetAuth();

        // Buyer places buy order at matching price
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);

        // Verify trade was created
        $this->assertEquals(1, Trade::count());

        // Both orders should be filled
        $this->assertEquals(2, Order::where('status', OrderStatus::FILLED)->count());
    }

    public function test_sell_order_matches_with_existing_buy_order(): void
    {
        // Buyer places buy order first (resting order)
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Seller places sell order at matching price
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);

        // Verify trade was created
        $this->assertEquals(1, Trade::count());
    }

    public function test_buy_order_matches_with_lower_priced_sell_order(): void
    {
        // Seller places sell order at 49000
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '49000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places buy order at 50000 (willing to pay more)
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Trade should execute at maker's (seller's) price of 49000
        $trade = Trade::first();
        $this->assertNotNull($trade);
        $this->assertEquals('49000.00000000', $trade->price);
    }

    public function test_sell_order_matches_with_higher_priced_buy_order(): void
    {
        // Buyer places buy order at 51000
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '51000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Seller places sell order at 50000
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Trade should execute at maker's (buyer's) price of 51000
        $trade = Trade::first();
        $this->assertNotNull($trade);
        $this->assertEquals('51000.00000000', $trade->price);
    }

    // ==================== NON-MATCHING SCENARIOS ====================

    public function test_buy_order_does_not_match_with_higher_priced_sell(): void
    {
        // Seller wants 55000
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '55000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer only willing to pay 50000
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // No trade should be created
        $this->assertEquals(0, Trade::count());

        // Both orders should remain open
        $this->assertEquals(2, Order::where('status', OrderStatus::OPEN)->count());
    }

    public function test_sell_order_does_not_match_with_lower_priced_buy(): void
    {
        // Buyer willing to pay 45000
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '45000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Seller wants at least 50000
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // No trade should be created
        $this->assertEquals(0, Trade::count());
    }

    public function test_orders_do_not_match_with_different_amounts(): void
    {
        // Seller offers 0.2 BTC
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.2',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer wants only 0.1 BTC (different amount - no match in full-match mode)
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // No trade (full match only - amounts must be equal)
        $this->assertEquals(0, Trade::count());
    }

    // ==================== BALANCE VERIFICATION AFTER MATCH ====================

    public function test_buyer_receives_asset_after_match(): void
    {
        // Seller places sell order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places matching buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Buyer should now have BTC
        $buyerAsset = Asset::where('user_id', $this->buyer->id)
            ->where('symbol', 'BTC/USD')
            ->first();

        $this->assertNotNull($buyerAsset);
        $this->assertEquals('0.10000000', $buyerAsset->amount);
    }

    public function test_seller_receives_usd_after_match(): void
    {
        // Seller places sell order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places matching buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Seller should receive USD (0.1 BTC * 50000 = 5000 USD)
        $this->seller->refresh();
        $this->assertEquals('5000.00000000', $this->seller->balance);
    }

    public function test_buyer_locked_balance_is_debited_after_match(): void
    {
        // Seller places sell order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->buyer->refresh();
        $initialBalance = $this->buyer->balance;

        // Buyer places matching buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->buyer->refresh();

        // Locked balance should be 0 after trade execution
        $this->assertEquals('0.00000000', $this->buyer->locked_balance);
    }

    public function test_seller_locked_asset_is_debited_after_match(): void
    {
        // Seller places sell order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places matching buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $sellerAsset = Asset::where('user_id', $this->seller->id)
            ->where('symbol', 'BTC/USD')
            ->first();

        // Locked amount should be 0 after trade execution
        $this->assertEquals('0.00000000', $sellerAsset->locked_amount);
        // Available amount should be reduced (10 - 0.1 = 9.9)
        $this->assertEquals('9.90000000', $sellerAsset->amount);
    }

    // ==================== FEE CALCULATION ====================

    public function test_buyer_pays_fee_on_match(): void
    {
        // Seller places sell order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $initialBuyerBalance = '100000.00000000';

        // Buyer places matching buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->buyer->refresh();

        // Trade value: 5000 USD
        // Fee (1.5%): 75 USD
        // Total deducted: 5075 USD
        // Remaining balance: 100000 - 5075 = 94925
        $this->assertEquals('94925.00000000', $this->buyer->balance);

        // Verify trade record has correct fee
        $trade = Trade::first();
        $this->assertEquals('75.00000000', $trade->buyer_fee);
        $this->assertEquals('0.00000000', $trade->seller_fee);
    }

    public function test_seller_pays_no_fee(): void
    {
        // Seller places sell order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places matching buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Seller receives full trade value (no fee deduction)
        $this->seller->refresh();
        $this->assertEquals('5000.00000000', $this->seller->balance);
    }

    // ==================== PRICE IMPROVEMENT ====================

    public function test_buyer_gets_refund_for_price_improvement(): void
    {
        // Seller places sell order at 49000
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '49000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places buy order at 50000 (willing to pay more)
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->buyer->refresh();

        // Buyer locked: 50000 * 0.1 = 5000
        // Actual cost: 49000 * 0.1 = 4900
        // Fee (1.5% of 4900): 73.50
        // Total spent: 4973.50
        // Refund: 5000 - 4973.50 = 26.50
        // Final balance: 100000 - 4973.50 = 95026.50
        $this->assertEquals('95026.50000000', $this->buyer->balance);
    }

    // ==================== TRADE RECORD ====================

    public function test_trade_record_is_created_on_match(): void
    {
        // Seller places sell order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places matching buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $trade = Trade::first();
        $this->assertNotNull($trade);
        $this->assertEquals($this->buyer->id, $trade->buyer_id);
        $this->assertEquals($this->seller->id, $trade->seller_id);
        $this->assertEquals('BTC/USD', $trade->symbol);
        $this->assertEquals('50000.00000000', $trade->price);
        $this->assertEquals('0.10000000', $trade->amount);
    }

    public function test_trade_has_uuid(): void
    {
        // Seller places sell order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places matching buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $trade = Trade::first();
        $this->assertNotNull($trade->uuid);
        $this->assertEquals(36, strlen($trade->uuid));
    }

    // ==================== MAKER/TAKER IDENTIFICATION ====================

    public function test_incoming_buy_order_is_taker(): void
    {
        // Seller places sell order first (maker)
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places matching buy order (taker)
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $trade = Trade::first();
        // Seller was maker, so buyer is NOT maker
        $this->assertFalse($trade->is_buyer_maker);
    }

    public function test_incoming_sell_order_is_taker(): void
    {
        // Buyer places buy order first (maker)
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Seller places matching sell order (taker)
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $trade = Trade::first();
        // Buyer was maker
        $this->assertTrue($trade->is_buyer_maker);
    }

    // ==================== FIFO ORDERING ====================

    public function test_oldest_order_at_same_price_matches_first(): void
    {
        // Create a second seller
        $seller2 = User::factory()->create([
            'balance' => '0.00000000',
            'is_active' => true,
        ]);
        Asset::create([
            'user_id' => $seller2->id,
            'symbol' => 'BTC/USD',
            'amount' => '10.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);
        $seller2Token = $seller2->createToken('test-token')->plainTextToken;

        // First seller places order
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Second seller places order at same price (later)
        $this->withHeaders(['Authorization' => "Bearer {$seller2Token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places matching order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // First seller should be matched (FIFO)
        $trade = Trade::first();
        $this->assertEquals($this->seller->id, $trade->seller_id);
    }

    // ==================== BEST PRICE MATCHING ====================

    public function test_best_price_sell_order_matches_first(): void
    {
        // Create a second seller
        $seller2 = User::factory()->create([
            'balance' => '0.00000000',
            'is_active' => true,
        ]);
        Asset::create([
            'user_id' => $seller2->id,
            'symbol' => 'BTC/USD',
            'amount' => '10.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);
        $seller2Token = $seller2->createToken('test-token')->plainTextToken;

        // First seller at 50000
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Second seller at better (lower) price 49000
        $this->withHeaders(['Authorization' => "Bearer {$seller2Token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '49000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        // Buyer places buy order
        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '51000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Second seller (better price) should be matched
        $trade = Trade::first();
        $this->assertEquals($seller2->id, $trade->seller_id);
        $this->assertEquals('49000.00000000', $trade->price);
    }
}
