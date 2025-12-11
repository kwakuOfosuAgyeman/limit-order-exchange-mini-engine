<?php

namespace Tests\Unit\Enums;

use App\Enums\SecurityEventType;
use App\Enums\SecuritySeverity;
use PHPUnit\Framework\TestCase;

class SecurityEventTypeTest extends TestCase
{
    public function test_all_event_types_have_string_values(): void
    {
        $expectedTypes = [
            'order_spoofing',
            'wash_trading',
            'layering',
            'price_manipulation',
            'rapid_fire_spam',
            'suspicious_pattern',
            'coordinated_trading',
        ];

        $actualTypes = array_map(fn ($case) => $case->value, SecurityEventType::cases());

        $this->assertEquals($expectedTypes, $actualTypes);
    }

    public function test_label_returns_human_readable_string(): void
    {
        $this->assertEquals('Order Spoofing', SecurityEventType::ORDER_SPOOFING->label());
        $this->assertEquals('Wash Trading', SecurityEventType::WASH_TRADING->label());
        $this->assertEquals('Layering Attack', SecurityEventType::LAYERING->label());
        $this->assertEquals('Price Manipulation', SecurityEventType::PRICE_MANIPULATION->label());
        $this->assertEquals('Rapid-Fire Spam', SecurityEventType::RAPID_FIRE_SPAM->label());
        $this->assertEquals('Suspicious Pattern', SecurityEventType::SUSPICIOUS_PATTERN->label());
        $this->assertEquals('Coordinated Trading', SecurityEventType::COORDINATED_TRADING->label());
    }

    public function test_base_severity_returns_security_severity(): void
    {
        $this->assertEquals(SecuritySeverity::MEDIUM, SecurityEventType::ORDER_SPOOFING->baseSeverity());
        $this->assertEquals(SecuritySeverity::HIGH, SecurityEventType::WASH_TRADING->baseSeverity());
        $this->assertEquals(SecuritySeverity::HIGH, SecurityEventType::LAYERING->baseSeverity());
        $this->assertEquals(SecuritySeverity::CRITICAL, SecurityEventType::PRICE_MANIPULATION->baseSeverity());
        $this->assertEquals(SecuritySeverity::LOW, SecurityEventType::RAPID_FIRE_SPAM->baseSeverity());
        $this->assertEquals(SecuritySeverity::LOW, SecurityEventType::SUSPICIOUS_PATTERN->baseSeverity());
        $this->assertEquals(SecuritySeverity::MEDIUM, SecurityEventType::COORDINATED_TRADING->baseSeverity());
    }

    public function test_risk_weight_returns_positive_float(): void
    {
        foreach (SecurityEventType::cases() as $type) {
            $weight = $type->riskWeight();
            $this->assertIsFloat($weight);
            $this->assertGreaterThan(0, $weight);
        }
    }

    public function test_price_manipulation_has_highest_risk_weight(): void
    {
        $weights = [];
        foreach (SecurityEventType::cases() as $type) {
            $weights[$type->value] = $type->riskWeight();
        }

        $maxWeight = max($weights);
        $this->assertEquals(SecurityEventType::PRICE_MANIPULATION->riskWeight(), $maxWeight);
    }

    public function test_suspicious_pattern_has_lowest_risk_weight(): void
    {
        $weights = [];
        foreach (SecurityEventType::cases() as $type) {
            $weights[$type->value] = $type->riskWeight();
        }

        $minWeight = min($weights);
        $this->assertEquals(SecurityEventType::SUSPICIOUS_PATTERN->riskWeight(), $minWeight);
    }
}
