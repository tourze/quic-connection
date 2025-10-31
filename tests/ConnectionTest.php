<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Connection;
use Tourze\QUIC\Core\Enum\ConnectionState;
use Tourze\QUIC\Core\Enum\FrameType;
use Tourze\QUIC\Frames\Frame;
use Tourze\QUIC\Packets\Packet;
use Tourze\QUIC\Packets\PacketType;

/**
 * Connection 类单元测试
 *
 * @internal
 */
#[CoversClass(Connection::class)]
final class ConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 测试设置
    }

    public function testCreateClientConnection(): void
    {
        $connection = new Connection(false);

        $this->assertFalse($connection->isServer());
        $this->assertEquals(ConnectionState::NEW, $connection->getStateMachine()->getState());
        $this->assertNotEmpty($connection->getLocalConnectionId());
    }

    public function testCreateServerConnection(): void
    {
        $connection = new Connection(true);

        $this->assertTrue($connection->isServer());
        $this->assertEquals(ConnectionState::NEW, $connection->getStateMachine()->getState());
    }

    public function testConnectionWithCustomId(): void
    {
        $customId = 'test-connection-id';
        $connection = new Connection(false, $customId);

        $this->assertEquals($customId, $connection->getLocalConnectionId());
    }

    public function testConnectChangesState(): void
    {
        $connection = new Connection(false);

        $connection->connect('127.0.0.1', 443);

        $this->assertEquals(ConnectionState::HANDSHAKING, $connection->getStateMachine()->getState());
    }

    public function testCloseConnection(): void
    {
        $connection = new Connection(false);

        $connection->close(0, 'test close');

        $this->assertEquals(ConnectionState::CLOSED, $connection->getStateMachine()->getState());

        $closeInfo = $connection->getStateMachine()->getCloseInfo();
        $this->assertEquals('test close', $closeInfo['reason']);
    }

    public function testCloseConnectionFromHandshaking(): void
    {
        $connection = new Connection(false);

        $connection->connect('127.0.0.1', 443);
        $this->assertEquals(ConnectionState::HANDSHAKING, $connection->getStateMachine()->getState());

        $connection->close(0, 'test close from handshaking');

        $this->assertEquals(ConnectionState::CLOSING, $connection->getStateMachine()->getState());

        $closeInfo = $connection->getStateMachine()->getCloseInfo();
        $this->assertEquals('test close from handshaking', $closeInfo['reason']);
    }

    public function testTransportParameters(): void
    {
        $connection = new Connection(false);

        $connection->setTransportParameter('test_param', 'test_value');

        $this->assertEquals('test_value', $connection->getTransportParameter('test_param'));
        $this->assertArrayHasKey('test_param', $connection->getTransportParameters());
    }

    public function testEventRegistrationAndTrigger(): void
    {
        $connection = new Connection(false);
        $eventTriggered = false;

        $connection->onEvent('test_event', function () use (&$eventTriggered): void {
            $eventTriggered = true;
        });

        $connection->triggerEvent('test_event');

        $this->assertTrue($eventTriggered);
    }

    public function testPathManagerInitialization(): void
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

    public function testIdleTimeoutManager(): void
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

    public function testSendFrameWhenNotConnected(): void
    {
        $connection = new Connection(false);

        // 创建一个测试用的 Frame 实现
        // 这里使用匿名类来实现抽象 Frame 类，因为：
        // 1. PHPStan 规则限制了对抽象类使用 createMock
        // 2. 测试只需要验证连接状态检查，不需要复杂的 Frame 实现
        // 3. 使用匿名类可以提供最小化的实现，专注于测试目标
        $frame = new class extends Frame {
            public function getType(): FrameType
            {
                return FrameType::PADDING;
            }

            public function encode(): string
            {
                return '';
            }

            public static function decode(string $data, int $offset = 0): array
            {
                // 测试用的简单实现
                return [new self(), 1];
            }

            public function validate(): bool
            {
                return true;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('连接状态不允许发送数据');

        $connection->sendFrame($frame);
    }

    public function testProcessPendingTasks(): void
    {
        $connection = new Connection(false);

        $initialState = $connection->getStateMachine()->getState();

        // 调用processPendingTasks不应该抛出异常
        $connection->processPendingTasks();

        // 验证连接状态没有意外改变
        $this->assertEquals($initialState, $connection->getStateMachine()->getState());

        // 验证连接仍然可用
        $this->assertNotNull($connection->getLocalConnectionId());
    }

    public function testMultipleEventHandlers(): void
    {
        $connection = new Connection(false);
        $callCount = 0;

        // 注册多个事件处理器
        $connection->onEvent('test_event', function () use (&$callCount): void {
            ++$callCount;
        });

        $connection->onEvent('test_event', function () use (&$callCount): void {
            ++$callCount;
        });

        $connection->triggerEvent('test_event');

        $this->assertEquals(2, $callCount);
    }

    public function testConnectionIdGeneration(): void
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

    public function testInvalidStateTransition(): void
    {
        $connection = new Connection(false);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('无效状态转换');

        // 尝试从NEW直接转到CONNECTED，这是无效的
        $connection->getStateMachine()->transitionTo(ConnectionState::CONNECTED);
    }

    public function testHandlePacket(): void
    {
        $connection = new Connection(false);

        // 创建一个测试用的包
        $packet = new class(PacketType::ONE_RTT, 1, 'test-payload') extends Packet {
            public function encode(): string
            {
                return '';
            }

            public static function decode(string $data): static
            {
                return new self(PacketType::ONE_RTT, 1, 'test-payload');
            }

            public function validate(): bool
            {
                return true;
            }

            public function getSourceConnectionId(): string
            {
                return 'test-source-id';
            }
        };

        // 应该能正确处理包而不抛出异常
        $connection->handlePacket($packet, '127.0.0.1', 443);

        $this->assertInstanceOf(Connection::class, $connection);
    }

    public function testOnEvent(): void
    {
        $connection = new Connection(false);
        $callbackData = null;

        $connection->onEvent('test_event', function (Connection $conn, array $data) use (&$callbackData): void {
            $callbackData = $data;
        });

        $testData = ['key' => 'value'];
        $connection->triggerEvent('test_event', $testData);

        $this->assertEquals($testData, $callbackData);
    }

    public function testSendData(): void
    {
        $connection = new Connection(false);

        // 先连接并转换到可发送数据的状态
        $connection->connect('127.0.0.1', 443);
        $connection->getStateMachine()->transitionTo(ConnectionState::CONNECTED);

        $testData = 'Hello QUIC';
        $bytesSent = $connection->sendData($testData);

        $this->assertEquals(strlen($testData), $bytesSent);
    }

    public function testTriggerEvent(): void
    {
        $connection = new Connection(false);
        $eventTriggered = false;
        $receivedData = null;

        $connection->onEvent('custom_event', function (Connection $conn, array $data) use (&$eventTriggered, &$receivedData): void {
            $eventTriggered = true;
            $receivedData = $data;
        });

        $testData = ['message' => 'test'];
        $connection->triggerEvent('custom_event', $testData);

        $this->assertTrue($eventTriggered);
        $this->assertEquals($testData, $receivedData);
    }
}
