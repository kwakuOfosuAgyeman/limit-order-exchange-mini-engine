<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'balance' => $this->balance,
            'locked_balance' => $this->locked_balance,
            'total_balance' => $this->total_balance,
            'is_active' => $this->is_active,
            'can_trade' => $this->canTrade(),
            'fee_tier' => $this->whenLoaded('feeTier', fn() => [
                'name' => $this->feeTier->name,
                'maker_fee_rate' => $this->feeTier->maker_fee_rate,
                'taker_fee_rate' => $this->feeTier->taker_fee_rate,
            ]),
            'assets' => AssetResource::collection($this->whenLoaded('assets')),
            'created_at' => $this->created_at,
        ];
    }
}
