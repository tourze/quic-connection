<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Enum;

/**
 * QUIC路径状态枚举
 * 
 * 定义连接路径的验证状态
 * 参考：RFC 9000 Section 8.2
 */
enum PathState: string
{
    case UNVALIDATED = 'unvalidated';
    case PROBING = 'probing';
    case VALIDATED = 'validated';
    case FAILED = 'failed';

    /**
     * 判断路径是否可用于发送数据
     */
    public function canSendData(): bool
    {
        return $this === self::VALIDATED;
    }

    /**
     * 判断路径是否处于验证过程中
     */
    public function isValidating(): bool
    {
        return $this === self::PROBING;
    }

    /**
     * 判断路径是否失效
     */
    public function isFailed(): bool
    {
        return $this === self::FAILED;
    }
} 