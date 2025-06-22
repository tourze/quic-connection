<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Connection;
use Tourze\QUIC\Connection\Enum\ConnectionState;

/**
 * Connection 类单元测试
 */
class ConnectionTest extends TestCase
{
    public function test_create_client_connection(): void
    {
        $connection = new Connection(false);
        
        $this->assertFalse($connection->isServer());
        $this->assertEquals(ConnectionState::NEW, $connection->getStateMachine()->getState());
        $this->assertNotEmpty($connection->getLocalConnectionId());
    }

    public function test_create_server_connection(): void
    {
        $connection = new Connection(true);
        
        $this->assertTrue($connection->isServer());
        $this->assertEquals(ConnectionState::NEW, $connection->getStateMachine()->getState());
    }

    public function test_connection_with_custom_id(): void
    {
        $customId = 'test-connection-id';
        $connection = new Connection(false, $customId);
        
        $this->assertEquals($customId, $connection->getLocalConnectionId());
    }

    public function test_connect_changes_state(): void
    {
        $connection = new Connection(false);
        
        $connection->connect('127.0.0.1', 443);
        
        $this->assertEquals(ConnectionState::HANDSHAKING, $connection->getStateMachine()->getState());
    }

    public function test_close_connection(): void
    {
        $connection = new Connection(false);
        
        $connection->close(0, 'test close');
        
        $this->assertEquals(ConnectionState::CLOSED, $connection->getStateMachine()->getState());
        
        $closeInfo = $connection->getStateMachine()->getCloseInfo();
        $this->assertEquals('test close', $closeInfo['reason']);
    }

    public function test_close_connection_from_handshaking(): void
    {
        $connection = new Connection(false);
        
        $connection->connect('127.0.0.1', 443);
        $this->assertEquals(ConnectionState::HANDSHAKING, $connection->getStateMachine()->getState());
        
        $connection->close(0, 'test close from handshaking');
        
        $this->assertEquals(ConnectionState::CLOSING, $connection->getStateMachine()->getState());
        
        $closeInfo = $connection->getStateMachine()->getCloseInfo();
        $this->assertEquals('test close from handshaking', $closeInfo['reason']);
    }

    public function test_transport_parameters(): void
    {
        $connection = new Connection(false);
        
        $connection->setTransportParameter('test_param', 'test_value');
        
        $this->assertEquals('test_value', $connection->getTransportParameter('test_param'));
        $this->assertArrayHasKey('test_param', $connection->getTransportParameters());
    }

    public function test_event_registration_and_trigger(): void
    {
        $connection = new Connection(false);
        $eventTriggered = false;
        
        $connection->onEvent('test_event', function() use (&$eventTriggered) {
            $eventTriggered = true;
        });
        
        $connection->triggerEvent('test_event');
        
        $this->assertTrue($eventTriggered);
    }

    public function test_path_manager_initialization(): void
    {
        $connection = new Connection(false);
        
        $connection->connect('192.168.1.100', 443, '192.168.1.10', 8080);
        
        $pathManager = $connection->getPathManager();
        $activePath = $pathManager->getActivePath();
        
        $this->assertNotNull($activePath);
        $this->assertEquals('192.168.1.10', $activePath['local_address']);
        $this->assertEquals(8080, $activePath['local_port']);
        $this->assertEquals('192.168.1.100', $activePath['remote_address']);
        $this->assertEquals(443, $activePath['remote_port']);
    }

    public function test_idle_timeout_manager(): void
    {
        $connection = new Connection(false);
        $idleManager = $connection->getIdleTimeoutManager();
        
        // 测试默认超时时间
        $this->assertGreaterThan(0, $idleManager->getIdleTimeout());
        
        // 测试设置超时时间
        $idleManager->setIdleTimeout(30000);
        $this->assertEquals(30000, $idleManager->getIdleTimeout());
        
        // 测试活动时间更新
        $timeBeforeUpdate = $idleManager->getTimeToTimeout();
        $idleManager->updateActivity();
        $timeAfterUpdate = $idleManager->getTimeToTimeout();
        
        $this->assertGreaterThanOrEqual($timeBeforeUpdate, $timeAfterUpdate);
    }

    public function test_send_frame_when_not_connected(): void
    {
        $connection = new Connection(false);
        
        // 创建一个mock Frame对象
        $frame = $this->createMock(\Tourze\QUIC\Frames\Frame::class);
        
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('连接状态不允许发送数据');
        
        $connection->sendFrame($frame);
    }

    public function test_process_pending_tasks(): void
    {
        $connection = new Connection(false);
        
        // 调用processPendingTasks不应该抛出异常
        $connection->processPendingTasks();
        
        $this->assertTrue(true); // 如果没有异常，测试通过
    }

    public function test_multiple_event_handlers(): void
    {
        $connection = new Connection(false);
        $callCount = 0;
        
        // 注册多个事件处理器
        $connection->onEvent('test_event', function() use (&$callCount) {
            $callCount++;
        });
        
        $connection->onEvent('test_event', function() use (&$callCount) {
            $callCount++;
        });
        
        $connection->triggerEvent('test_event');
        
        $this->assertEquals(2, $callCount);
    }

    public function test_connection_id_generation(): void
    {
        $connection1 = new Connection(false);
        $connection2 = new Connection(false);
        
        // 两个连接应该有不同的ID
        $this->assertNotEquals(
            $connection1->getLocalConnectionId(),
            $connection2->getLocalConnectionId()
        );
        
        // 连接ID不应该为空
        $this->assertNotEmpty($connection1->getLocalConnectionId());
        $this->assertNotEmpty($connection2->getLocalConnectionId());
    }

    public function test_invalid_state_transition(): void
    {
        $connection = new Connection(false);
        
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('无效状态转换');
        
        // 尝试从NEW直接转到CONNECTED，这是无效的
        $connection->getStateMachine()->transitionTo(\Tourze\QUIC\Connection\Enum\ConnectionState::CONNECTED);
    }
} 