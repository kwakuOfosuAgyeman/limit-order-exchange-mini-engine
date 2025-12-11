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
        Schema::create('system_settings', function (Blueprint $table) {
            $table->id();

            $table->string('key', 100)->unique();
            $table->text('value');
            $table->enum('value_type', ['string', 'integer', 'decimal', 'boolean', 'json'])->default('string');

            $table->string('description', 500)->nullable();
            $table->boolean('is_public')->default(false)
                ->comment('Whether to expose in public API');

            $table->timestamps();

            // Indexes
            $table->index('is_public', 'idx_settings_public');
        });

        // Default settings
        DB::table('system_settings')->insert([
            ['key' => 'trading_enabled', 'value' => 'true', 'value_type' => 'boolean', 'description' => 'Global trading toggle', 'is_public' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'default_fee_rate', 'value' => '0.015', 'value_type' => 'decimal', 'description' => 'Default commission rate (1.5%)', 'is_public' => false, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'min_order_value_usd', 'value' => '1.00', 'value_type' => 'decimal', 'description' => 'Minimum order value in USD', 'is_public' => true, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'max_open_orders_per_user', 'value' => '100', 'value_type' => 'integer', 'description' => 'Maximum open orders per user', 'is_public' => false, 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'maintenance_mode', 'value' => 'false', 'value_type' => 'boolean', 'description' => 'System maintenance mode', 'is_public' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('system_settings');
    }
};
