<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();

            // Trade identification
            $table->uuid('uuid')->unique();

            // Matched orders
            $table->foreignId('buy_order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('sell_order_id')->constrained('orders')->restrictOnDelete();

            // Participants (denormalized for query performance)
            $table->foreignId('buyer_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('seller_id')->constrained('users')->restrictOnDelete();

            // Trade details
            $table->string('symbol', 20);
            $table->decimal('price', 18, 8)
                ->comment('Execution price');
            $table->decimal('amount', 18, 8)
                ->comment('Executed amount in base asset');

            // Commission tracking
            $table->decimal('buyer_fee', 18, 8)->default(0)
                ->comment('Fee charged to buyer (in quote currency)');
            $table->decimal('seller_fee', 18, 8)->default(0)
                ->comment('Fee charged to seller (in base asset or quote)');
            $table->string('fee_currency_buyer', 10)->default('USD');
            $table->string('fee_currency_seller', 10)->default('USD');

            // Market data
            $table->boolean('is_buyer_maker')
                ->comment('TRUE if buy order was resting (maker), FALSE if taker');

            // Timestamps (only created_at, trades are immutable)
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index(['buyer_id', 'created_at'], 'idx_trades_buyer');
            $table->index(['seller_id', 'created_at'], 'idx_trades_seller');
            $table->index(['symbol', 'created_at'], 'idx_trades_symbol_time');
            $table->index(['buy_order_id', 'sell_order_id'], 'idx_trades_orders');
        });

        // Add generated column for quote_amount (MySQL 5.7+)
        DB::statement('ALTER TABLE trades ADD COLUMN quote_amount DECIMAL(18, 8) GENERATED ALWAYS AS (price * amount) STORED COMMENT \'Total value in quote currency (USD)\' AFTER amount');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
