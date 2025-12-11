<?php

namespace App\Console\Commands;

use App\Models\RateLimitCounter;
use Illuminate\Console\Command;

class CleanupRateLimitCounters extends Command
{
    protected $signature = 'security:cleanup-counters';

    protected $description = 'Clean up expired rate limit counters';

    public function handle(): int
    {
        $deleted = RateLimitCounter::cleanupExpired();
        $this->info("Cleaned up {$deleted} expired rate limit counters.");

        return Command::SUCCESS;
    }
}
