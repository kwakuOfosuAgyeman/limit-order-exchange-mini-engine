<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RateLimitCounter extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'key',
        'bucket',
        'count',
        'cancel_count',
        'window_start',
        'window_end',
        'updated_at',
    ];

    protected $casts = [
        'window_start' => 'datetime',
        'window_end' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Atomically increment counter and return new value.
     */
    public static function incrementAndGet(
        string $key,
        string $counterField = 'count',
        int $windowMinutes = 1
    ): int {
        $bucket = now()->format('Y-m-d-H-i');
        $windowEnd = now()->addMinutes($windowMinutes)->format('Y-m-d H:i:s');
        $now = now()->format('Y-m-d H:i:s');

        // Use INSERT ... ON DUPLICATE KEY UPDATE for atomic operation
        DB::statement("
            INSERT INTO rate_limit_counters (`key`, bucket, `{$counterField}`, window_start, window_end, updated_at)
            VALUES (?, ?, 1, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                `{$counterField}` = `{$counterField}` + 1,
                updated_at = ?
        ", [$key, $bucket, $now, $windowEnd, $now, $now]);

        return self::where('key', $key)
            ->where('bucket', $bucket)
            ->value($counterField) ?? 0;
    }

    /**
     * Get current count for a key within active time windows.
     */
    public static function getCurrentCount(string $key, string $counterField = 'count'): int
    {
        return (int) self::where('key', $key)
            ->where('window_end', '>', now())
            ->sum($counterField);
    }

    /**
     * Get counts for a key within the last N minutes.
     */
    public static function getCountInWindow(string $key, int $windowMinutes = 1, string $counterField = 'count'): int
    {
        return (int) self::where('key', $key)
            ->where('window_start', '>=', now()->subMinutes($windowMinutes))
            ->sum($counterField);
    }

    /**
     * Clean up expired counters.
     */
    public static function cleanupExpired(): int
    {
        return self::where('window_end', '<', now()->subHour())->delete();
    }

    /**
     * Reset counters for a specific key.
     */
    public static function resetKey(string $key): int
    {
        return self::where('key', $key)->delete();
    }
}
