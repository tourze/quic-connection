<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Enum;

use Tourze\Arrayable\Arrayable;
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
 *
 * @implements Arrayable<string, mixed>
 */
enum PathState: string implements Labelable, Itemable, Selectable, Arrayable
{
    use ItemTrait;
    use SelectTrait;
    case UNVALIDATED = 'unvalidated';
    case PROBING = 'probing';
    case VALIDATED = 'validated';
    case FAILED = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::UNVALIDATED => '未验证',
            self::PROBING => '探测中',
            self::VALIDATED => '已验证',
            self::FAILED => '失败',
        };
    }

    /**
     * 获取所有枚举的选项数组（用于下拉列表等）
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function toSelectItems(): array
    {
        $result = [];
        foreach (self::cases() as $case) {
            $result[] = [
                'value' => $case->value,
                'label' => $case->getLabel(),
            ];
        }

        return $result;
    }

    /**
     * 判断路径是否可以发送数据
     *
     * 只有已验证的路径才可以发送数据
     */
    public function canSendData(): bool
    {
        return match ($this) {
            self::VALIDATED => true,
            self::UNVALIDATED, self::PROBING, self::FAILED => false,
        };
    }
}
