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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Order identification - UUID for external API exposure
            $table->uuid('uuid')->unique()->comment('Public-facing order ID');

            // Ownership and symbol
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('symbol', 20);

            // Order details
            $table->enum('side', ['buy', 'sell']);
            $table->enum('type', ['limit', 'market'])->default('limit');
            $table->decimal('price', 18, 8)->comment('Limit price in quote currency (USD)');
            $table->decimal('amount', 18, 8)->comment('Original order amount in base asset');
            $table->decimal('filled_amount', 18, 8)->default(0.00000000)
                ->comment('Amount that has been executed');

            // Locked funds tracking
            $table->decimal('locked_funds', 18, 8)->default(0.00000000)
                ->comment('USD locked (buy) or asset locked (sell)');

            // Status: 1=open, 2=filled, 3=cancelled, 4=partially_filled, 5=expired
            $table->tinyInteger('status')->unsigned()->default(1);

            // Metadata for debugging and idempotency
            $table->string('client_order_id', 100)->nullable()
                ->comment('Client-provided order ID for idempotency');
            $table->string('ip_address', 45)->nullable()
                ->comment('IP address for security audit');
            $table->string('user_agent', 500)->nullable();

            // Optimistic locking
            $table->unsignedInteger('version')->default(1);

            // Timestamps
            $table->timestamps();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('expires_at')->nullable();

            // Composite indexes for order matching (CRITICAL for performance)
            // For finding best SELL orders to match with incoming BUY
            $table->index(['symbol', 'side', 'status', 'price', 'created_at'], 'idx_orders_matching_sell');

            // User order lookup
            $table->index(['user_id', 'status']);
            $table->index(['symbol', 'status']);

            // Client order ID lookup (for idempotency)
            $table->index(['user_id', 'client_order_id']);

            // Expiry processing
            $table->index(['expires_at', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
