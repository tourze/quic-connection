<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Connection;
use Tourze\QUIC\Connection\ConnectionStateMachine;
use Tourze\QUIC\Connection\IdleTimeoutManager;

/**
 * @covers \Tourze\QUIC\Connection\IdleTimeoutManager
 */
class IdleTimeoutManagerTest extends TestCase
{
    private IdleTimeoutManager $timeoutManager;
    private ConnectionStateMachine $stateMachine;

    protected function setUp(): void
    {
        // Create a real ConnectionStateMachine for testing since mocking enums is problematic
        $connection = new Connection(false, 'test-connection-id');
        $this->stateMachine = $connection->getStateMachine();
        $this->timeoutManager = new IdleTimeoutManager($this->stateMachine);
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(IdleTimeoutManager::class, $this->timeoutManager);
    }

    public function testSetIdleTimeout(): void
    {
        $timeout = 30000; // 30 seconds
        $this->timeoutManager->setIdleTimeout($timeout);
        
        $this->assertEquals($timeout, $this->timeoutManager->getIdleTimeout());
    }

    public function testUpdateActivity(): void
    {
        // Set a smaller timeout to make the test more predictable
        $this->timeoutManager->setIdleTimeout(10000); // 10 seconds
        
        // Wait a bit to let some time pass
        usleep(5000); // 5ms delay
        $timeAfterDelay = $this->timeoutManager->getTimeToTimeout();
        
        // Update activity - this should reset the timeout
        $this->timeoutManager->updateActivity();
        $timeAfterUpdate = $this->timeoutManager->getTimeToTimeout();
        
        // After updating activity, time to timeout should be greater (closer to full timeout)
        $this->assertGreaterThan($timeAfterDelay, $timeAfterUpdate);
    }

    public function testCheckTimeout(): void
    {
        // With default timeout, should not timeout immediately
        $this->assertFalse($this->timeoutManager->checkTimeout());
        
        // Set very small timeout
        $this->timeoutManager->setIdleTimeout(1); // 1ms
        usleep(2000); // 2ms delay
        
        $this->assertTrue($this->timeoutManager->checkTimeout());
    }
}