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
        Schema::create('balance_ledger', function (Blueprint $table) {
            $table->id();

            // Account identification
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('currency', 10)
                ->comment('USD for fiat, BTC/ETH for assets');

            // Transaction details
            $table->decimal('amount', 18, 8)
                ->comment('Positive for credit, negative for debit');
            $table->decimal('balance_before', 18, 8);
            $table->decimal('balance_after', 18, 8);

            // Locked balance tracking (for orders)
            $table->decimal('locked_amount', 18, 8)->default(0)
                ->comment('Change in locked amount');
            $table->decimal('locked_before', 18, 8)->default(0);
            $table->decimal('locked_after', 18, 8)->default(0);

            // Reference to source transaction
            $table->enum('reference_type', [
                'deposit',
                'withdrawal',
                'order_lock',
                'order_unlock',
                'trade_debit',
                'trade_credit',
                'fee_debit',
                'refund',
                'adjustment',
            ]);
            $table->unsignedBigInteger('reference_id')->nullable()
                ->comment('ID of the related order/trade/etc');

            // Audit metadata
            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable()
                ->comment('Additional context (order details, trade info)');

            // Idempotency
            $table->string('idempotency_key', 100)->nullable()->unique()
                ->comment('Prevents duplicate transactions');

            // Timestamps (immutable - no updated_at)
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index(['user_id', 'currency', 'created_at'], 'idx_ledger_user_currency');
            $table->index(['reference_type', 'reference_id'], 'idx_ledger_reference');
            $table->index('created_at', 'idx_ledger_created');
        });

        // Check constraints (MySQL 8.0.16+)
        DB::statement('ALTER TABLE balance_ledger ADD CONSTRAINT chk_balance_after_matches CHECK (balance_after = balance_before + amount)');
        DB::statement('ALTER TABLE balance_ledger ADD CONSTRAINT chk_locked_after_matches CHECK (locked_after = locked_before + locked_amount)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('balance_ledger');
    }
};
