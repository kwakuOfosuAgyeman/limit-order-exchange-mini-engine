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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();

            // Ownership
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('symbol', 20)
                ->comment('Asset symbol e.g., BTC, ETH');

            // Balances
            $table->decimal('amount', 18, 8)->default(0)
                ->comment('Available balance for trading/withdrawal');
            $table->decimal('locked_amount', 18, 8)->default(0)
                ->comment('Reserved for open sell orders');

            // Optimistic locking
            $table->unsignedInteger('version')->default(1);

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->unique(['user_id', 'symbol'], 'idx_assets_user_symbol');
            $table->index('symbol', 'idx_assets_symbol');
        });

        // Check constraints (MySQL 8.0.16+)
        DB::statement('ALTER TABLE assets ADD CONSTRAINT chk_amount_non_negative CHECK (amount >= 0)');
        DB::statement('ALTER TABLE assets ADD CONSTRAINT chk_locked_amount_non_negative CHECK (locked_amount >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
