<?php

namespace App\Enums;

enum OrderStatus: int
{
    case OPEN = 1;
    case FILLED = 2;
    case CANCELLED = 3;
    case PARTIALLY_FILLED = 4;
    case EXPIRED = 5;

    public function label(): string
    {
        return match($this) {
            self::OPEN => 'Open',
            self::FILLED => 'Filled',
            self::CANCELLED => 'Cancelled',
            self::PARTIALLY_FILLED => 'Partially Filled',
            self::EXPIRED => 'Expired',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::OPEN, self::PARTIALLY_FILLED]);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::FILLED, self::CANCELLED, self::EXPIRED]);
    }

    /**
     * Valid transitions from current status
     */
    public function allowedTransitions(): array
    {
        return match($this) {
            self::OPEN => [self::FILLED, self::PARTIALLY_FILLED, self::CANCELLED, self::EXPIRED],
            self::PARTIALLY_FILLED => [self::FILLED, self::CANCELLED],
            self::FILLED => [],
            self::CANCELLED => [],
            self::EXPIRED => [],
        };
    }

    public function canTransitionTo(OrderStatus $newStatus): bool
    {
        return in_array($newStatus, $this->allowedTransitions());
    }
}
