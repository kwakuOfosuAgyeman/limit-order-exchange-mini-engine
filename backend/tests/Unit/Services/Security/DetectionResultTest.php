<?php

namespace Tests\Unit\Services\Security;

use App\Enums\SecurityEventType;
use App\Enums\SecuritySeverity;
use App\Services\Security\DetectionResult;
use PHPUnit\Framework\TestCase;

class DetectionResultTest extends TestCase
{
    public function test_clean_returns_non_detected_result(): void
    {
        $result = DetectionResult::clean();

        $this->assertFalse($result->detected);
        $this->assertEmpty($result->threats);
        $this->assertNull($result->highestSeverity);
        $this->assertEquals(0, $result->riskScore);
    }

    public function test_detected_result_has_threats(): void
    {
        $threats = [
            [
                'type' => SecurityEventType::ORDER_SPOOFING,
                'severity' => SecuritySeverity::MEDIUM,
                'metrics' => ['cancel_rate' => 0.8],
            ],
        ];

        $result = new DetectionResult(
            detected: true,
            threats: $threats,
            highestSeverity: SecuritySeverity::MEDIUM,
            riskScore: 15.0
        );

        $this->assertTrue($result->detected);
        $this->assertCount(1, $result->threats);
        $this->assertEquals(SecuritySeverity::MEDIUM, $result->highestSeverity);
        $this->assertEquals(15.0, $result->riskScore);
    }

    public function test_should_throttle_when_detected_but_not_critical(): void
    {
        $result = new DetectionResult(
            detected: true,
            threats: [],
            highestSeverity: SecuritySeverity::MEDIUM
        );

        $this->assertTrue($result->shouldThrottle());
        $this->assertFalse($result->shouldBlock());
    }

    public function test_should_block_when_critical_severity(): void
    {
        $result = new DetectionResult(
            detected: true,
            threats: [],
            highestSeverity: SecuritySeverity::CRITICAL
        );

        $this->assertFalse($result->shouldThrottle());
        $this->assertTrue($result->shouldBlock());
    }

    public function test_clean_result_should_not_throttle_or_block(): void
    {
        $result = DetectionResult::clean();

        $this->assertFalse($result->shouldThrottle());
        $this->assertFalse($result->shouldBlock());
    }

    public function test_get_throttle_delay_returns_severity_delay(): void
    {
        $result = new DetectionResult(
            detected: true,
            threats: [],
            highestSeverity: SecuritySeverity::HIGH
        );

        $this->assertEquals(5000, $result->getThrottleDelay());
    }

    public function test_get_throttle_delay_returns_zero_when_no_severity(): void
    {
        $result = DetectionResult::clean();

        $this->assertEquals(0, $result->getThrottleDelay());
    }

    public function test_should_alert_for_medium_and_above(): void
    {
        $lowResult = new DetectionResult(
            detected: true,
            threats: [],
            highestSeverity: SecuritySeverity::LOW
        );

        $mediumResult = new DetectionResult(
            detected: true,
            threats: [],
            highestSeverity: SecuritySeverity::MEDIUM
        );

        $this->assertFalse($lowResult->shouldAlert());
        $this->assertTrue($mediumResult->shouldAlert());
    }

    public function test_get_primary_threat_returns_first_threat(): void
    {
        $threats = [
            ['type' => SecurityEventType::ORDER_SPOOFING, 'severity' => SecuritySeverity::MEDIUM],
            ['type' => SecurityEventType::LAYERING, 'severity' => SecuritySeverity::HIGH],
        ];

        $result = new DetectionResult(
            detected: true,
            threats: $threats,
            highestSeverity: SecuritySeverity::HIGH
        );

        $primaryThreat = $result->getPrimaryThreat();
        $this->assertEquals(SecurityEventType::ORDER_SPOOFING, $primaryThreat['type']);
    }

    public function test_get_primary_threat_returns_null_when_no_threats(): void
    {
        $result = DetectionResult::clean();

        $this->assertNull($result->getPrimaryThreat());
    }

    public function test_get_all_related_orders_aggregates_from_threats(): void
    {
        $threats = [
            [
                'type' => SecurityEventType::ORDER_SPOOFING,
                'severity' => SecuritySeverity::MEDIUM,
                'related_orders' => ['uuid-1', 'uuid-2'],
            ],
            [
                'type' => SecurityEventType::LAYERING,
                'severity' => SecuritySeverity::HIGH,
                'related_orders' => ['uuid-2', 'uuid-3'],
            ],
        ];

        $result = new DetectionResult(
            detected: true,
            threats: $threats,
            highestSeverity: SecuritySeverity::HIGH
        );

        $relatedOrders = $result->getAllRelatedOrders();

        $this->assertContains('uuid-1', $relatedOrders);
        $this->assertContains('uuid-2', $relatedOrders);
        $this->assertContains('uuid-3', $relatedOrders);
        // Check uniqueness - uuid-2 should only appear once
        $this->assertCount(3, $relatedOrders);
    }

    public function test_get_all_related_users_aggregates_from_threats(): void
    {
        $threats = [
            [
                'type' => SecurityEventType::WASH_TRADING,
                'severity' => SecuritySeverity::CRITICAL,
                'related_users' => [1, 2],
            ],
            [
                'type' => SecurityEventType::COORDINATED_TRADING,
                'severity' => SecuritySeverity::HIGH,
                'related_users' => [2, 3],
            ],
        ];

        $result = new DetectionResult(
            detected: true,
            threats: $threats,
            highestSeverity: SecuritySeverity::CRITICAL
        );

        $relatedUsers = $result->getAllRelatedUsers();

        $this->assertContains(1, $relatedUsers);
        $this->assertContains(2, $relatedUsers);
        $this->assertContains(3, $relatedUsers);
        $this->assertCount(3, $relatedUsers);
    }

    public function test_get_threat_types_returns_array_of_type_values(): void
    {
        $threats = [
            ['type' => SecurityEventType::ORDER_SPOOFING, 'severity' => SecuritySeverity::MEDIUM],
            ['type' => SecurityEventType::LAYERING, 'severity' => SecuritySeverity::HIGH],
        ];

        $result = new DetectionResult(
            detected: true,
            threats: $threats,
            highestSeverity: SecuritySeverity::HIGH
        );

        $types = $result->getThreatTypes();

        $this->assertEquals(['order_spoofing', 'layering'], $types);
    }

    public function test_has_threat_type_checks_for_specific_type(): void
    {
        $threats = [
            ['type' => SecurityEventType::ORDER_SPOOFING, 'severity' => SecuritySeverity::MEDIUM],
        ];

        $result = new DetectionResult(
            detected: true,
            threats: $threats,
            highestSeverity: SecuritySeverity::MEDIUM
        );

        $this->assertTrue($result->hasThreatType('order_spoofing'));
        $this->assertFalse($result->hasThreatType('wash_trading'));
    }
}
