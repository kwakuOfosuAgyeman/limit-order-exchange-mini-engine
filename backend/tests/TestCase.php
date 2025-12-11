<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Ensure attack detection config is loaded for tests
        config([
            'attack_detection.enabled' => true,
            'attack_detection.alerts.enabled' => false,
        ]);
    }
}
