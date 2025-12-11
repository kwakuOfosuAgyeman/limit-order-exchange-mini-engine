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

class OrderCreationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Symbol $symbol;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable attack detection for core functionality tests
        config(['attack_detection.enabled' => false]);

        $this->user = User::factory()->create([
            'balance' => '10000.00000000',
            'locked_balance' => '0.00000000',
            'is_active' => true,
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;

        $this->symbol = Symbol::factory()->btcUsd()->create();
    }

    // ==================== BUY ORDER CREATION ====================

    public function test_user_can_create_buy_order(): void
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'message',
                'order' => [
                    'id', // API returns uuid as 'id'
                    'symbol',
                    'side',
                    'type',
                    'price',
                    'amount',
                    'filled_amount',
                    'status',
                    'created_at',
                ],
            ]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY->value,
            'price' => '50000.00000000',
            'amount' => '0.10000000',
            'status' => OrderStatus::OPEN->value,
        ]);
    }

    public function test_buy_order_locks_usd_balance(): void
    {
        $initialBalance = $this->user->balance;

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);

        $this->user->refresh();

        // Price * amount = 50000 * 0.1 = 5000 + 1.5% fee (75) = 5075 should be locked
        $expectedLocked = '5075.00000000';
        $expectedAvailable = bcsub($initialBalance, $expectedLocked, 8);

        $this->assertEquals($expectedAvailable, $this->user->balance);
        $this->assertEquals($expectedLocked, $this->user->locked_balance);
    }

    public function test_buy_order_fails_with_insufficient_balance(): void
    {
        // User has 10000 USD, try to buy 1 BTC at 50000 = 50000 + 750 fee = 50750 USD needed
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '1.0',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'insufficient_balance',
            ]);

        // Balance should remain unchanged
        $this->user->refresh();
        $this->assertEquals('10000.00000000', $this->user->balance);
        $this->assertEquals('0.00000000', $this->user->locked_balance);
    }

    // ==================== SELL ORDER CREATION ====================

    public function test_user_can_create_sell_order(): void
    {
        // Give user some BTC first
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '1.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::SELL->value,
            'price' => '50000.00000000',
            'amount' => '0.10000000',
            'status' => OrderStatus::OPEN->value,
        ]);
    }

    public function test_sell_order_locks_asset_balance(): void
    {
        // Give user 1 BTC
        $asset = Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '1.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);

        $asset->refresh();

        // 0.1 BTC should be locked
        $this->assertEquals('0.90000000', $asset->amount);
        $this->assertEquals('0.10000000', $asset->locked_amount);
    }

    public function test_sell_order_fails_with_insufficient_asset_balance(): void
    {
        // User has no BTC
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'insufficient_balance',
            ]);
    }

    public function test_sell_order_fails_when_asset_partially_insufficient(): void
    {
        // Give user 0.05 BTC, try to sell 0.1 BTC
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '0.05000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'insufficient_balance',
            ]);
    }

    // ==================== ORDER VALIDATION ====================

    public function test_order_fails_for_inactive_symbol(): void
    {
        Symbol::factory()->create([
            'symbol' => 'ETH/USD',
            'base_asset' => 'ETH',
            'quote_asset' => 'USD',
            'is_active' => false,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'ETH/USD',
                'side' => 'buy',
                'price' => '3000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['symbol']);
    }

    public function test_order_fails_for_trading_disabled_symbol(): void
    {
        Symbol::factory()->create([
            'symbol' => 'ETH/USD',
            'base_asset' => 'ETH',
            'quote_asset' => 'USD',
            'is_active' => true,
            'trading_enabled' => false,
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'ETH/USD',
                'side' => 'buy',
                'price' => '3000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['symbol']);
    }

    public function test_order_fails_below_minimum_trade_amount(): void
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.000001', // Below min_trade_amount of 0.00001
            ]);

        $response->assertStatus(422);
    }

    public function test_order_fails_above_maximum_trade_amount(): void
    {
        // Give user enough balance for a large order
        $this->user->update(['balance' => '100000000.00000000']);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '10000.0', // Above max_trade_amount of 1000
            ]);

        $response->assertStatus(422);
    }

    public function test_order_fails_for_nonexistent_symbol(): void
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'FAKE/USD',
                'side' => 'buy',
                'price' => '100.00',
                'amount' => '1.0',
            ]);

        $response->assertStatus(422);
    }

    // ==================== USER TRADING STATUS ====================

    public function test_order_fails_for_inactive_user(): void
    {
        $this->user->update(['is_active' => false]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'order_error',
            ]);
    }

    public function test_order_fails_for_suspended_user(): void
    {
        $this->user->update([
            'suspended_at' => now(),
            'suspension_reason' => 'Suspicious activity',
        ]);

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'order_error',
            ]);
    }

    // ==================== ORDER UUID ====================

    public function test_order_is_assigned_uuid(): void
    {
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);

        $uuid = $response->json('order.id'); // API returns uuid as 'id'
        $this->assertNotNull($uuid);
        $this->assertEquals(36, strlen($uuid)); // UUID format
    }

    public function test_client_order_id_is_saved(): void
    {
        $clientOrderId = 'my-custom-order-123';

        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
                'client_order_id' => $clientOrderId,
            ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('orders', [
            'client_order_id' => $clientOrderId,
        ]);
    }

    // ==================== MULTIPLE ORDERS ====================

    public function test_user_can_create_multiple_buy_orders(): void
    {
        // First order: 5000 + 75 fee = 5075 USD locked
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Second order: 2500 + 37.50 fee = 2537.50 USD locked (total 7612.50)
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.05',
            ])
            ->assertStatus(201);

        $this->user->refresh();
        $this->assertEquals('2387.50000000', $this->user->balance);
        $this->assertEquals('7612.50000000', $this->user->locked_balance);

        $this->assertEquals(2, Order::where('user_id', $this->user->id)->count());
    }

    public function test_user_cannot_create_order_exceeding_remaining_balance(): void
    {
        // First order: 5000 + 75 fee = 5075 USD locked (4925 remaining)
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        // Second order: try to lock 6000 + 90 fee = 6090 USD (only 4925 available)
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '60000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => 'insufficient_balance',
            ]);
    }

    // ==================== LEDGER RECORDING ====================

    public function test_order_creation_records_ledger_entry(): void
    {
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'currency' => 'USD',
            'reference_type' => 'order_lock',
        ]);
    }

    // ==================== ORDER REQUIRES AUTHENTICATION ====================

    public function test_order_creation_requires_authentication(): void
    {
        $response = $this->postJson('/api/orders', [
            'symbol' => 'BTC/USD',
            'side' => 'buy',
            'price' => '50000.00',
            'amount' => '0.1',
        ]);

        $response->assertStatus(401);
    }
}
