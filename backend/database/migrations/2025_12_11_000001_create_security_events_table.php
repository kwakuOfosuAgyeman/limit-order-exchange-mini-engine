<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            // Event classification
            $table->string('event_type', 30);
            $table->string('severity', 20);

            // Actor identification
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ip_address', 45);
            $table->string('user_agent', 500)->nullable();

            // Context
            $table->string('symbol', 20)->nullable();
            $table->string('endpoint', 100);
            $table->string('http_method', 10);

            // Detection details
            $table->json('detection_metrics')->comment('Threshold values that triggered detection');
            $table->json('related_orders')->nullable()->comment('Order UUIDs involved in the event');
            $table->json('related_users')->nullable()->comment('Other user IDs involved (wash trading)');

            // Action taken
            $table->string('action_taken', 30)->default('logged');
            $table->integer('throttle_delay_ms')->nullable();

            // Risk scoring
            $table->decimal('risk_score', 5, 2)->default(0)->comment('0-100 risk score contribution');

            // Alert tracking
            $table->boolean('alert_sent')->default(false);
            $table->timestamp('alert_sent_at')->nullable();

            // Resolution
            $table->boolean('reviewed')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('resolution', 20)->default('pending');

            $table->timestamps();

            // Indexes for efficient querying
            $table->index(['user_id', 'event_type', 'created_at']);
            $table->index(['ip_address', 'created_at']);
            $table->index(['severity', 'reviewed', 'created_at']);
            $table->index(['event_type', 'created_at']);
            $table->index(['symbol', 'event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
