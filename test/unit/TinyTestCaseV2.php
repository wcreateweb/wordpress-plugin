<?php
use PHPUnit\Framework\TestCase;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Brain\Monkey;

class TinyTestCaseV2 extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }
}