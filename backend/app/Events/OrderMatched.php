<?php

namespace App\Events;

use App\Models\Trade;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class OrderMatched implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Trade $trade,
        public User $buyer,
        public User $seller
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('user.' . $this->buyer->id),
            new PrivateChannel('user.' . $this->seller->id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'order.matched';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // Reload users to get updated balances
        $this->buyer->refresh()->load('assets');
        $this->seller->refresh()->load('assets');

        return [
            'trade' => [
                'id' => $this->trade->uuid,
                'symbol' => $this->trade->symbol,
                'price' => $this->trade->price,
                'amount' => $this->trade->amount,
                'quote_amount' => $this->trade->quote_amount,
                'buyer_fee' => $this->trade->buyer_fee,
                'seller_fee' => $this->trade->seller_fee,
                'is_buyer_maker' => $this->trade->is_buyer_maker,
                'created_at' => $this->trade->created_at?->toIso8601String(),
            ],
            'buyer' => [
                'id' => $this->buyer->id,
                'balance' => $this->buyer->balance,
                'locked_balance' => $this->buyer->locked_balance,
                'assets' => $this->buyer->assets->map(fn($asset) => [
                    'symbol' => $asset->symbol,
                    'amount' => $asset->amount,
                    'locked_amount' => $asset->locked_amount,
                ])->toArray(),
            ],
            'seller' => [
                'id' => $this->seller->id,
                'balance' => $this->seller->balance,
                'locked_balance' => $this->seller->locked_balance,
                'assets' => $this->seller->assets->map(fn($asset) => [
                    'symbol' => $asset->symbol,
                    'amount' => $asset->amount,
                    'locked_amount' => $asset->locked_amount,
                ])->toArray(),
            ],
            'buy_order_id' => $this->trade->buyOrder?->uuid,
            'sell_order_id' => $this->trade->sellOrder?->uuid,
        ];
    }
}
