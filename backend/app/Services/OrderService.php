<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\OrderBookUpdated;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\OrderException;
use App\Models\Order;
use App\Models\Symbol;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(
        private BalanceService $balanceService,
        private MatchingEngine $matchingEngine
    ) {}

    /**
     * Create a new limit order.
     *
     * @throws InsufficientBalanceException
     * @throws OrderException
     */
    public function createOrder(User $user, array $data): Order
    {
        // Validate user can trade
        if (!$user->canTrade()) {
            throw new OrderException('Your account is not allowed to trade.');
        }

        // Get symbol and validate
        $symbol = Symbol::where('symbol', $data['symbol'])->first();
        if (!$symbol || !$symbol->canTrade()) {
            throw new OrderException('This symbol is not available for trading.');
        }

        $side = OrderSide::from($data['side']);
        $price = $data['price'];
        $amount = $data['amount'];

        // Validate minimum trade amount
        if (bccomp($amount, $symbol->min_trade_amount, 8) < 0) {
            throw new OrderException("Minimum trade amount is {$symbol->min_trade_amount} {$symbol->base_asset}");
        }

        // Validate maximum trade amount if set
        if ($symbol->max_trade_amount && bccomp($amount, $symbol->max_trade_amount, 8) > 0) {
            throw new OrderException("Maximum trade amount is {$symbol->max_trade_amount} {$symbol->base_asset}");
        }

        return DB::transaction(function () use ($user, $symbol, $side, $price, $amount, $data) {
            // Refresh user to get latest version
            $user = User::find($user->id);

            // Calculate required funds
            $totalValue = bcmul($price, $amount, 8);

            // Create order first
            $order = Order::create([
                'user_id' => $user->id,
                'symbol' => $symbol->symbol,
                'side' => $side,
                'type' => 'limit',
                'price' => $price,
                'amount' => $amount,
                'filled_amount' => '0',
                'locked_funds' => $side->isBuy() ? $totalValue : $amount,
                'status' => OrderStatus::OPEN,
                'client_order_id' => $data['client_order_id'] ?? null,
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);

            // Lock funds based on order side
            if ($side->isBuy()) {
                // Buy order: lock USD
                $this->balanceService->lockUsdFunds(
                    $user,
                    $totalValue,
                    $order->id,
                    "Lock USD for buy order #{$order->uuid}"
                );
            } else {
                // Sell order: lock asset
                $this->balanceService->lockAsset(
                    $user,
                    $symbol->symbol,
                    $amount,
                    $order->id,
                    "Lock {$symbol->symbol} for sell order #{$order->uuid}"
                );
            }

            // Try to match the order synchronously
            $this->matchingEngine->match($order);

            // Refresh order to get updated status
            $order->refresh();

            // Broadcast orderbook update (extract base symbol from pair)
            $baseSymbol = explode('/', $symbol->symbol)[0];
            event(new OrderBookUpdated($baseSymbol));

            return $order;
        });
    }

    /**
     * Cancel an open order.
     *
     * @throws OrderException
     */
    public function cancelOrder(User $user, Order $order): Order
    {
        // Validate ownership
        if ($order->user_id !== $user->id) {
            throw new OrderException('You are not authorized to cancel this order.');
        }

        // Validate order can be cancelled
        if (!$order->canBeCancelled()) {
            throw new OrderException('This order cannot be cancelled.');
        }

        return DB::transaction(function () use ($user, $order) {
            // Lock the order row
            $order = Order::where('id', $order->id)
                ->lockForUpdate()
                ->first();

            // Re-check status after lock
            if (!$order->canBeCancelled()) {
                throw new OrderException('This order cannot be cancelled.');
            }

            // Calculate remaining locked funds
            $remainingAmount = bcsub($order->amount, $order->filled_amount, 8);

            if ($order->side->isBuy()) {
                // Unlock remaining USD
                $remainingValue = bcmul($order->price, $remainingAmount, 8);
                if (bccomp($remainingValue, '0', 8) > 0) {
                    $user = User::find($user->id);
                    $this->balanceService->unlockUsdFunds(
                        $user,
                        $remainingValue,
                        $order->id,
                        "Unlock USD for cancelled buy order #{$order->uuid}"
                    );
                }
            } else {
                // Unlock remaining asset
                if (bccomp($remainingAmount, '0', 8) > 0) {
                    $user = User::find($user->id);
                    $this->balanceService->unlockAsset(
                        $user,
                        $order->symbol,
                        $remainingAmount,
                        $order->id,
                        "Unlock {$order->symbol} for cancelled sell order #{$order->uuid}"
                    );
                }
            }

            // Update order status
            $order->status = OrderStatus::CANCELLED;
            $order->cancelled_at = now();
            $order->save();

            // Broadcast orderbook update (extract base symbol from pair)
            $baseSymbol = explode('/', $order->symbol)[0];
            event(new OrderBookUpdated($baseSymbol));

            return $order;
        });
    }

    /**
     * Get orderbook for a symbol.
     */
    public function getOrderBook(string $symbol): array
    {
        // Get buy orders (highest price first)
        $buyOrders = Order::where('symbol', $symbol)
            ->where('side', OrderSide::BUY)
            ->whereIn('status', [OrderStatus::OPEN, OrderStatus::PARTIALLY_FILLED])
            ->orderBy('price', 'desc')
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get()
            ->map(fn($order) => [
                'price' => $order->price,
                'amount' => $order->remaining_amount,
                'total' => bcmul($order->price, $order->remaining_amount, 8),
            ]);

        // Get sell orders (lowest price first)
        $sellOrders = Order::where('symbol', $symbol)
            ->where('side', OrderSide::SELL)
            ->whereIn('status', [OrderStatus::OPEN, OrderStatus::PARTIALLY_FILLED])
            ->orderBy('price', 'asc')
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get()
            ->map(fn($order) => [
                'price' => $order->price,
                'amount' => $order->remaining_amount,
                'total' => bcmul($order->price, $order->remaining_amount, 8),
            ]);

        return [
            'symbol' => $symbol,
            'bids' => $buyOrders,
            'asks' => $sellOrders,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
