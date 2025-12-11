<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'type' => $this->type,
            'price' => $this->price,
            'amount' => $this->amount,
            'filled_amount' => $this->filled_amount,
            'remaining_amount' => $this->remaining_amount,
            'total_value' => $this->total_value,
            'filled_value' => $this->filled_value,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'can_be_cancelled' => $this->canBeCancelled(),
            'created_at' => $this->created_at,
            'filled_at' => $this->filled_at,
            'cancelled_at' => $this->cancelled_at,
        ];
    }
}
