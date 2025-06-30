<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Connection;
use Tourze\QUIC\Connection\ConnectionMonitor;

/**
 * @covers \Tourze\QUIC\Connection\ConnectionMonitor
 */
class ConnectionMonitorTest extends TestCase
{
    private ConnectionMonitor $monitor;
    private Connection $connection;

    protected function setUp(): void
    {
        // Create a real Connection object for testing since mocking enums is problematic
        $this->connection = new Connection(false, 'test-connection-id');
        $this->monitor = new ConnectionMonitor($this->connection);
    }

    public function testCanBeInstantiated(): void
    {
        $this->assertInstanceOf(ConnectionMonitor::class, $this->monitor);
    }

    public function testGetStatistics(): void
    {
        $stats = $this->monitor->getStatistics();
        
        $this->assertArrayHasKey('packets_sent', $stats);
        $this->assertArrayHasKey('packets_received', $stats);
        $this->assertArrayHasKey('bytes_sent', $stats);
        $this->assertArrayHasKey('bytes_received', $stats);
        $this->assertArrayHasKey('connection_time', $stats);
    }

    public function testResetStatistics(): void
    {
        $this->monitor->resetStatistics();
        $stats = $this->monitor->getStatistics();
        
        $this->assertEquals(0, $stats['packets_sent']);
        $this->assertEquals(0, $stats['packets_received']);
        $this->assertEquals(0, $stats['bytes_sent']);
        $this->assertEquals(0, $stats['bytes_received']);
    }
}