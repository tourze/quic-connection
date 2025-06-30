<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Tourze\QUIC\Core\Constants;

/**
 * QUIC连接空闲超时管理器
 *
 * 管理连接的空闲超时检测和处理
 * 参考：RFC 9000 Section 10.1
 */
class IdleTimeoutManager
{
    private readonly ConnectionStateMachine $stateMachine;
    private ?Connection $connection = null;
    
    /**
     * 空闲超时时间（毫秒）
     */
    private int $idleTimeout = Constants::DEFAULT_IDLE_TIMEOUT;
    
    /**
     * 最后活动时间（毫秒时间戳）
     */
    private int $lastActivityTime;
    
    /**
     * 是否启用PING探活
     */
    private bool $pingEnabled = true;
    
    /**
     * PING间隔时间（毫秒）
     */
    private int $pingInterval;

    public function __construct(ConnectionStateMachine $stateMachine)
    {
        $this->stateMachine = $stateMachine;
        $this->lastActivityTime = (int)(microtime(true) * 1000);
        $this->pingInterval = (int)($this->idleTimeout * 0.5); // PING间隔为超时时间的一半
    }

    /**
     * 更新活动时间
     */
    public function updateActivity(): void
    {
        $this->lastActivityTime = (int)(microtime(true) * 1000);
    }

    /**
     * 检查是否空闲超时
     */
    public function checkTimeout(): bool
    {
        if ($this->stateMachine->getState()->isClosed()) {
            return false;
        }

        $now = (int)(microtime(true) * 1000);
        $idleTime = $now - $this->lastActivityTime;

        if ($idleTime >= $this->idleTimeout) {
            $this->handleTimeout();
            return true;
        }

        return false;
    }

    /**
     * 检查是否需要发送PING
     */
    public function shouldSendPing(): bool
    {
        if (!$this->pingEnabled || $this->stateMachine->getState()->isClosed()) {
            return false;
        }

        $now = (int)(microtime(true) * 1000);
        $idleTime = $now - $this->lastActivityTime;

        return $idleTime >= $this->pingInterval;
    }

    /**
     * 处理空闲超时
     */
    private function handleTimeout(): void
    {
        // 触发超时事件
        if ($this->connection !== null) {
            $this->connection->triggerEvent('timeout', ['reason' => 'idle timeout']);
        }
        
        $this->stateMachine->close(0, 'idle timeout');
    }
    
    /**
     * 设置关联的连接对象
     */
    public function setConnection(Connection $connection): void
    {
        $this->connection = $connection;
    }

    /**
     * 设置空闲超时时间
     */
    public function setIdleTimeout(int $timeoutMs): void
    {
        $this->idleTimeout = max(0, $timeoutMs);
        $this->pingInterval = (int)($this->idleTimeout * 0.5);
    }

    /**
     * 获取空闲超时时间
     */
    public function getIdleTimeout(): int
    {
        return $this->idleTimeout;
    }

    /**
     * 延长空闲超时
     */
    public function extendTimeout(int $extensionMs): void
    {
        $this->updateActivity();
        $this->idleTimeout += $extensionMs;
        $this->pingInterval = (int)($this->idleTimeout * 0.5);
    }

    /**
     * 启用/禁用PING探活
     */
    public function setPingEnabled(bool $enabled): void
    {
        $this->pingEnabled = $enabled;
    }

    /**
     * 获取距离超时的剩余时间（毫秒）
     */
    public function getTimeToTimeout(): int
    {
        $now = (int)(microtime(true) * 1000);
        $idleTime = $now - $this->lastActivityTime;
        return max(0, $this->idleTimeout - $idleTime);
    }

    /**
     * 获取距离下次PING的剩余时间（毫秒）
     */
    public function getTimeToPing(): int
    {
        if (!$this->pingEnabled) {
            return PHP_INT_MAX;
        }

        $now = (int)(microtime(true) * 1000);
        $idleTime = $now - $this->lastActivityTime;
        return max(0, $this->pingInterval - $idleTime);
    }

    /**
     * 重置活动时间
     */
    public function reset(): void
    {
        $this->lastActivityTime = (int)(microtime(true) * 1000);
    }
} 