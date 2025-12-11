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
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->enum('type', ['deposit', 'withdrawal']);
            $table->string('currency', 10);

            $table->decimal('amount', 18, 8);
            $table->decimal('fee', 18, 8)->default(0);

            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'cancelled'])->default('pending');

            // External reference
            $table->string('external_id', 255)->nullable();
            $table->string('tx_hash', 255)->nullable();

            // Metadata
            $table->string('address', 255)->nullable();
            $table->json('metadata')->nullable();

            // Timestamps
            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            // Indexes
            $table->index(['user_id', 'type', 'created_at'], 'idx_transactions_user');
            $table->index(['status', 'type'], 'idx_transactions_status');
        });

        // Add generated column for net_amount
        DB::statement('ALTER TABLE transactions ADD COLUMN net_amount DECIMAL(18, 8) GENERATED ALWAYS AS (amount - fee) STORED AFTER fee');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
