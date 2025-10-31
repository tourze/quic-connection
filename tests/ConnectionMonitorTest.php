<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Connection;
use Tourze\QUIC\Connection\ConnectionMonitor;

/**
 * @internal
 */
#[CoversClass(ConnectionMonitor::class)]
final class ConnectionMonitorTest extends TestCase
{
    private ConnectionMonitor $monitor;

    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function testOnConnected(): void
    {
        $this->monitor->onConnected($this->connection);
        $stats = $this->monitor->getStatistics();

        $this->assertArrayHasKey('connection_time', $stats);
        $this->assertGreaterThanOrEqual(0, $stats['connection_time']);
    }

    public function testOnDisconnected(): void
    {
        $this->monitor->onDisconnected($this->connection, 0, 'test');

        // 断开连接不应该抛出异常
        $this->assertInstanceOf(ConnectionMonitor::class, $this->monitor);
    }

    public function testOnError(): void
    {
        $error = new \RuntimeException('Test error');
        $this->monitor->onError($this->connection, $error);

        $stats = $this->monitor->getStatistics();
        $this->assertEquals(1, $stats['errors']);
    }

    public function testOnPacketSent(): void
    {
        $data = ['size' => 100];
        $this->monitor->onPacketSent($this->connection, $data);

        $stats = $this->monitor->getStatistics();
        $this->assertEquals(1, $stats['packets_sent']);
        $this->assertEquals(100, $stats['bytes_sent']);
    }

    public function testOnPacketReceived(): void
    {
        $data = ['size' => 150];
        $this->monitor->onPacketReceived($this->connection, $data);

        $stats = $this->monitor->getStatistics();
        $this->assertEquals(1, $stats['packets_received']);
        $this->assertEquals(150, $stats['bytes_received']);
    }

    public function testOnStateChanged(): void
    {
        $data = ['from' => 'new', 'to' => 'handshaking'];
        $this->monitor->onStateChanged($this->connection, $data);

        $stats = $this->monitor->getStatistics();
        $this->assertEquals(1, $stats['state_changes']);
    }
}
