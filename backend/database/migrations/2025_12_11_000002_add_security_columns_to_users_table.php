<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('risk_score', 5, 2)->default(0)->after('suspension_reason')
                ->comment('Cumulative risk score 0-100');
            $table->timestamp('risk_score_updated_at')->nullable()->after('risk_score');
            $table->integer('security_event_count')->default(0)->after('risk_score_updated_at');
            $table->timestamp('last_security_event_at')->nullable()->after('security_event_count');
            $table->boolean('security_review_required')->default(false)->after('last_security_event_at');

            $table->index(['risk_score', 'security_review_required']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['risk_score', 'security_review_required']);
            $table->dropColumn([
                'risk_score',
                'risk_score_updated_at',
                'security_event_count',
                'last_security_event_at',
                'security_review_required',
            ]);
        });
    }
};
