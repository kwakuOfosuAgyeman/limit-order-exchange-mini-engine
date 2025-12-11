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
        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // State transition
            $table->unsignedTinyInteger('status_from')->nullable()
                ->comment('NULL for initial creation');
            $table->unsignedTinyInteger('status_to');

            // Context
            $table->enum('changed_by', ['system', 'user', 'admin', 'expiry'])->default('system');
            $table->string('reason', 255)->nullable();

            // Metadata
            $table->json('metadata')->nullable()
                ->comment('Snapshot of order state at transition');

            // Timestamps
            $table->timestamp('created_at')->useCurrent();

            // Indexes
            $table->index(['order_id', 'created_at'], 'idx_order_history_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
    }
};
