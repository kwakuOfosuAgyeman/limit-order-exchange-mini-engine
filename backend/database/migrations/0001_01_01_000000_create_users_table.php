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
        Schema::create('users', function (Blueprint $table) {
            // Primary key
            $table->id();

            // Authentication (Laravel defaults)
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();

            // Financial balances (USD - quote currency)
            $table->decimal('balance', 18, 8)->default(0)
                ->comment('Available USD balance for trading');
            $table->decimal('locked_balance', 18, 8)->default(0)
                ->comment('USD reserved for open buy orders');

            // Optimistic locking for race condition detection
            $table->unsignedInteger('version')->default(1)
                ->comment('Incremented on every balance change for optimistic locking');

            // Soft delete and status
            $table->boolean('is_active')->default(true);
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 500)->nullable();

            // Timestamps
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['is_active', 'deleted_at'], 'idx_users_active');
        });

        // MySQL 8.0.16+
        DB::statement('ALTER TABLE users ADD CONSTRAINT chk_balance_non_negative CHECK (balance >= 0)');
        DB::statement('ALTER TABLE users ADD CONSTRAINT chk_locked_balance_non_negative CHECK (locked_balance >= 0)');

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
