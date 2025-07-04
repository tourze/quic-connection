<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Connection;
use Tourze\QUIC\Connection\ConnectionStateMachine;
use Tourze\QUIC\Core\Enum\ConnectionState;

/**
 * ConnectionStateMachine 类单元测试
 */
class ConnectionStateMachineTest extends TestCase
{
    private ConnectionStateMachine $stateMachine;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = new Connection(false);
        $this->stateMachine = $this->connection->getStateMachine();
    }

    public function test_initial_state(): void
    {
        $this->assertEquals(ConnectionState::NEW, $this->stateMachine->getState());
    }

    public function test_valid_state_transitions(): void
    {
        // NEW -> HANDSHAKING
        $this->stateMachine->transitionTo(ConnectionState::HANDSHAKING);
        $this->assertEquals(ConnectionState::HANDSHAKING, $this->stateMachine->getState());

        // HANDSHAKING -> CONNECTED
        $this->stateMachine->transitionTo(ConnectionState::CONNECTED);
        $this->assertEquals(ConnectionState::CONNECTED, $this->stateMachine->getState());

        // CONNECTED -> CLOSING
        $this->stateMachine->transitionTo(ConnectionState::CLOSING);
        $this->assertEquals(ConnectionState::CLOSING, $this->stateMachine->getState());

        // CLOSING -> CLOSED
        $this->stateMachine->transitionTo(ConnectionState::CLOSED);
        $this->assertEquals(ConnectionState::CLOSED, $this->stateMachine->getState());
    }

    public function test_invalid_state_transitions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('无效状态转换');

        // 直接从NEW转到CONNECTED是无效的
        $this->stateMachine->transitionTo(ConnectionState::CONNECTED);
    }

    public function test_can_send_data_states(): void
    {
        // NEW状态不能发送数据
        $this->assertFalse($this->stateMachine->canSendData());

        // HANDSHAKING状态不能发送应用数据
        $this->stateMachine->transitionTo(ConnectionState::HANDSHAKING);
        $this->assertFalse($this->stateMachine->canSendData());

        // CONNECTED状态可以发送数据
        $this->stateMachine->transitionTo(ConnectionState::CONNECTED);
        $this->assertTrue($this->stateMachine->canSendData());

        // CLOSING状态不能发送数据
        $this->stateMachine->transitionTo(ConnectionState::CLOSING);
        $this->assertFalse($this->stateMachine->canSendData());
    }

    public function test_can_receive_data_states(): void
    {
        // NEW状态不能接收数据
        $this->assertFalse($this->stateMachine->canReceiveData());

        // HANDSHAKING状态可以接收握手数据
        $this->stateMachine->transitionTo(ConnectionState::HANDSHAKING);
        $this->assertTrue($this->stateMachine->canReceiveData());

        // CONNECTED状态可以接收数据
        $this->stateMachine->transitionTo(ConnectionState::CONNECTED);
        $this->assertTrue($this->stateMachine->canReceiveData());

        // CLOSING状态不能接收新数据
        $this->stateMachine->transitionTo(ConnectionState::CLOSING);
        $this->assertFalse($this->stateMachine->canReceiveData());
    }

    public function test_close_from_new_state(): void
    {
        $this->stateMachine->close(42, 'test reason');

        $this->assertEquals(ConnectionState::CLOSED, $this->stateMachine->getState());

        $closeInfo = $this->stateMachine->getCloseInfo();
        $this->assertEquals(42, $closeInfo['errorCode']);
        $this->assertEquals('test reason', $closeInfo['reason']);
        $this->assertIsInt($closeInfo['timestamp']);
    }

    public function test_close_from_handshaking_state(): void
    {
        $this->stateMachine->transitionTo(ConnectionState::HANDSHAKING);
        $this->stateMachine->close(0, 'handshake failed');

        $this->assertEquals(ConnectionState::CLOSING, $this->stateMachine->getState());

        $closeInfo = $this->stateMachine->getCloseInfo();
        $this->assertEquals(0, $closeInfo['errorCode']);
        $this->assertEquals('handshake failed', $closeInfo['reason']);
    }

    public function test_immediate_close(): void
    {
        // 正确的状态转换序列
        $this->stateMachine->transitionTo(ConnectionState::HANDSHAKING);
        $this->stateMachine->transitionTo(ConnectionState::CONNECTED);
        
        $this->stateMachine->immediateClose(100, 'connection error', 42);

        $this->assertEquals(ConnectionState::DRAINING, $this->stateMachine->getState());

        $closeInfo = $this->stateMachine->getCloseInfo();
        $this->assertEquals(100, $closeInfo['errorCode']);
        $this->assertEquals('connection error', $closeInfo['reason']);
        $this->assertEquals(42, $closeInfo['frameType']);
    }

    public function test_close_when_already_closed(): void
    {
        $this->stateMachine->close();
        $this->assertEquals(ConnectionState::CLOSED, $this->stateMachine->getState());

        // 再次关闭应该没有影响
        $this->stateMachine->close(123, 'another reason');
        $this->assertEquals(ConnectionState::CLOSED, $this->stateMachine->getState());

        // 关闭信息应该保持第一次的
        $closeInfo = $this->stateMachine->getCloseInfo();
        $this->assertEquals(0, $closeInfo['errorCode']);
    }

    public function test_draining_to_closed_transition(): void
    {
        $this->stateMachine->transitionTo(ConnectionState::HANDSHAKING);
        $this->stateMachine->immediateClose(0, 'test close');

        $this->assertEquals(ConnectionState::DRAINING, $this->stateMachine->getState());

        // DRAINING -> CLOSED
        $this->stateMachine->transitionTo(ConnectionState::CLOSED);
        $this->assertEquals(ConnectionState::CLOSED, $this->stateMachine->getState());
    }
} 