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
        Schema::create('fee_tiers', function (Blueprint $table) {
            $table->id();

            $table->string('name', 50);
            $table->string('description', 255)->nullable();

            // Fee rates (stored as decimals, e.g., 0.015 = 1.5%)
            $table->decimal('maker_fee_rate', 8, 6)->default(0.001000)
                ->comment('Fee for resting orders');
            $table->decimal('taker_fee_rate', 8, 6)->default(0.001500)
                ->comment('Fee for matching against resting orders');

            // Tier qualification
            $table->decimal('min_30d_volume', 18, 2)->default(0.00)
                ->comment('Minimum 30-day trading volume in USD');

            // Status
            $table->boolean('is_active')->default(true);

            // Timestamps
            $table->timestamps();

            // Indexes
            $table->index(['min_30d_volume', 'is_active'], 'idx_fee_tiers_volume');
        });

        // Default tiers
        DB::table('fee_tiers')->insert([
            ['name' => 'Standard', 'maker_fee_rate' => 0.001000, 'taker_fee_rate' => 0.001500, 'min_30d_volume' => 0.00, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bronze', 'maker_fee_rate' => 0.000800, 'taker_fee_rate' => 0.001200, 'min_30d_volume' => 10000.00, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Silver', 'maker_fee_rate' => 0.000600, 'taker_fee_rate' => 0.001000, 'min_30d_volume' => 50000.00, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gold', 'maker_fee_rate' => 0.000400, 'taker_fee_rate' => 0.000800, 'min_30d_volume' => 100000.00, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Platinum', 'maker_fee_rate' => 0.000200, 'taker_fee_rate' => 0.000500, 'min_30d_volume' => 500000.00, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fee_tiers');
    }
};
