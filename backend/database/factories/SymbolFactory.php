<?php

namespace Database\Factories;

use App\Models\Symbol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Symbol>
 */
class SymbolFactory extends Factory
{
    protected $model = Symbol::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $baseAssets = ['BTC', 'ETH', 'LTC', 'XRP', 'ADA'];
        $quoteAssets = ['USD', 'USDT', 'EUR'];

        $base = $this->faker->randomElement($baseAssets);
        $quote = $this->faker->randomElement($quoteAssets);

        return [
            'symbol' => "{$base}/{$quote}",
            'name' => "{$base} to {$quote}",
            'base_asset' => $base,
            'quote_asset' => $quote,
            'min_trade_amount' => '0.00001000',
            'max_trade_amount' => '1000.00000000',
            'tick_size' => '0.01000000',
            'lot_size' => '0.00001000',
            'price_precision' => 2,
            'amount_precision' => 8,
            'is_active' => true,
            'trading_enabled' => true,
        ];
    }

    /**
     * Indicate that the symbol is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that trading is disabled.
     */
    public function tradingDisabled(): static
    {
        return $this->state(fn (array $attributes) => [
            'trading_enabled' => false,
        ]);
    }

    /**
     * Create a BTC/USD symbol.
     */
    public function btcUsd(): static
    {
        return $this->state(fn (array $attributes) => [
            'symbol' => 'BTC/USD',
            'name' => 'Bitcoin to US Dollar',
            'base_asset' => 'BTC',
            'quote_asset' => 'USD',
        ]);
    }
}
