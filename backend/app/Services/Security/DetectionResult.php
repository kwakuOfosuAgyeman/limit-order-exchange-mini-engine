<?php

namespace App\Services\Security;

use App\Enums\SecuritySeverity;

class DetectionResult
{
    public function __construct(
        public bool $detected = false,
        public array $threats = [],
        public ?SecuritySeverity $highestSeverity = null,
        public float $riskScore = 0,
        public ?DetectionContext $context = null
    ) {}

    public static function clean(?DetectionContext $context = null): self
    {
        return new self(detected: false, context: $context);
    }

    public function shouldThrottle(): bool
    {
        return $this->detected &&
               $this->highestSeverity &&
               !$this->highestSeverity->shouldBlock();
    }

    public function shouldBlock(): bool
    {
        return $this->detected &&
               $this->highestSeverity?->shouldBlock();
    }

    public function getThrottleDelay(): int
    {
        return $this->highestSeverity?->throttleDelayMs() ?? 0;
    }

    public function shouldAlert(): bool
    {
        return $this->detected &&
               $this->highestSeverity?->shouldAlert();
    }

    public function getPrimaryThreat(): ?array
    {
        return $this->threats[0] ?? null;
    }

    public function getAllRelatedOrders(): array
    {
        $orders = [];
        foreach ($this->threats as $threat) {
            if (isset($threat['related_orders'])) {
                $orders = array_merge($orders, $threat['related_orders']);
            }
        }

        return array_unique($orders);
    }

    public function getAllRelatedUsers(): array
    {
        $users = [];
        foreach ($this->threats as $threat) {
            if (isset($threat['related_users'])) {
                $users = array_merge($users, $threat['related_users']);
            }
        }

        return array_unique($users);
    }

    public function getThreatTypes(): array
    {
        return array_map(fn ($threat) => $threat['type']->value, $this->threats);
    }

    public function hasThreatType(string $type): bool
    {
        return in_array($type, $this->getThreatTypes());
    }
}
