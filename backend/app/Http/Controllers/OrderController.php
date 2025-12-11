<?php

namespace App\Http\Controllers;

use App\Enums\OrderStatus;
use App\Exceptions\InsufficientBalanceException;
use App\Exceptions\OrderException;
use App\Http\Requests\CancelOrderRequest;
use App\Http\Requests\CreateOrderRequest;
use App\Http\Resources\OrderResource;
use App\Http\Resources\TradeResource;
use App\Models\Order;
use App\Models\Trade;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function __construct(private OrderService $orderService)
    {
    }

    /**
     * List all orders for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $query = $request->user()->orders()
            ->orderBy('created_at', 'desc');

        // Filter by symbol
        if ($request->has('symbol')) {
            $query->where('symbol', $request->symbol);
        }

        // Filter by status
        if ($request->has('status')) {
            $statusMap = [
                'open' => [OrderStatus::OPEN, OrderStatus::PARTIALLY_FILLED],
                'filled' => [OrderStatus::FILLED],
                'cancelled' => [OrderStatus::CANCELLED],
                'expired' => [OrderStatus::EXPIRED],
            ];

            if (isset($statusMap[$request->status])) {
                $query->whereIn('status', $statusMap[$request->status]);
            }
        }

        // Filter by side
        if ($request->has('side')) {
            $query->where('side', $request->side);
        }

        $orders = $query->paginate(50);

        return response()->json([
            'orders' => OrderResource::collection($orders),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    /**
     * Create a new order.
     */
    public function store(CreateOrderRequest $request): JsonResponse
    {
        try {
            $order = $this->orderService->createOrder(
                $request->user(),
                $request->validated()
            );

            return response()->json([
                'message' => 'Order created successfully',
                'order' => new OrderResource($order),
            ], 201);
        } catch (InsufficientBalanceException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'insufficient_balance',
                'details' => [
                    'currency' => $e->currency,
                    'required' => $e->required,
                    'available' => $e->available,
                ],
            ], 422);
        } catch (OrderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'order_error',
            ], 422);
        }
    }

    /**
     * Get a specific order.
     */
    public function show(Request $request, Order $order): JsonResponse
    {
        // Ensure the order belongs to the authenticated user
        if ($order->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'Order not found',
            ], 404);
        }

        return response()->json([
            'order' => new OrderResource($order),
        ]);
    }

    /**
     * Cancel an order.
     */
    public function cancel(CancelOrderRequest $request, Order $order): JsonResponse
    {
        try {
            $order = $this->orderService->cancelOrder(
                $request->user(),
                $order
            );

            return response()->json([
                'message' => 'Order cancelled successfully',
                'order' => new OrderResource($order),
            ]);
        } catch (OrderException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'error' => 'order_error',
            ], 422);
        }
    }

    /**
     * Get trade history for the authenticated user.
     */
    public function trades(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $query = Trade::where(function ($q) use ($userId) {
            $q->where('buyer_id', $userId)
                ->orWhere('seller_id', $userId);
        })->orderBy('created_at', 'desc');

        // Filter by symbol
        if ($request->has('symbol')) {
            $query->where('symbol', $request->symbol);
        }

        $trades = $query->paginate(50);

        return response()->json([
            'trades' => TradeResource::collection($trades),
            'pagination' => [
                'current_page' => $trades->currentPage(),
                'last_page' => $trades->lastPage(),
                'per_page' => $trades->perPage(),
                'total' => $trades->total(),
            ],
        ]);
    }
}
