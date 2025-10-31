<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Connection;
use Tourze\QUIC\Connection\ConnectionManager;

/**
 * ConnectionManager 类单元测试
 *
 * @internal
 */
#[CoversClass(ConnectionManager::class)]
final class ConnectionManagerTest extends TestCase
{
    private ConnectionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager = new ConnectionManager();
    }

    public function testInitialState(): void
    {
        $this->assertEquals(0, $this->manager->getConnectionCount());
        $this->assertEquals(1000, $this->manager->getMaxConnections());
        $this->assertEmpty($this->manager->getAllConnections());
    }

    public function testAddConnection(): void
    {
        $connection = new Connection(false);
        $this->manager->addConnection($connection);

        $this->assertEquals(1, $this->manager->getConnectionCount());
        $this->assertNotEmpty($this->manager->getAllConnections());

        $retrievedConnection = $this->manager->getConnection($connection->getLocalConnectionId());
        $this->assertSame($connection, $retrievedConnection);
    }

    public function testRemoveConnection(): void
    {
        $connection = new Connection(false);
        $connectionId = $connection->getLocalConnectionId();

        $this->manager->addConnection($connection);
        $this->assertEquals(1, $this->manager->getConnectionCount());

        $this->manager->removeConnection($connectionId);
        $this->assertEquals(0, $this->manager->getConnectionCount());
        $this->assertNull($this->manager->getConnection($connectionId));
    }

    public function testRemoveNonExistentConnection(): void
    {
        // 移除不存在的连接应该不会抛出异常
        $this->manager->removeConnection('non-existent-id');
        $this->assertEquals(0, $this->manager->getConnectionCount());
    }

    public function testSetMaxConnections(): void
    {
        $this->manager->setMaxConnections(50);
        $this->assertEquals(50, $this->manager->getMaxConnections());

        // 测试边界值
        $this->manager->setMaxConnections(0);
        $this->assertEquals(1, $this->manager->getMaxConnections()); // 应该被限制为最小1
    }

    public function testMaxConnectionsLimit(): void
    {
        $this->manager->setMaxConnections(2);

        // 添加两个连接
        $connection1 = new Connection(false);
        $connection2 = new Connection(false);

        $this->manager->addConnection($connection1);
        $this->manager->addConnection($connection2);

        $this->assertEquals(2, $this->manager->getConnectionCount());

        // 尝试添加第三个连接应该抛出异常
        $connection3 = new Connection(false);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('连接数已达上限');

        $this->manager->addConnection($connection3);
    }

    public function testGetStatistics(): void
    {
        $connection1 = new Connection(false);
        $connection2 = new Connection(true);

        $this->manager->addConnection($connection1);
        $this->manager->addConnection($connection2);

        $stats = $this->manager->getStatistics();

        $this->assertEquals(2, $stats['total_connections']);
        $this->assertEquals(1000, $stats['max_connections']);
        $this->assertGreaterThanOrEqual(2, $stats['connection_counter']);
        $this->assertArrayHasKey('by_state', $stats);
        $this->assertIsArray($stats['by_state']);
        $byState = $stats['by_state'];
        $this->assertEquals(2, $byState['new'] ?? 0);
    }

    public function testProcessPendingTasks(): void
    {
        $connection = new Connection(false);
        $this->manager->addConnection($connection);

        $initialConnectionCount = $this->manager->getConnectionCount();

        // 调用processPendingTasks不应该抛出异常
        $this->manager->processPendingTasks();

        // 验证连接数量没有改变（说明方法正常执行）
        $this->assertEquals($initialConnectionCount, $this->manager->getConnectionCount());

        // 验证连接仍然存在且可以检索
        $this->assertSame($connection, $this->manager->getConnection($connection->getLocalConnectionId()));
    }

    public function testCleanup(): void
    {
        $connection = new Connection(false);
        $this->manager->addConnection($connection);

        $this->assertEquals(1, $this->manager->getConnectionCount());

        // 关闭连接
        $connection->close();

        // 清理应该移除已关闭的连接
        $this->manager->cleanup();

        $this->assertEquals(0, $this->manager->getConnectionCount());
    }

    public function testCheckTimeouts(): void
    {
        $connection = new Connection(false);
        $this->manager->addConnection($connection);

        $initialConnectionCount = $this->manager->getConnectionCount();

        // 调用checkTimeouts不应该抛出异常
        $this->manager->checkTimeouts();

        // 验证连接数量没有改变（说明没有连接被超时清理）
        $this->assertEquals($initialConnectionCount, $this->manager->getConnectionCount());

        // 验证连接仍然存在
        $this->assertNotNull($this->manager->getConnection($connection->getLocalConnectionId()));
    }

    public function testCloseAllConnections(): void
    {
        $connection1 = new Connection(false);
        $connection2 = new Connection(false);

        $this->manager->addConnection($connection1);
        $this->manager->addConnection($connection2);

        $this->manager->closeAllConnections(123, 'shutdown');

        // 验证所有连接都已关闭
        $this->assertTrue($connection1->getStateMachine()->getState()->isClosed());
        $this->assertTrue($connection2->getStateMachine()->getState()->isClosed());

        // 验证关闭信息
        $closeInfo1 = $connection1->getStateMachine()->getCloseInfo();
        $closeInfo2 = $connection2->getStateMachine()->getCloseInfo();

        $this->assertEquals(123, $closeInfo1['errorCode']);
        $this->assertEquals('shutdown', $closeInfo1['reason']);
        $this->assertEquals(123, $closeInfo2['errorCode']);
        $this->assertEquals('shutdown', $closeInfo2['reason']);
    }

    public function testMultipleConnectionsWithDifferentIds(): void
    {
        $connection1 = new Connection(false);
        $connection2 = new Connection(false);

        $this->manager->addConnection($connection1);
        $this->manager->addConnection($connection2);

        $this->assertEquals(2, $this->manager->getConnectionCount());

        $allConnections = $this->manager->getAllConnections();
        $this->assertCount(2, $allConnections);

        // 确保每个连接都可以通过其ID检索
        $this->assertSame($connection1, $this->manager->getConnection($connection1->getLocalConnectionId()));
        $this->assertSame($connection2, $this->manager->getConnection($connection2->getLocalConnectionId()));
    }
}
