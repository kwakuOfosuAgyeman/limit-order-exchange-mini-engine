<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('symbols', function (Blueprint $table) {
            $table->id();

            // Symbol identification
            $table->string('symbol', 20)->unique()
                ->comment('Trading pair symbol e.g., BTC, ETH');
            $table->string('name', 100)
                ->comment('Full name e.g., Bitcoin, Ethereum');

            // Trading pair composition
            $table->string('base_asset', 10)
                ->comment('Asset being traded e.g., BTC');
            $table->string('quote_asset', 10)->default('USD')
                ->comment('Currency used for pricing e.g., USD');

            // Trading rules
            $table->decimal('min_trade_amount', 18, 8)->default(0.00000001)
                ->comment('Minimum order amount');
            $table->decimal('max_trade_amount', 18, 8)->nullable()
                ->comment('Maximum order amount (NULL = unlimited)');
            $table->decimal('tick_size', 18, 8)->default(0.01)
                ->comment('Minimum price increment');
            $table->decimal('lot_size', 18, 8)->default(0.00000001)
                ->comment('Minimum amount increment');

            // Display settings
            $table->unsignedTinyInteger('price_precision')->default(2);
            $table->unsignedTinyInteger('amount_precision')->default(8);

            // Status
            $table->boolean('is_active')->default(true);
            $table->boolean('trading_enabled')->default(true);

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['is_active', 'trading_enabled'], 'idx_symbols_active');
            $table->unique(['base_asset', 'quote_asset'], 'idx_symbols_pair');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('symbols');
    }
};
