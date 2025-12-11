<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TradeResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $isBuyer = $user && $this->buyer_id === $user->id;

        return [
            'id' => $this->uuid,
            'symbol' => $this->symbol,
            'side' => $isBuyer ? 'buy' : 'sell',
            'price' => $this->price,
            'amount' => $this->amount,
            'quote_amount' => $this->quote_amount,
            'fee' => $isBuyer ? $this->buyer_fee : $this->seller_fee,
            'fee_currency' => $isBuyer ? $this->fee_currency_buyer : $this->fee_currency_seller,
            'is_maker' => $isBuyer ? $this->is_buyer_maker : !$this->is_buyer_maker,
            'created_at' => $this->created_at,
        ];
    }
}
