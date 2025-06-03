<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Enum;

/**
 * QUIC连接状态枚举
 * 
 * 定义连接在其生命周期中的各种状态
 * 参考：RFC 9000 Section 4.1
 */
enum ConnectionState: string
{
    case NEW = 'new';
    case HANDSHAKING = 'handshaking';
    case CONNECTED = 'connected';
    case CLOSING = 'closing';
    case DRAINING = 'draining';
    case CLOSED = 'closed';

    /**
     * 判断连接是否处于活跃状态
     */
    public function isActive(): bool
    {
        return in_array($this, [self::NEW, self::HANDSHAKING, self::CONNECTED]);
    }

    /**
     * 判断连接是否可以发送数据
     */
    public function canSendData(): bool
    {
        return $this === self::CONNECTED;
    }

    /**
     * 判断连接是否可以接收数据
     */
    public function canReceiveData(): bool
    {
        return in_array($this, [self::HANDSHAKING, self::CONNECTED]);
    }

    /**
     * 判断连接是否正在关闭
     */
    public function isClosing(): bool
    {
        return in_array($this, [self::CLOSING, self::DRAINING]);
    }

    /**
     * 判断连接是否已关闭
     */
    public function isClosed(): bool
    {
        return $this === self::CLOSED;
    }
} 