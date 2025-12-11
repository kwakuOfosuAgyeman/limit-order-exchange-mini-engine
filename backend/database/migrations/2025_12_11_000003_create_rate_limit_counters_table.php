<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_limit_counters', function (Blueprint $table) {
            $table->id();

            // Composite key for lookup (e.g., "user:1:orders" or "ip:192.168.1.1:orders")
            $table->string('key', 255);
            $table->string('bucket', 50)->comment('Time bucket identifier');

            // Counters
            $table->integer('count')->default(0);
            $table->integer('cancel_count')->default(0);

            // Time window
            $table->timestamp('window_start')->useCurrent();
            $table->timestamp('window_end');
            $table->timestamp('updated_at')->useCurrent();

            // Unique constraint for atomic upsert operations
            $table->unique(['key', 'bucket']);

            // Index for cleanup of expired counters
            $table->index('window_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_counters');
    }
};
