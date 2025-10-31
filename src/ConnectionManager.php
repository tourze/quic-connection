<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Tourze\QUIC\Connection\Exception\QuicConnectionException;

/**
 * QUIC连接管理器
 *
 * 管理多个QUIC连接的生命周期和调度
 * 参考：RFC 9000
 */
class ConnectionManager
{
    /**
     * 活跃连接列表
     * @var array<string, Connection>
     */
    private array $connections = [];

    /**
     * 连接ID到连接的映射
     * @var array<string, string>
     */
    private array $connectionIdMap = [];

    /**
     * 最大连接数
     */
    private int $maxConnections = 1000;

    /**
     * 连接计数器
     */
    private int $connectionCounter = 0;

    /**
     * 添加连接
     */
    public function addConnection(Connection $connection): void
    {
        if (count($this->connections) >= $this->maxConnections) {
            throw new QuicConnectionException('连接数已达上限');
        }

        $connectionId = $connection->getLocalConnectionId();
        $this->connections[$connectionId] = $connection;
        $this->connectionIdMap[$connectionId] = $connectionId;
        ++$this->connectionCounter;
    }

    /**
     * 移除连接
     */
    public function removeConnection(string $connectionId): void
    {
        if (isset($this->connections[$connectionId])) {
            unset($this->connections[$connectionId], $this->connectionIdMap[$connectionId]);
        }
    }

    /**
     * 根据连接ID获取连接
     */
    public function getConnection(string $connectionId): ?Connection
    {
        return $this->connections[$connectionId] ?? null;
    }

    /**
     * 获取所有连接
     * @return array<string, Connection>
     */
    public function getAllConnections(): array
    {
        return $this->connections;
    }

    /**
     * 获取活跃连接数
     */
    public function getConnectionCount(): int
    {
        return count($this->connections);
    }

    /**
     * 检查所有连接的超时
     */
    public function checkTimeouts(): void
    {
        foreach ($this->connections as $connectionId => $connection) {
            if ($connection->getIdleTimeoutManager()->checkTimeout()) {
                $this->removeConnection($connectionId);
            }
        }
    }

    /**
     * 处理所有连接的定期任务
     */
    public function processPendingTasks(): void
    {
        foreach ($this->connections as $connection) {
            $connection->processPendingTasks();
        }
    }

    /**
     * 清理已关闭的连接
     */
    public function cleanup(): void
    {
        foreach ($this->connections as $connectionId => $connection) {
            if ($connection->getStateMachine()->getState()->isClosed()) {
                $this->removeConnection($connectionId);
            }
        }
    }

    /**
     * 设置最大连接数
     */
    public function setMaxConnections(int $maxConnections): void
    {
        $this->maxConnections = max(1, $maxConnections);
    }

    /**
     * 获取最大连接数
     */
    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    /**
     * 获取连接统计信息
     * @return array<string, mixed>
     */
    public function getStatistics(): array
    {
        $stats = [
            'total_connections' => count($this->connections),
            'max_connections' => $this->maxConnections,
            'connection_counter' => $this->connectionCounter,
            'by_state' => [],
        ];

        foreach ($this->connections as $connection) {
            $state = $connection->getStateMachine()->getState()->value;
            $stats['by_state'][$state] = ($stats['by_state'][$state] ?? 0) + 1;
        }

        return $stats;
    }

    /**
     * 关闭所有连接
     */
    public function closeAllConnections(int $errorCode = 0, string $reason = 'shutdown'): void
    {
        foreach ($this->connections as $connection) {
            $connection->close($errorCode, $reason);
        }
    }
}
