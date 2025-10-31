<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

/**
 * QUIC连接事件接口
 *
 * 定义连接事件处理器的标准接口
 */
interface ConnectionEventInterface
{
    /**
     * 连接建立事件
     */
    public function onConnected(Connection $connection): void;

    /**
     * 连接关闭事件
     */
    public function onDisconnected(Connection $connection, int $errorCode, string $reason): void;

    /**
     * 连接错误事件
     */
    public function onError(Connection $connection, \Throwable $error): void;

    /**
     * 数据接收事件
     */
    public function onDataReceived(Connection $connection, string $data): void;

    /**
     * 路径切换事件
     * @param array<string, mixed> $oldPath
     * @param array<string, mixed> $newPath
     */
    public function onPathChanged(Connection $connection, array $oldPath, array $newPath): void;
}
