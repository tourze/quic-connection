<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Enum;

use Tourze\EnumExtra\Itemable;
use Tourze\EnumExtra\ItemTrait;
use Tourze\EnumExtra\Labelable;
use Tourze\EnumExtra\Selectable;
use Tourze\EnumExtra\SelectTrait;

/**
 * QUIC路径状态枚举
 * 
 * 定义连接路径的验证状态
 * 参考：RFC 9000 Section 8.2
 */
enum PathState: string implements Itemable, Labelable, Selectable
{
    use ItemTrait;
    use SelectTrait;

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

    /**
     * 获取枚举值的标签
     */
    public function getLabel(): string
    {
        return match ($this) {
            self::UNVALIDATED => '未验证',
            self::PROBING => '探测中',
            self::VALIDATED => '已验证',
            self::FAILED => '已失效',
        };
    }
} 