<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

/**
 * QUIC连接监控器
 *
 * 监控连接状态、性能指标和健康状况
 */
class ConnectionMonitor
{
    /**
     * 连接性能统计
     * @var array<string, int|float>
     */
    private array $statistics = [
        'packets_sent' => 0,
        'packets_received' => 0,
        'bytes_sent' => 0,
        'bytes_received' => 0,
        'connection_time' => 0,
        'state_changes' => 0,
        'errors' => 0,
    ];

    /**
     * 监控开始时间
     */
    private int $startTime;

    public function __construct(
        private readonly Connection $connection,
    ) {
        $this->startTime = time();

        // 注册监控事件
        $this->registerEventHandlers();
    }

    /**
     * 注册事件处理器
     */
    private function registerEventHandlers(): void
    {
        $this->connection->onEvent('connected', $this->onConnected(...));
        $this->connection->onEvent('disconnected', $this->onDisconnected(...));
        $this->connection->onEvent('error', $this->onError(...));
        $this->connection->onEvent('packet_sent', $this->onPacketSent(...));
        $this->connection->onEvent('packet_received', $this->onPacketReceived(...));
        $this->connection->onEvent('state_changed', $this->onStateChanged(...));
    }

    /**
     * 连接建立事件处理
     */
    public function onConnected(Connection $connection): void
    {
        $this->statistics['connection_time'] = time() - $this->startTime;
    }

    /**
     * 连接断开事件处理
     */
    public function onDisconnected(Connection $connection, int $errorCode, string $reason): void
    {
        // 记录断开信息
    }

    /**
     * 错误事件处理
     */
    public function onError(Connection $connection, \Throwable $error): void
    {
        ++$this->statistics['errors'];
    }

    /**
     * 包发送事件处理
     * @param array<string, mixed> $data
     */
    public function onPacketSent(Connection $connection, array $data): void
    {
        ++$this->statistics['packets_sent'];
        $size = $data['size'] ?? 0;
        $this->statistics['bytes_sent'] += is_numeric($size) ? (int) $size : 0;
    }

    /**
     * 包接收事件处理
     * @param array<string, mixed> $data
     */
    public function onPacketReceived(Connection $connection, array $data): void
    {
        ++$this->statistics['packets_received'];
        $size = $data['size'] ?? 0;
        $this->statistics['bytes_received'] += is_numeric($size) ? (int) $size : 0;
    }

    /**
     * 状态变化事件处理
     * @param array<string, mixed> $data
     */
    public function onStateChanged(Connection $connection, array $data): void
    {
        ++$this->statistics['state_changes'];
    }

    /**
     * 获取统计信息
     * @return array<string, int|float|string>
     */
    public function getStatistics(): array
    {
        $stats = $this->statistics;
        $stats['uptime'] = time() - $this->startTime;
        $stats['current_state'] = $this->connection->getStateMachine()->getState()->value;

        // 计算速率
        if ($stats['uptime'] > 0) {
            $stats['packets_per_second'] = (float) $stats['packets_sent'] / $stats['uptime'];
            $stats['bytes_per_second'] = (float) $stats['bytes_sent'] / $stats['uptime'];
        }

        return $stats;
    }

    /**
     * 重置统计信息
     */
    public function resetStatistics(): void
    {
        $this->statistics = [
            'packets_sent' => 0,
            'packets_received' => 0,
            'bytes_sent' => 0,
            'bytes_received' => 0,
            'connection_time' => 0,
            'state_changes' => 0,
            'errors' => 0,
        ];
        $this->startTime = time();
    }

    /**
     * 检查连接健康状态
     * @return array<string, mixed>
     */
    public function getHealthStatus(): array
    {
        $state = $this->connection->getStateMachine()->getState();
        $idleManager = $this->connection->getIdleTimeoutManager();

        return [
            'is_healthy' => $state->isActive() && 0 === $this->statistics['errors'],
            'state' => $state->value,
            'uptime' => time() - $this->startTime,
            'time_to_timeout' => $idleManager->getTimeToTimeout(),
            'error_count' => $this->statistics['errors'],
            'packet_loss_rate' => $this->calculatePacketLossRate(),
        ];
    }

    /**
     * 计算丢包率
     */
    private function calculatePacketLossRate(): float
    {
        $totalPackets = $this->statistics['packets_sent'] + $this->statistics['packets_received'];
        if (0 === $totalPackets) {
            return 0.0;
        }

        // 这里是简化的计算，实际应该基于ACK信息
        $lostPackets = max(0, $this->statistics['packets_sent'] - $this->statistics['packets_received']);

        return $lostPackets / $totalPackets;
    }
}
