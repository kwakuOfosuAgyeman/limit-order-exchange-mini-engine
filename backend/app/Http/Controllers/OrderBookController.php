<?php

namespace App\Http\Controllers;

use App\Services\OrderService;
use Illuminate\Http\JsonResponse;

class OrderBookController extends Controller
{
    public function __construct(private OrderService $orderService)
    {
    }

    /**
     * Get the orderbook for a symbol.
     */
    public function show(string $symbol): JsonResponse
    {
        $orderbook = $this->orderService->getOrderBook(strtoupper($symbol));

        return response()->json($orderbook);
    }
}
