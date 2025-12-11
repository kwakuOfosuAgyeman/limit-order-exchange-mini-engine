<?php

namespace Tests\Unit\Models;

use App\Models\RateLimitCounter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateLimitCounterTest extends TestCase
{
    use RefreshDatabase;

    public function test_increment_and_get_creates_new_counter(): void
    {
        $count = RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('rate_limit_counters', [
            'key' => 'user:1:orders',
            'count' => 1,
        ]);
    }

    public function test_increment_and_get_increments_existing_counter(): void
    {
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);
        $count = RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);

        $this->assertEquals(3, $count);
    }

    public function test_increment_and_get_supports_different_counter_fields(): void
    {
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);
        RateLimitCounter::incrementAndGet('user:1:orders', 'cancel_count', 1);

        $counter = RateLimitCounter::where('key', 'user:1:orders')->first();

        $this->assertEquals(1, $counter->count);
        $this->assertEquals(1, $counter->cancel_count);
    }

    public function test_get_current_count_returns_sum_of_active_windows(): void
    {
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);

        $count = RateLimitCounter::getCurrentCount('user:1:orders');

        $this->assertEquals(2, $count);
    }

    public function test_get_current_count_returns_zero_for_nonexistent_key(): void
    {
        $count = RateLimitCounter::getCurrentCount('nonexistent:key');

        $this->assertEquals(0, $count);
    }

    public function test_get_count_in_window_filters_by_time(): void
    {
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);

        $count = RateLimitCounter::getCountInWindow('user:1:orders', 1);

        $this->assertEquals(1, $count);
    }

    public function test_cleanup_expired_removes_old_counters(): void
    {
        // Create a counter
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);

        // Manually set window_end to past
        RateLimitCounter::where('key', 'user:1:orders')
            ->update(['window_end' => now()->subHours(2)]);

        $deleted = RateLimitCounter::cleanupExpired();

        $this->assertEquals(1, $deleted);
        $this->assertDatabaseMissing('rate_limit_counters', [
            'key' => 'user:1:orders',
        ]);
    }

    public function test_cleanup_expired_does_not_remove_active_counters(): void
    {
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);

        $deleted = RateLimitCounter::cleanupExpired();

        $this->assertEquals(0, $deleted);
        $this->assertDatabaseHas('rate_limit_counters', [
            'key' => 'user:1:orders',
        ]);
    }

    public function test_reset_key_removes_all_counters_for_key(): void
    {
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);
        RateLimitCounter::incrementAndGet('user:2:orders', 'count', 1);

        $deleted = RateLimitCounter::resetKey('user:1:orders');

        $this->assertEquals(1, $deleted);
        $this->assertDatabaseMissing('rate_limit_counters', [
            'key' => 'user:1:orders',
        ]);
        $this->assertDatabaseHas('rate_limit_counters', [
            'key' => 'user:2:orders',
        ]);
    }

    public function test_different_keys_have_independent_counters(): void
    {
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);
        RateLimitCounter::incrementAndGet('user:1:orders', 'count', 1);
        RateLimitCounter::incrementAndGet('user:2:orders', 'count', 1);

        $user1Count = RateLimitCounter::getCurrentCount('user:1:orders');
        $user2Count = RateLimitCounter::getCurrentCount('user:2:orders');

        $this->assertEquals(2, $user1Count);
        $this->assertEquals(1, $user2Count);
    }

    public function test_ip_based_key_works_correctly(): void
    {
        $count = RateLimitCounter::incrementAndGet('ip:192.168.1.1:orders', 'count', 1);

        $this->assertEquals(1, $count);
        $this->assertDatabaseHas('rate_limit_counters', [
            'key' => 'ip:192.168.1.1:orders',
        ]);
    }
}
