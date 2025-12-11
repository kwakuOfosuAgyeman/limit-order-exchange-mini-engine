<?php

namespace App\Enums;

enum SecuritySeverity: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::LOW => 'Low',
            self::MEDIUM => 'Medium',
            self::HIGH => 'High',
            self::CRITICAL => 'Critical',
        };
    }

    public function throttleDelayMs(): int
    {
        return match ($this) {
            self::LOW => 500,      // 0.5 second delay
            self::MEDIUM => 2000,  // 2 second delay
            self::HIGH => 5000,    // 5 second delay
            self::CRITICAL => 0,   // Block immediately (no delay, just reject)
        };
    }

    public function shouldBlock(): bool
    {
        return $this === self::CRITICAL;
    }

    public function shouldAlert(): bool
    {
        return in_array($this, [self::MEDIUM, self::HIGH, self::CRITICAL]);
    }

    public function numericValue(): int
    {
        return match ($this) {
            self::LOW => 1,
            self::MEDIUM => 2,
            self::HIGH => 3,
            self::CRITICAL => 4,
        };
    }
}
