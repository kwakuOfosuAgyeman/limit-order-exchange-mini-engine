<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DecayRiskScores extends Command
{
    protected $signature = 'security:decay-risk-scores';

    protected $description = 'Decay user risk scores over time';

    public function handle(): int
    {
        $decayRate = config('attack_detection.risk_scoring.decay_rate_per_day');

        $updated = User::where('risk_score', '>', 0)
            ->where(function ($query) {
                $query->whereNull('risk_score_updated_at')
                    ->orWhere('risk_score_updated_at', '<', now()->subDay());
            })
            ->update([
                'risk_score' => DB::raw("GREATEST(0, risk_score - {$decayRate})"),
                'risk_score_updated_at' => now(),
            ]);

        $this->info("Decayed risk scores for {$updated} users by {$decayRate} points.");

        // Clear security_review_required for users who dropped below threshold
        $flagThreshold = config('attack_detection.risk_scoring.auto_flag_threshold');
        $clearedFlags = User::where('security_review_required', true)
            ->where('risk_score', '<', $flagThreshold)
            ->update(['security_review_required' => false]);

        if ($clearedFlags > 0) {
            $this->info("Cleared security review flag for {$clearedFlags} users.");
        }

        return Command::SUCCESS;
    }
}
