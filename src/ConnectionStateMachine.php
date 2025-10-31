<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Tourze\QUIC\Connection\Exception\InvalidConnectionStateException;
use Tourze\QUIC\Core\Enum\ConnectionState;

/**
 * QUIC连接状态机
 *
 * 管理连接状态转换和生命周期
 * 参考：RFC 9000 Section 4
 */
class ConnectionStateMachine
{
    private ConnectionState $state = ConnectionState::NEW;

    /**
     * 关闭状态信息
     * @var array<string, mixed>
     */
    private array $closeInfo = [
        'errorCode' => 0,
        'frameType' => null,
        'reason' => '',
        'timestamp' => null,
    ];

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * 获取当前状态
     */
    public function getState(): ConnectionState
    {
        return $this->state;
    }

    /**
     * 转换到新状态
     */
    public function transitionTo(ConnectionState $newState): void
    {
        if (!$this->isValidTransition($this->state, $newState)) {
            throw new InvalidConnectionStateException(sprintf('无效状态转换：%s -> %s', $this->state->value, $newState->value));
        }

        $this->onStateTransition($this->state, $newState);
        $this->state = $newState;
    }

    /**
     * 判断状态转换是否有效
     */
    private function isValidTransition(ConnectionState $from, ConnectionState $to): bool
    {
        return match ([$from, $to]) {
            [ConnectionState::NEW, ConnectionState::HANDSHAKING] => true,
            [ConnectionState::NEW, ConnectionState::CLOSED] => true,
            [ConnectionState::HANDSHAKING, ConnectionState::CONNECTED] => true,
            [ConnectionState::HANDSHAKING, ConnectionState::CLOSING] => true,
            [ConnectionState::HANDSHAKING, ConnectionState::DRAINING] => true,
            [ConnectionState::CONNECTED, ConnectionState::CLOSING] => true,
            [ConnectionState::CONNECTED, ConnectionState::DRAINING] => true,
            [ConnectionState::CLOSING, ConnectionState::DRAINING] => true,
            [ConnectionState::CLOSING, ConnectionState::CLOSED] => true,
            [ConnectionState::DRAINING, ConnectionState::CLOSED] => true,
            default => false,
        };
    }

    /**
     * 状态转换时的处理
     */
    private function onStateTransition(ConnectionState $from, ConnectionState $to): void
    {
        match ($to) {
            ConnectionState::HANDSHAKING => $this->onHandshaking(),
            ConnectionState::CONNECTED => $this->onConnected(),
            ConnectionState::CLOSING => $this->onClosing(),
            ConnectionState::DRAINING => $this->onDraining(),
            ConnectionState::CLOSED => $this->onClosed(),
            default => null,
        };
    }

    /**
     * 开始握手处理
     */
    private function onHandshaking(): void
    {
        // 开始握手超时计时
        // 可以在这里初始化握手相关的资源
    }

    /**
     * 连接建立处理
     */
    private function onConnected(): void
    {
        // 连接建立成功，可以开始传输应用数据
        // 触发连接建立事件
        $this->connection->triggerEvent('connected');
    }

    /**
     * 开始关闭处理
     */
    private function onClosing(): void
    {
        // 发送CONNECTION_CLOSE帧
        $this->sendConnectionClose();

        // 在实际实现中，应该等待确认或超时后再转换到CLOSED状态
        // 这里不自动转换，让调用者决定何时转换到CLOSED
    }

    /**
     * 开始排空处理
     */
    private function onDraining(): void
    {
        // 停止发送数据，仅接收数据
        // 启动排空超时
    }

    /**
     * 连接关闭处理
     */
    private function onClosed(): void
    {
        // 清理所有资源
        // 触发连接关闭事件
        $this->connection->triggerEvent('closed', $this->closeInfo);
        $this->connection->triggerEvent('disconnected', [
            'errorCode' => $this->closeInfo['errorCode'],
            'reason' => $this->closeInfo['reason'],
        ]);
    }

    /**
     * 关闭连接
     */
    public function close(int $errorCode = 0, string $reason = '', ?int $frameType = null): void
    {
        if ($this->state->isClosed()) {
            return;
        }

        $this->closeInfo = [
            'errorCode' => $errorCode,
            'frameType' => $frameType,
            'reason' => $reason,
            'timestamp' => time(),
        ];

        if (ConnectionState::NEW === $this->state) {
            $this->transitionTo(ConnectionState::CLOSED);
        } elseif ($this->state->isActive()) {
            $this->transitionTo(ConnectionState::CLOSING);
        }
    }

    /**
     * 立即关闭连接（收到CONNECTION_CLOSE时）
     */
    public function immediateClose(int $errorCode, string $reason = '', ?int $frameType = null): void
    {
        $this->closeInfo = [
            'errorCode' => $errorCode,
            'frameType' => $frameType,
            'reason' => $reason,
            'timestamp' => time(),
        ];

        if ($this->state->isActive()) {
            $this->transitionTo(ConnectionState::DRAINING);
        }
    }

    /**
     * 发送CONNECTION_CLOSE帧
     */
    private function sendConnectionClose(): void
    {
        // 编码CONNECTION_CLOSE帧数据
        $frameData = pack('N', $this->closeInfo['errorCode']);

        if (null !== $this->closeInfo['frameType']) {
            $frameData .= pack('N', $this->closeInfo['frameType']);
        }

        $reason = $this->closeInfo['reason'];
        $reasonStr = is_string($reason) ? $reason : '';
        $frameData .= pack('n', strlen($reasonStr)) . $reasonStr;

        // TODO: 发送CONNECTION_CLOSE帧
        // 当有具体的ConnectionCloseFrame类时，取消下面的注释
        // $frame = new ConnectionCloseFrame($frameData);
        // $this->connection->sendFrame($frame);

        // 暂时触发关闭事件，让外部处理
        $this->connection->triggerEvent('sendConnectionClose', ['frameData' => $frameData]);
    }

    /**
     * 获取关闭信息
     * @return array<string, mixed>
     */
    public function getCloseInfo(): array
    {
        return $this->closeInfo;
    }

    /**
     * 判断是否可以发送数据
     */
    public function canSendData(): bool
    {
        // 只有在CONNECTED状态才能发送应用数据
        return ConnectionState::CONNECTED === $this->state;
    }

    /**
     * 判断是否可以接收数据
     */
    public function canReceiveData(): bool
    {
        // 只有在HANDSHAKING和CONNECTED状态才能接收数据
        // CLOSING和DRAINING状态不应该接收新数据
        return in_array($this->state, [
            ConnectionState::HANDSHAKING,
            ConnectionState::CONNECTED,
        ], true);
    }
}
