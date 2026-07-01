<?php
use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use org\bovigo\vfs\vfsStream;

class TinyV2_TestCase extends TestCase
{
    /** @var org\bovigo\vfs\vfsStreamContainer */
    protected $vfs;

    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->vfs = vfsStream::setup();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }
}