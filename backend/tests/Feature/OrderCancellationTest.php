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

class OrderCancellationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private string $token;
    private Symbol $symbol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'balance' => '10000.00000000',
            'locked_balance' => '0.00000000',
            'is_active' => true,
        ]);
        $this->token = $this->user->createToken('test-token')->plainTextToken;

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

    // ==================== BUY ORDER CANCELLATION ====================

    public function test_user_can_cancel_open_buy_order(): void
    {
        // Create a buy order
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);
        $orderUuid = $response->json('order.id'); // API returns uuid as 'id'

        // Cancel the order
        $cancelResponse = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid}/cancel");

        $cancelResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Order cancelled successfully',
            ]);

        // Verify order is cancelled
        $this->assertDatabaseHas('orders', [
            'uuid' => $orderUuid,
            'status' => OrderStatus::CANCELLED->value,
        ]);
    }

    public function test_buy_order_cancellation_unlocks_usd(): void
    {
        // Create a buy order (locks 5000 + 75 fee = 5075 USD)
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);
        $orderUuid = $response->json('order.id');

        $this->user->refresh();
        $this->assertEquals('5075.00000000', $this->user->locked_balance);
        $this->assertEquals('4925.00000000', $this->user->balance);

        // Cancel the order
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid}/cancel")
            ->assertStatus(200);

        // Verify USD is unlocked
        $this->user->refresh();
        $this->assertEquals('0.00000000', $this->user->locked_balance);
        $this->assertEquals('10000.00000000', $this->user->balance);
    }

    // ==================== SELL ORDER CANCELLATION ====================

    public function test_user_can_cancel_open_sell_order(): void
    {
        // Give user some BTC
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '1.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        // Create a sell order
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);
        $orderUuid = $response->json('order.id');

        // Cancel the order
        $cancelResponse = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid}/cancel");

        $cancelResponse->assertStatus(200);

        // Verify order is cancelled
        $this->assertDatabaseHas('orders', [
            'uuid' => $orderUuid,
            'status' => OrderStatus::CANCELLED->value,
        ]);
    }

    public function test_sell_order_cancellation_unlocks_asset(): void
    {
        // Give user some BTC
        $asset = Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '1.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        // Create a sell order (locks 0.1 BTC)
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);
        $orderUuid = $response->json('order.id');

        $asset->refresh();
        $this->assertEquals('0.10000000', $asset->locked_amount);
        $this->assertEquals('0.90000000', $asset->amount);

        // Cancel the order
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid}/cancel")
            ->assertStatus(200);

        // Verify asset is unlocked
        $asset->refresh();
        $this->assertEquals('0.00000000', $asset->locked_amount);
        $this->assertEquals('1.00000000', $asset->amount);
    }

    // ==================== CANCELLATION RESTRICTIONS ====================

    public function test_cannot_cancel_another_users_order(): void
    {
        $otherUser = User::factory()->create([
            'balance' => '10000.00000000',
            'is_active' => true,
        ]);
        $otherToken = $otherUser->createToken('test-token')->plainTextToken;

        // Other user creates order
        $response = $this->withHeaders(['Authorization' => "Bearer {$otherToken}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);
        $orderUuid = $response->json('order.id');

        $this->resetAuth();

        // Try to cancel as different user
        $cancelResponse = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid}/cancel");

        // 403 Forbidden - user is not authorized to cancel another user's order
        $cancelResponse->assertStatus(403);
    }

    public function test_cannot_cancel_filled_order(): void
    {
        // Create and fill an order manually
        $order = Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '0.10000000',
            'filled_amount' => '0.10000000',
            'locked_funds' => '5000.00000000',
            'status' => OrderStatus::FILLED,
            'filled_at' => now(),
        ]);

        $cancelResponse = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$order->uuid}/cancel");

        $cancelResponse->assertStatus(422)
            ->assertJson([
                'error' => 'order_error',
            ]);
    }

    public function test_cannot_cancel_already_cancelled_order(): void
    {
        // Create and cancel an order
        $order = Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '0.10000000',
            'filled_amount' => '0.00000000',
            'locked_funds' => '5000.00000000',
            'status' => OrderStatus::CANCELLED,
            'cancelled_at' => now(),
        ]);

        $cancelResponse = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$order->uuid}/cancel");

        $cancelResponse->assertStatus(422)
            ->assertJson([
                'error' => 'order_error',
            ]);
    }

    public function test_cannot_cancel_expired_order(): void
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '0.10000000',
            'filled_amount' => '0.00000000',
            'locked_funds' => '5000.00000000',
            'status' => OrderStatus::EXPIRED,
            'expires_at' => now()->subHour(),
        ]);

        $cancelResponse = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$order->uuid}/cancel");

        $cancelResponse->assertStatus(422)
            ->assertJson([
                'error' => 'order_error',
            ]);
    }

    // ==================== CANCELLATION TIMESTAMP ====================

    public function test_cancelled_order_has_cancelled_at_timestamp(): void
    {
        // Create a buy order
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);
        $orderUuid = $response->json('order.id');

        // Cancel the order
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid}/cancel")
            ->assertStatus(200);

        $order = Order::where('uuid', $orderUuid)->first();
        $this->assertNotNull($order->cancelled_at);
    }

    // ==================== CANCELLATION LEDGER ENTRY ====================

    public function test_buy_order_cancellation_records_ledger_entry(): void
    {
        // Create a buy order
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);
        $orderUuid = $response->json('order.id');

        // Cancel the order
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid}/cancel")
            ->assertStatus(200);

        // Verify ledger entry for unlock
        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'currency' => 'USD',
            'reference_type' => 'order_unlock',
        ]);
    }

    public function test_sell_order_cancellation_records_ledger_entry(): void
    {
        // Give user some BTC
        Asset::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'amount' => '1.00000000',
            'locked_amount' => '0.00000000',
            'version' => 1,
        ]);

        // Create a sell order
        $response = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'sell',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);

        $response->assertStatus(201);
        $orderUuid = $response->json('order.id');

        // Cancel the order
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid}/cancel")
            ->assertStatus(200);

        // Verify ledger entry for unlock
        $this->assertDatabaseHas('balance_ledger', [
            'user_id' => $this->user->id,
            'currency' => 'BTC/USD',
            'reference_type' => 'order_unlock',
        ]);
    }

    // ==================== MULTIPLE ORDER CANCELLATION ====================

    public function test_cancelling_one_order_does_not_affect_others(): void
    {
        // Create two buy orders
        $response1 = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '50000.00',
                'amount' => '0.1',
            ]);
        $response1->assertStatus(201);
        $orderUuid1 = $response1->json('order.id');

        $response2 = $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson('/api/orders', [
                'symbol' => 'BTC/USD',
                'side' => 'buy',
                'price' => '49000.00',
                'amount' => '0.05',
            ]);
        $response2->assertStatus(201);
        $orderUuid2 = $response2->json('order.id');

        $this->user->refresh();
        // Order 1: 5000 + 75 fee = 5075 locked
        // Order 2: 2450 + 36.75 fee = 2486.75 locked
        // Total: 7561.75
        $this->assertEquals('7561.75000000', $this->user->locked_balance);

        // Cancel first order
        $this->withHeaders(['Authorization' => "Bearer {$this->token}"])
            ->postJson("/api/orders/{$orderUuid1}/cancel")
            ->assertStatus(200);

        $this->user->refresh();
        // Only Order 2's 2486.75 should remain locked
        $this->assertEquals('2486.75000000', $this->user->locked_balance);

        // Order 2 should still be open
        $this->assertDatabaseHas('orders', [
            'uuid' => $orderUuid2,
            'status' => OrderStatus::OPEN->value,
        ]);
    }

    // ==================== CANCELLATION REQUIRES AUTHENTICATION ====================

    public function test_order_cancellation_requires_authentication(): void
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'symbol' => 'BTC/USD',
            'side' => OrderSide::BUY,
            'type' => 'limit',
            'price' => '50000.00000000',
            'amount' => '0.10000000',
            'filled_amount' => '0.00000000',
            'locked_funds' => '5000.00000000',
            'status' => OrderStatus::OPEN,
        ]);

        $response = $this->postJson("/api/orders/{$order->uuid}/cancel");

        $response->assertStatus(401);
    }
}
