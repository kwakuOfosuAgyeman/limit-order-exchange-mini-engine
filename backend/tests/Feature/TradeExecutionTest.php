<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\BalanceLedger;
use App\Models\Symbol;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeExecutionTest extends TestCase
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

        // Buyer with USD
        $this->buyer = User::factory()->create([
            'balance' => '100000.00000000',
            'locked_balance' => '0.00000000',
            'is_active' => true,
        ]);
        $this->buyerToken = $this->buyer->createToken('test-token')->plainTextToken;

        // Seller with BTC
        $this->seller = User::factory()->create([
            'balance' => '0.00000000',
            'locked_balance' => '0.00000000',
            'is_active' => true,
        ]);
        $this->sellerToken = $this->seller->createToken('test-token')->plainTextToken;

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

    // ==================== FEE CALCULATION ====================

    public function test_fee_is_1_5_percent_of_trade_value(): void
    {
        // Trade: 0.1 BTC at 50000 = 5000 USD
        // Fee: 5000 * 0.015 = 75 USD

        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $trade = Trade::first();
        $this->assertEquals('75.00000000', $trade->buyer_fee);
    }

    public function test_fee_applies_to_trade_execution_price_not_order_price(): void
    {
        // Seller offers at 49000, buyer willing to pay 50000
        // Trade executes at maker's price (49000)
        // Fee should be based on 49000, not 50000

        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '49000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $trade = Trade::first();
        // 49000 * 0.1 = 4900, fee = 4900 * 0.015 = 73.50
        $this->assertEquals('73.50000000', $trade->buyer_fee);
    }

    public function test_seller_fee_is_zero(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $trade = Trade::first();
        $this->assertEquals('0.00000000', $trade->seller_fee);
    }

    public function test_fee_currency_is_usd_for_buyer(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $trade = Trade::first();
        $this->assertEquals('USD', $trade->fee_currency_buyer);
        $this->assertEquals('USD', $trade->fee_currency_seller);
    }

    // ==================== BALANCE TRANSFERS ====================

    public function test_buyer_final_balance_reflects_trade_cost_plus_fee(): void
    {
        $initialBuyerBalance = $this->buyer->balance;

        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->buyer->refresh();

        // Trade cost: 5000, Fee: 75, Total: 5075
        // Final: 100000 - 5075 = 94925
        $this->assertEquals('94925.00000000', $this->buyer->balance);
        $this->assertEquals('0.00000000', $this->buyer->locked_balance);
    }

    public function test_seller_receives_full_trade_value(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->seller->refresh();

        // Seller receives full trade value, no fee deducted
        $this->assertEquals('5000.00000000', $this->seller->balance);
    }

    public function test_buyer_receives_full_asset_amount(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $buyerAsset = Asset::where('user_id', $this->buyer->id)
            ->where('symbol', 'BTC/USD')
            ->first();

        // Buyer receives full amount, fee is in USD
        $this->assertEquals('0.10000000', $buyerAsset->amount);
    }

    public function test_seller_asset_is_fully_debited(): void
    {
        $initialSellerAsset = Asset::where('user_id', $this->seller->id)
            ->where('symbol', 'BTC/USD')
            ->first();
        $initialAmount = $initialSellerAsset->amount;

        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $initialSellerAsset->refresh();

        // 10.0 - 0.1 = 9.9
        $this->assertEquals('9.90000000', $initialSellerAsset->amount);
        $this->assertEquals('0.00000000', $initialSellerAsset->locked_amount);
    }

    // ==================== PRICE IMPROVEMENT REFUND ====================

    public function test_buyer_gets_price_improvement_refund(): void
    {
        // Buyer willing to pay 50000, seller offers at 48000
        // Buyer locks 5000 (50000 * 0.1), but only pays 4800 (48000 * 0.1) + fee

        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '48000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->buyer->refresh();

        // Locked: 5000
        // Actual trade value: 4800
        // Fee: 4800 * 0.015 = 72
        // Total spent: 4872
        // Refund: 5000 - 4872 = 128
        // Final: 100000 - 4872 = 95128
        $this->assertEquals('95128.00000000', $this->buyer->balance);
    }

    public function test_refund_is_recorded_in_ledger(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '48000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->buyer->id,
            'currency' => 'USD',
            'reference_type' => BalanceLedger::TYPE_REFUND,
        ]);
    }

    // ==================== LEDGER AUDIT TRAIL ====================

    public function test_trade_creates_ledger_entries_for_buyer(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Buyer should have:
        // 1. Order lock (USD)
        // 2. Trade debit from locked (USD)
        // 3. Trade credit (BTC asset)

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->buyer->id,
            'currency' => 'USD',
            'reference_type' => BalanceLedger::TYPE_ORDER_LOCK,
        ]);

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->buyer->id,
            'currency' => 'USD',
            'reference_type' => BalanceLedger::TYPE_TRADE_DEBIT,
        ]);

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->buyer->id,
            'currency' => 'BTC/USD',
            'reference_type' => BalanceLedger::TYPE_TRADE_CREDIT,
        ]);
    }

    public function test_trade_creates_ledger_entries_for_seller(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Seller should have:
        // 1. Order lock (BTC asset)
        // 2. Trade debit from locked (BTC asset)
        // 3. Trade credit (USD)

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->seller->id,
            'currency' => 'BTC/USD',
            'reference_type' => BalanceLedger::TYPE_ORDER_LOCK,
        ]);

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->seller->id,
            'currency' => 'BTC/USD',
            'reference_type' => BalanceLedger::TYPE_TRADE_DEBIT,
        ]);

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->seller->id,
            'currency' => 'USD',
            'reference_type' => BalanceLedger::TYPE_TRADE_CREDIT,
        ]);
    }

    // ==================== TRADE HISTORY ====================

    public function test_buyer_can_see_trade_in_history(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->getJson('/api/trades');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'trades' => [
                    '*' => [
                        'id',
                        'symbol',
                        'side',
                        'price',
                        'amount',
                        'quote_amount',
                        'fee',
                        'fee_currency',
                        'is_maker',
                        'created_at',
                    ],
                ],
            ]);

        $this->assertEquals(1, count($response->json('trades')));
    }

    public function test_seller_can_see_trade_in_history(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->resetAuth();

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->getJson('/api/trades');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('trades')));
    }

    public function test_trade_history_can_be_filtered_by_symbol(): void
    {
        // Create ETH symbol and give seller ETH
        Symbol::factory()->create([
            'symbol' => 'ETH/USD',
            'base_asset' => 'ETH',
            'quote_asset' => 'USD',
        ]);

        Asset::create([
            'user_id' => $this->seller->id,
            'symbol' => 'ETH/USD',
            'amount' => '100.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        // BTC trade
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $this->resetAuth();

        // ETH trade
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'ETH/USD',
                'side' => 'sell',
                'price' => '3000.00',
                'amount' => '1.0',
            ]);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'ETH/USD',
                'side' => 'buy',
                'price' => '3000.00',
                'amount' => '1.0',
            ]);

        // Filter by BTC/USD
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->getJson('/api/trades?symbol=BTC/USD');

        $response->assertStatus(200);
        $this->assertEquals(1, count($response->json('trades')));
        $this->assertEquals('BTC/USD', $response->json('trades.0.symbol'));
    }

    // ==================== MULTIPLE TRADES ====================

    public function test_multiple_sequential_trades_update_balances_correctly(): void
    {
        // First trade: 0.1 BTC at 50000
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $this->resetAuth();

        // Second trade: 0.2 BTC at 51000
        $this->withHeaders(['Authorization' => "Bearer {$this->sellerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '51000.00',
                'amount' => '0.2',
            ]);

        $this->resetAuth();

        $this->withHeaders(['Authorization' => "Bearer {$this->buyerToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '51000.00',
                'amount' => '0.2',
            ]);

        $this->buyer->refresh();
        $this->seller->refresh();

        // Trade 1: 5000 + 75 fee = 5075 deducted
        // Trade 2: 10200 + 153 fee = 10353 deducted
        // Total: 15428 deducted, 84572 remaining
        $this->assertEquals('84572.00000000', $this->buyer->balance);

        // Seller receives: 5000 + 10200 = 15200
        $this->assertEquals('15200.00000000', $this->seller->balance);

        // Buyer asset: 0.1 + 0.2 = 0.3 BTC
        $buyerAsset = Asset::where('user_id', $this->buyer->id)
            ->where('symbol', 'BTC/USD')
            ->first();
        $this->assertEquals('0.30000000', $buyerAsset->amount);

        // Seller asset: 10 - 0.1 - 0.2 = 9.7 BTC
        $sellerAsset = Asset::where('user_id', $this->seller->id)
            ->where('symbol', 'BTC/USD')
            ->first();
        $this->assertEquals('9.70000000', $sellerAsset->amount);

        // Verify 2 trades created
        $this->assertEquals(2, Trade::count());
    }
}
