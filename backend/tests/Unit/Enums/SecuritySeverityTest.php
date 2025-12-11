<?php

namespace Tests\Unit\Enums;

use App\Enums\SecuritySeverity;
use PHPUnit\Framework\TestCase;

class SecuritySeverityTest extends TestCase
{
    public function test_all_severity_levels_have_string_values(): void
    {
        $expectedLevels = ['low', 'medium', 'high', 'critical'];
        $actualLevels = array_map(fn ($case) => $case->value, SecuritySeverity::cases());

        $this->assertEquals($expectedLevels, $actualLevels);
    }

    public function test_label_returns_human_readable_string(): void
    {
        $this->assertEquals('Low', SecuritySeverity::LOW->label());
        $this->assertEquals('Medium', SecuritySeverity::MEDIUM->label());
        $this->assertEquals('High', SecuritySeverity::HIGH->label());
        $this->assertEquals('Critical', SecuritySeverity::CRITICAL->label());
    }

    public function test_throttle_delay_increases_with_severity(): void
    {
        $lowDelay = SecuritySeverity::LOW->throttleDelayMs();
        $mediumDelay = SecuritySeverity::MEDIUM->throttleDelayMs();
        $highDelay = SecuritySeverity::HIGH->throttleDelayMs();

        $this->assertGreaterThan($lowDelay, $mediumDelay);
        $this->assertGreaterThan($mediumDelay, $highDelay);
    }

    public function test_critical_has_zero_throttle_delay_because_it_blocks(): void
    {
        $this->assertEquals(0, SecuritySeverity::CRITICAL->throttleDelayMs());
    }

    public function test_specific_throttle_delays(): void
    {
        $this->assertEquals(500, SecuritySeverity::LOW->throttleDelayMs());
        $this->assertEquals(2000, SecuritySeverity::MEDIUM->throttleDelayMs());
        $this->assertEquals(5000, SecuritySeverity::HIGH->throttleDelayMs());
        $this->assertEquals(0, SecuritySeverity::CRITICAL->throttleDelayMs());
    }

    public function test_only_critical_should_block(): void
    {
        $this->assertFalse(SecuritySeverity::LOW->shouldBlock());
        $this->assertFalse(SecuritySeverity::MEDIUM->shouldBlock());
        $this->assertFalse(SecuritySeverity::HIGH->shouldBlock());
        $this->assertTrue(SecuritySeverity::CRITICAL->shouldBlock());
    }

    public function test_medium_and_above_should_alert(): void
    {
        $this->assertFalse(SecuritySeverity::LOW->shouldAlert());
        $this->assertTrue(SecuritySeverity::MEDIUM->shouldAlert());
        $this->assertTrue(SecuritySeverity::HIGH->shouldAlert());
        $this->assertTrue(SecuritySeverity::CRITICAL->shouldAlert());
    }

    public function test_numeric_value_increases_with_severity(): void
    {
        $this->assertEquals(1, SecuritySeverity::LOW->numericValue());
        $this->assertEquals(2, SecuritySeverity::MEDIUM->numericValue());
        $this->assertEquals(3, SecuritySeverity::HIGH->numericValue());
        $this->assertEquals(4, SecuritySeverity::CRITICAL->numericValue());
    }

    public function test_numeric_values_can_be_used_for_comparison(): void
    {
        $this->assertLessThan(
            SecuritySeverity::HIGH->numericValue(),
            SecuritySeverity::MEDIUM->numericValue()
        );

        $this->assertGreaterThan(
            SecuritySeverity::MEDIUM->numericValue(),
            SecuritySeverity::CRITICAL->numericValue()
        );
    }
}
