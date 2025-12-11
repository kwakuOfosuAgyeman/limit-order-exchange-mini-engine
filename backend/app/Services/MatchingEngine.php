<?php

namespace App\Services;

use App\Enums\OrderSide;
use App\Enums\OrderStatus;
use App\Events\OrderMatched;
use App\Models\BalanceLedger;
use App\Models\Order;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MatchingEngine
{
    private const FEE_RATE = '0.015'; // 1.5% commission

    public function __construct(private BalanceService $balanceService)
    {
    }

    /**
     * Try to match an order against the orderbook.
     * Full match only - no partial fills.
     */
    public function match(Order $order): ?Trade
    {
        if (!$order->canBeMatched()) {
            return null;
        }

        return DB::transaction(function () use ($order) {
            // Lock the order
            $order = Order::where('id', $order->id)
                ->lockForUpdate()
                ->first();

            if (!$order->canBeMatched()) {
                return null;
            }

            // Find a matching counter-order
            $counterOrder = $this->findMatchingOrder($order);

            if (!$counterOrder) {
                return null;
            }

            // Execute the match
            return $this->executeMatch($order, $counterOrder);
        });
    }

    /**
     * Find a matching order from the orderbook.
     * For full match only, we need exact amount match.
     */
    private function findMatchingOrder(Order $order): ?Order
    {
        $query = Order::where('symbol', $order->symbol)
            ->where('side', $order->side->opposite())
            ->where('status', OrderStatus::OPEN)
            ->where('amount', $order->amount) // Full match only - exact amount
            ->lockForUpdate();

        if ($order->side->isBuy()) {
            // Buy order matches with sell orders where sell.price <= buy.price
            $query->where('price', '<=', $order->price)
                ->orderBy('price', 'asc') // Best (lowest) price first
                ->orderBy('created_at', 'asc'); // Then oldest first (FIFO)
        } else {
            // Sell order matches with buy orders where buy.price >= sell.price
            $query->where('price', '>=', $order->price)
                ->orderBy('price', 'desc') // Best (highest) price first
                ->orderBy('created_at', 'asc'); // Then oldest first (FIFO)
        }

        return $query->first();
    }

    /**
     * Execute a match between two orders.
     */
    private function executeMatch(Order $order, Order $counterOrder): Trade
    {
        // Determine buyer and seller
        $buyOrder = $order->side->isBuy() ? $order : $counterOrder;
        $sellOrder = $order->side->isSell() ? $order : $counterOrder;

        // Execution price is the maker's (resting order) price
        // The counter order is the resting order (was in the book first)
        $executionPrice = $counterOrder->price;
        $amount = $order->amount;

        // Calculate quote amount (USD value)
        $quoteAmount = bcmul($executionPrice, $amount, 8);

        // Calculate fee (1.5% charged to buyer)
        $buyerFee = bcmul($quoteAmount, self::FEE_RATE, 8);
        $sellerFee = '0'; // Seller pays no fee

        // Determine if buyer is maker
        $isBuyerMaker = $order->side->isSell(); // If incoming order is sell, buyer was maker

        // Load users
        $buyer = User::find($buyOrder->user_id);
        $seller = User::find($sellOrder->user_id);

        // Execute the trade:
        // 1. Buyer: debit locked USD (price * amount + fee)
        // 2. Buyer: credit asset
        // 3. Seller: debit locked asset
        // 4. Seller: credit USD (price * amount)

        // Total USD buyer pays from locked balance
        $totalBuyerPays = bcadd($quoteAmount, $buyerFee, 8);

        // Debit buyer's locked USD
        $this->balanceService->debitLockedUsd(
            $buyer,
            $totalBuyerPays,
            BalanceLedger::TYPE_TRADE_DEBIT,
            $buyOrder->id,
            "Trade execution: buy {$amount} {$order->symbol} @ {$executionPrice}"
        );

        // Credit buyer with asset
        $this->balanceService->creditAsset(
            $buyer,
            $order->symbol,
            $amount,
            BalanceLedger::TYPE_TRADE_CREDIT,
            $buyOrder->id,
            "Trade execution: received {$amount} {$order->symbol}"
        );

        // Debit seller's locked asset
        $this->balanceService->debitLockedAsset(
            $seller,
            $order->symbol,
            $amount,
            BalanceLedger::TYPE_TRADE_DEBIT,
            $sellOrder->id,
            "Trade execution: sell {$amount} {$order->symbol} @ {$executionPrice}"
        );

        // Credit seller with USD (full amount, no fee)
        $this->balanceService->creditUsd(
            $seller,
            $quoteAmount,
            BalanceLedger::TYPE_TRADE_CREDIT,
            $sellOrder->id,
            "Trade execution: received {$quoteAmount} USD"
        );

        // If buyer had locked more USD than needed (because buy price > execution price),
        // refund the difference
        $buyerOriginalLock = bcmul($buyOrder->price, $amount, 8);
        $actualUsed = $totalBuyerPays;
        $refund = bcsub($buyerOriginalLock, $actualUsed, 8);

        if (bccomp($refund, '0', 8) > 0) {
            // The locked balance was already debited, so we need to credit available balance
            $this->balanceService->creditUsd(
                $buyer,
                $refund,
                BalanceLedger::TYPE_REFUND,
                $buyOrder->id,
                "Price improvement refund"
            );
        }

        // Update orders
        $order->filled_amount = $amount;
        $order->status = OrderStatus::FILLED;
        $order->filled_at = now();
        $order->save();

        $counterOrder->filled_amount = $amount;
        $counterOrder->status = OrderStatus::FILLED;
        $counterOrder->filled_at = now();
        $counterOrder->save();

        // Create trade record
        $trade = Trade::create([
            'uuid' => Str::uuid(),
            'buy_order_id' => $buyOrder->id,
            'sell_order_id' => $sellOrder->id,
            'buyer_id' => $buyer->id,
            'seller_id' => $seller->id,
            'symbol' => $order->symbol,
            'price' => $executionPrice,
            'amount' => $amount,
            'buyer_fee' => $buyerFee,
            'seller_fee' => $sellerFee,
            'fee_currency_buyer' => 'USD',
            'fee_currency_seller' => 'USD',
            'is_buyer_maker' => $isBuyerMaker,
        ]);

        // Broadcast event to both users
        event(new OrderMatched($trade, $buyer, $seller));

        return $trade;
    }

    /**
     * Calculate fee for a given amount.
     */
    public function calculateFee(string $quoteAmount): string
    {
        return bcmul($quoteAmount, self::FEE_RATE, 8);
    }
}
