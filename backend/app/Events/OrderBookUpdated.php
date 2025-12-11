<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class OrderBookUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $symbol
    ) {}

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return [
            new Channel('orderbook.' . $this->symbol),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'orderbook.updated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return $this->getOrderBook();
    }

    /**
     * Get the current orderbook for the symbol.
     */
    private function getOrderBook(): array
    {
        // Get aggregated bids (buy orders) - highest price first
        $bids = DB::table('orders')
            ->select('price', DB::raw('SUM(amount - filled_amount) as amount'))
            ->where('symbol', $this->symbol . '/USD')
            ->where('side', 'buy')
            ->where('status', 'open')
            ->groupBy('price')
            ->orderBy('price', 'desc')
            ->limit(20)
            ->get()
            ->map(fn($row) => [
                'price' => $row->price,
                'amount' => $row->amount,
                'total' => bcmul($row->price, $row->amount, 2),
            ])
            ->toArray();

        // Get aggregated asks (sell orders) - lowest price first
        $asks = DB::table('orders')
            ->select('price', DB::raw('SUM(amount - filled_amount) as amount'))
            ->where('symbol', $this->symbol . '/USD')
            ->where('side', 'sell')
            ->where('status', 'open')
            ->groupBy('price')
            ->orderBy('price', 'asc')
            ->limit(20)
            ->get()
            ->map(fn($row) => [
                'price' => $row->price,
                'amount' => $row->amount,
                'total' => bcmul($row->price, $row->amount, 2),
            ])
            ->toArray();

        return [
            'symbol' => $this->symbol,
            'bids' => $bids,
            'asks' => $asks,
            'timestamp' => now()->toIso8601String(),
        ];
    }
}
