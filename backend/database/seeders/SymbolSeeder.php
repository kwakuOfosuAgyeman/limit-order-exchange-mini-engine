<?php

namespace Database\Seeders;

use App\Models\Symbol;
use Illuminate\Database\Seeder;

class SymbolSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $symbols = [
            [
                'symbol' => 'BTC',
                'name' => 'Bitcoin',
                'base_asset' => 'BTC',
                'quote_asset' => 'USD',
                'min_trade_amount' => '0.00001000',
                'max_trade_amount' => '100.00000000',
                'tick_size' => '0.01000000',
                'lot_size' => '0.00001000',
                'price_precision' => 2,
                'amount_precision' => 8,
                'is_active' => true,
                'trading_enabled' => true,
            ],
            [
                'symbol' => 'ETH',
                'name' => 'Ethereum',
                'base_asset' => 'ETH',
                'quote_asset' => 'USD',
                'min_trade_amount' => '0.00010000',
                'max_trade_amount' => '1000.00000000',
                'tick_size' => '0.01000000',
                'lot_size' => '0.00010000',
                'price_precision' => 2,
                'amount_precision' => 8,
                'is_active' => true,
                'trading_enabled' => true,
            ],
        ];

        foreach ($symbols as $symbol) {
            Symbol::updateOrCreate(
                ['symbol' => $symbol['symbol']],
                $symbol
            );
        }
    }
}
