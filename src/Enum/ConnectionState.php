<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Enum;

use Tourze\EnumExtra\Itemable;
use Tourze\EnumExtra\ItemTrait;
use Tourze\EnumExtra\Labelable;
use Tourze\EnumExtra\Selectable;
use Tourze\EnumExtra\SelectTrait;

/**
 * QUIC连接状态枚举
 * 
 * 定义连接在其生命周期中的各种状态
 * 参考：RFC 9000 Section 4.1
 */
enum ConnectionState: string implements Itemable, Labelable, Selectable
{
    use ItemTrait;
    use SelectTrait;

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

    /**
     * 获取枚举值的标签
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::NEW => '新建',
            self::HANDSHAKING => '握手中',
            self::CONNECTED => '已连接',
            self::CLOSING => '关闭中',
            self::DRAINING => '排空中',
            self::CLOSED => '已关闭',
        };
    }
} 