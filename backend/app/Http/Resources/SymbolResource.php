<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SymbolResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'symbol' => $this->symbol,
            'name' => $this->name,
            'base_asset' => $this->base_asset,
            'quote_asset' => $this->quote_asset,
            'pair' => $this->pair,
            'min_trade_amount' => $this->min_trade_amount,
            'max_trade_amount' => $this->max_trade_amount,
            'tick_size' => $this->tick_size,
            'lot_size' => $this->lot_size,
            'price_precision' => $this->price_precision,
            'amount_precision' => $this->amount_precision,
            'is_active' => $this->is_active,
            'trading_enabled' => $this->trading_enabled,
        ];
    }
}
