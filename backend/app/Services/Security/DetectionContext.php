<?php

namespace App\Services\Security;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DetectionContext
{
    public Request $request;
    public ?User $user = null;
    public string $ipAddress;
    public string $endpoint;
    public string $method;
    public ?string $symbol = null;
    public ?string $orderPrice = null;
    public ?string $orderAmount = null;
    public ?string $orderSide = null;
    public Collection $recentOrders;

    public function __construct()
    {
        $this->recentOrders = collect();
    }

    public function toArray(): array
    {
        return [
            'ip_address' => $this->ipAddress,
            'endpoint' => $this->endpoint,
            'method' => $this->method,
            'user_id' => $this->user?->id,
            'symbol' => $this->symbol,
            'order_price' => $this->orderPrice,
            'order_amount' => $this->orderAmount,
            'order_side' => $this->orderSide,
        ];
    }

    public function getUserKey(): string
    {
        return $this->user ? "user:{$this->user->id}" : "ip:{$this->ipAddress}";
    }
}
