<?php

namespace App\Services\Security;

use App\Enums\OrderStatus;
use App\Enums\SecurityEventType;
use App\Enums\SecuritySeverity;
use App\Models\Order;
use App\Models\RateLimitCounter;
use App\Models\Trade;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class MarketManipulationDetector
{
    private array $detectedThreats = [];

    /**
     * Get fresh config each time to support runtime config changes (e.g., in tests).
     */
    private function config(): array
    {
        return config('attack_detection');
    }

    /**
     * Main detection entry point - analyzes request for all attack patterns.
     */
    public function analyze(Request $request, ?User $user): DetectionResult
    {
        $this->detectedThreats = [];

        if (! $this->config()['enabled']) {
            return DetectionResult::clean();
        }

        // Skip whitelisted IPs/users
        if ($this->isWhitelisted($request, $user)) {
            return DetectionResult::clean();
        }

        $context = $this->buildContext($request, $user);

        // Run all detectors
        $this->detectRapidFireSpam($context);

        if ($user) {
            $this->detectOrderSpoofing($context);
            $this->detectLayering($context);
            $this->detectPriceManipulation($context);
            $this->detectWashTrading($context);
        }

        return $this->buildResult($context);
    }

    /**
     * Build context object for detection.
     */
    private function buildContext(Request $request, ?User $user): DetectionContext
    {
        $context = new DetectionContext;
        $context->request = $request;
        $context->user = $user;
        $context->ipAddress = $request->ip();
        $context->endpoint = $request->path();
        $context->method = $request->method();
        $context->symbol = $request->input('symbol');
        $context->orderPrice = $request->input('price');
        $context->orderAmount = $request->input('amount');
        $context->orderSide = $request->input('side');

        // Pre-load user's recent orders if authenticated
        if ($user) {
            $lookbackMinutes = $this->config()['thresholds']['spoofing']['lookback_minutes'];
            $context->recentOrders = Order::where('user_id', $user->id)
                ->where('created_at', '>=', now()->subMinutes($lookbackMinutes))
                ->get();
        }

        return $context;
    }

    /**
     * Detect rapid-fire spam attacks.
     */
    private function detectRapidFireSpam(DetectionContext $context): void
    {
        $thresholds = $this->config()['thresholds']['spam'];
        $key = $context->getUserKey().':orders';

        // Check orders per minute
        $currentCount = RateLimitCounter::incrementAndGet($key, 'count', 1);

        if ($currentCount > $thresholds['orders_per_minute']) {
            $severity = $currentCount > ($thresholds['orders_per_minute'] * 2)
                ? SecuritySeverity::HIGH
                : SecuritySeverity::MEDIUM;

            $this->detectedThreats[] = [
                'type' => SecurityEventType::RAPID_FIRE_SPAM,
                'severity' => $severity,
                'metrics' => [
                    'current_rate' => $currentCount,
                    'threshold' => $thresholds['orders_per_minute'],
                    'window' => '1 minute',
                ],
            ];
        }

        // Check requests per 5-second window (approximation of per-second rate)
        $secondKey = $key.':burst:'.floor(time() / 5);
        $burstCount = RateLimitCounter::incrementAndGet($secondKey, 'count', 1);

        if ($burstCount > ($thresholds['requests_per_second'] * 5)) {
            $this->detectedThreats[] = [
                'type' => SecurityEventType::RAPID_FIRE_SPAM,
                'severity' => SecuritySeverity::HIGH,
                'metrics' => [
                    'current_rate' => round($burstCount / 5, 2),
                    'threshold' => $thresholds['requests_per_second'],
                    'window' => '5 seconds',
                ],
            ];
        }
    }

    /**
     * Detect order spoofing patterns.
     */
    private function detectOrderSpoofing(DetectionContext $context): void
    {
        if (! $context->user || $context->recentOrders->isEmpty()) {
            return;
        }

        $thresholds = $this->config()['thresholds']['spoofing'];
        $orders = $context->recentOrders;

        // Need minimum orders to calculate patterns
        if ($orders->count() < $thresholds['min_orders_for_detection']) {
            return;
        }

        // Calculate cancel rate
        $cancelledOrders = $orders->where('status', OrderStatus::CANCELLED);
        $cancelRate = $cancelledOrders->count() / $orders->count();

        // Check for high cancel rate
        if ($cancelRate >= $thresholds['cancel_rate_threshold']) {
            // Check for quick cancellations (spoofing indicator)
            $quickCancels = $cancelledOrders->filter(function ($order) use ($thresholds) {
                if (! $order->cancelled_at) {
                    return false;
                }
                $lifetime = $order->cancelled_at->diffInSeconds($order->created_at);

                return $lifetime <= $thresholds['quick_cancel_seconds'];
            });

            if ($quickCancels->count() >= 3) {
                $severity = $cancelRate >= 0.9
                    ? SecuritySeverity::HIGH
                    : SecuritySeverity::MEDIUM;

                $this->detectedThreats[] = [
                    'type' => SecurityEventType::ORDER_SPOOFING,
                    'severity' => $severity,
                    'metrics' => [
                        'cancel_rate' => round($cancelRate, 3),
                        'threshold' => $thresholds['cancel_rate_threshold'],
                        'quick_cancels' => $quickCancels->count(),
                        'quick_cancel_threshold_seconds' => $thresholds['quick_cancel_seconds'],
                        'total_orders' => $orders->count(),
                    ],
                    'related_orders' => $quickCancels->pluck('uuid')->toArray(),
                ];
            }
        }

        // Check for large orders that get cancelled quickly
        $avgAmount = $orders->avg('amount');
        if ($avgAmount > 0) {
            $largeThreshold = bcmul((string) $avgAmount, (string) $thresholds['large_order_multiplier'], 8);

            $largeCancelledQuickly = $cancelledOrders->filter(function ($order) use ($largeThreshold, $thresholds) {
                if (! $order->cancelled_at) {
                    return false;
                }
                $isLarge = bccomp($order->amount, $largeThreshold, 8) >= 0;
                $lifetime = $order->cancelled_at->diffInSeconds($order->created_at);

                return $isLarge && $lifetime <= $thresholds['quick_cancel_seconds'];
            });

            if ($largeCancelledQuickly->count() >= 2) {
                $this->detectedThreats[] = [
                    'type' => SecurityEventType::ORDER_SPOOFING,
                    'severity' => SecuritySeverity::HIGH,
                    'metrics' => [
                        'large_quick_cancels' => $largeCancelledQuickly->count(),
                        'large_order_threshold' => $largeThreshold,
                        'average_order_size' => $avgAmount,
                    ],
                    'related_orders' => $largeCancelledQuickly->pluck('uuid')->toArray(),
                ];
            }
        }
    }

    /**
     * Detect layering attacks.
     */
    private function detectLayering(DetectionContext $context): void
    {
        if (! $context->user || $context->recentOrders->isEmpty()) {
            return;
        }

        $thresholds = $this->config()['thresholds']['layering'];

        // Check for batch cancellation patterns first (doesn't require active orders)
        $recentCancels = $context->recentOrders
            ->where('status', OrderStatus::CANCELLED)
            ->filter(fn ($order) => $order->cancelled_at && $order->cancelled_at->gte(now()->subSeconds($thresholds['batch_window_seconds'])));

        if ($recentCancels->count() >= $thresholds['batch_cancel_threshold']) {
            $this->detectedThreats[] = [
                'type' => SecurityEventType::LAYERING,
                'severity' => SecuritySeverity::HIGH,
                'metrics' => [
                    'batch_cancels' => $recentCancels->count(),
                    'window_seconds' => $thresholds['batch_window_seconds'],
                    'threshold' => $thresholds['batch_cancel_threshold'],
                ],
                'related_orders' => $recentCancels->pluck('uuid')->toArray(),
            ];
        }

        // Check for layering pattern (multiple orders stacked at same price)
        $activeOrders = $context->recentOrders->whereIn('status', [
            OrderStatus::OPEN,
            OrderStatus::PARTIALLY_FILLED,
        ]);

        if ($activeOrders->count() < $thresholds['min_orders_same_price']) {
            return;
        }

        // Group orders by price level (with tolerance)
        $priceGroups = $activeOrders->groupBy(function ($order) use ($thresholds) {
            $tolerance = $thresholds['price_level_tolerance'];
            if ($tolerance > 0) {
                return (string) (round((float) $order->price / $tolerance) * $tolerance);
            }

            return (string) $order->price;
        });

        foreach ($priceGroups as $price => $ordersAtPrice) {
            if ($ordersAtPrice->count() >= $thresholds['min_orders_same_price']) {
                $this->detectedThreats[] = [
                    'type' => SecurityEventType::LAYERING,
                    'severity' => SecuritySeverity::MEDIUM,
                    'metrics' => [
                        'orders_at_price' => $ordersAtPrice->count(),
                        'price_level' => $price,
                        'threshold' => $thresholds['min_orders_same_price'],
                        'total_amount' => $ordersAtPrice->sum('amount'),
                    ],
                    'related_orders' => $ordersAtPrice->pluck('uuid')->toArray(),
                ];
            }
        }
    }

    /**
     * Detect price manipulation attempts.
     */
    private function detectPriceManipulation(DetectionContext $context): void
    {
        if (! $context->orderPrice || ! $context->symbol) {
            return;
        }

        $thresholds = $this->config()['thresholds']['price_manipulation'];

        // Get current market price (last trade price or mid-price from orderbook)
        $marketPrice = $this->getMarketPrice($context->symbol);

        if (! $marketPrice || bccomp($marketPrice, '0', 8) <= 0) {
            return;
        }

        // Calculate deviation from market price
        $deviation = abs((float) $context->orderPrice - (float) $marketPrice) / (float) $marketPrice;

        if ($deviation >= $thresholds['extreme_deviation']) {
            $this->detectedThreats[] = [
                'type' => SecurityEventType::PRICE_MANIPULATION,
                'severity' => SecuritySeverity::CRITICAL,
                'metrics' => [
                    'order_price' => $context->orderPrice,
                    'market_price' => $marketPrice,
                    'deviation_percent' => round($deviation * 100, 2),
                    'extreme_threshold' => $thresholds['extreme_deviation'] * 100,
                ],
            ];
        } elseif ($deviation >= $thresholds['deviation_from_market']) {
            $this->detectedThreats[] = [
                'type' => SecurityEventType::PRICE_MANIPULATION,
                'severity' => SecuritySeverity::MEDIUM,
                'metrics' => [
                    'order_price' => $context->orderPrice,
                    'market_price' => $marketPrice,
                    'deviation_percent' => round($deviation * 100, 2),
                    'threshold' => $thresholds['deviation_from_market'] * 100,
                ],
            ];
        }
    }

    /**
     * Detect wash trading patterns.
     */
    private function detectWashTrading(DetectionContext $context): void
    {
        if (! $context->user) {
            return;
        }

        $thresholds = $this->config()['thresholds']['wash_trading'];
        $lookbackHours = $thresholds['lookback_hours'];

        // Find trades where this user was involved
        $userTrades = Trade::where(function ($q) use ($context) {
            $q->where('buyer_id', $context->user->id)
                ->orWhere('seller_id', $context->user->id);
        })
            ->where('created_at', '>=', now()->subHours($lookbackHours))
            ->with(['buyOrder', 'sellOrder'])
            ->get();

        if ($userTrades->isEmpty()) {
            return;
        }

        // Check for same-IP trades (potential wash trading)
        $sameIpTrades = $userTrades->filter(function ($trade) {
            // Get IPs from the orders
            $buyOrderIp = $trade->buyOrder->ip_address ?? null;
            $sellOrderIp = $trade->sellOrder->ip_address ?? null;

            // Check if buyer and seller share IP
            return $buyOrderIp && $sellOrderIp && $buyOrderIp === $sellOrderIp;
        });

        if ($sameIpTrades->count() >= $thresholds['same_ip_trade_threshold']) {
            $relatedUsers = $sameIpTrades->flatMap(function ($trade) {
                return [$trade->buyer_id, $trade->seller_id];
            })->unique()->values()->toArray();

            $this->detectedThreats[] = [
                'type' => SecurityEventType::WASH_TRADING,
                'severity' => SecuritySeverity::CRITICAL,
                'metrics' => [
                    'same_ip_trades' => $sameIpTrades->count(),
                    'threshold' => $thresholds['same_ip_trade_threshold'],
                    'lookback_hours' => $lookbackHours,
                ],
                'related_orders' => $sameIpTrades->flatMap(function ($trade) {
                    return [$trade->buyOrder->uuid ?? null, $trade->sellOrder->uuid ?? null];
                })->filter()->unique()->values()->toArray(),
                'related_users' => $relatedUsers,
            ];
        }

        // Check for coordinated timing patterns
        $this->detectCoordinatedTiming($userTrades, $context, $thresholds);
    }

    /**
     * Detect coordinated timing in trades.
     */
    private function detectCoordinatedTiming(Collection $trades, DetectionContext $context, array $thresholds): void
    {
        // Group trades by time window
        $windowSeconds = $thresholds['timing_window_seconds'];

        $tradesByWindow = $trades->groupBy(function ($trade) use ($windowSeconds) {
            return floor($trade->created_at->timestamp / $windowSeconds);
        });

        foreach ($tradesByWindow as $window => $windowTrades) {
            if ($windowTrades->count() < 2) {
                continue;
            }

            // Check if user appears on both sides within window
            $asBuyer = $windowTrades->where('buyer_id', $context->user->id)->count();
            $asSeller = $windowTrades->where('seller_id', $context->user->id)->count();

            if ($asBuyer > 0 && $asSeller > 0) {
                $this->detectedThreats[] = [
                    'type' => SecurityEventType::COORDINATED_TRADING,
                    'severity' => SecuritySeverity::HIGH,
                    'metrics' => [
                        'trades_in_window' => $windowTrades->count(),
                        'as_buyer' => $asBuyer,
                        'as_seller' => $asSeller,
                        'window_seconds' => $windowSeconds,
                    ],
                ];
            }
        }
    }

    /**
     * Get current market price for a symbol.
     */
    private function getMarketPrice(string $symbol): ?string
    {
        // Try last trade price first
        $lastTrade = Trade::where('symbol', $symbol)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($lastTrade) {
            return $lastTrade->price;
        }

        // Fall back to mid-price from orderbook
        $bestBid = Order::where('symbol', $symbol)
            ->where('side', 'buy')
            ->whereIn('status', [OrderStatus::OPEN, OrderStatus::PARTIALLY_FILLED])
            ->orderBy('price', 'desc')
            ->value('price');

        $bestAsk = Order::where('symbol', $symbol)
            ->where('side', 'sell')
            ->whereIn('status', [OrderStatus::OPEN, OrderStatus::PARTIALLY_FILLED])
            ->orderBy('price', 'asc')
            ->value('price');

        if ($bestBid && $bestAsk) {
            return bcdiv(bcadd($bestBid, $bestAsk, 8), '2', 8);
        }

        return $bestBid ?? $bestAsk;
    }

    /**
     * Check if request is whitelisted.
     */
    private function isWhitelisted(Request $request, ?User $user): bool
    {
        if (in_array($request->ip(), $this->config()['ip_whitelist'])) {
            return true;
        }

        if ($user && in_array($user->id, $this->config()['user_whitelist'])) {
            return true;
        }

        return false;
    }

    /**
     * Build the final detection result.
     */
    private function buildResult(DetectionContext $context): DetectionResult
    {
        if (empty($this->detectedThreats)) {
            return DetectionResult::clean($context);
        }

        // Find highest severity threat
        $highestSeverity = SecuritySeverity::LOW;
        foreach ($this->detectedThreats as $threat) {
            if ($threat['severity']->numericValue() > $highestSeverity->numericValue()) {
                $highestSeverity = $threat['severity'];
            }
        }

        // Calculate risk score contribution
        $riskScore = 0;
        foreach ($this->detectedThreats as $threat) {
            $riskScore += $threat['type']->riskWeight();
        }
        $riskScore = min($riskScore, $this->config()['risk_scoring']['max_score']);

        return new DetectionResult(
            detected: true,
            threats: $this->detectedThreats,
            highestSeverity: $highestSeverity,
            riskScore: $riskScore,
            context: $context
        );
    }
}
