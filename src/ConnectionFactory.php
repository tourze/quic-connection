<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Tourze\QUIC\Core\Constants;

/**
 * QUIC连接工厂
 *
 * 负责创建和配置QUIC连接实例
 */
class ConnectionFactory
{
    /**
     * 默认传输参数
     */
    private array $defaultTransportParameters;
    
    /**
     * 默认事件处理器
     */
    private array $defaultEventHandlers = [];

    public function __construct(array $defaultTransportParameters = [])
    {
        $this->defaultTransportParameters = array_merge(
            Constants::getDefaultTransportParameters(),
            $defaultTransportParameters
        );
    }

    /**
     * 创建客户端连接
     */
    public function createClientConnection(?string $connectionId = null): Connection
    {
        $connection = new Connection(false, $connectionId);
        $this->configureConnection($connection);
        return $connection;
    }

    /**
     * 创建服务端连接
     */
    public function createServerConnection(?string $connectionId = null): Connection
    {
        $connection = new Connection(true, $connectionId);
        $this->configureConnection($connection);
        return $connection;
    }

    /**
     * 配置连接
     */
    private function configureConnection(Connection $connection): void
    {
        // 设置传输参数
        foreach ($this->defaultTransportParameters as $key => $value) {
            $connection->setTransportParameter($key, $value);
        }

        // 注册默认事件处理器
        foreach ($this->defaultEventHandlers as $event => $handlers) {
            foreach ($handlers as $handler) {
                $connection->onEvent($event, $handler);
            }
        }
    }

    /**
     * 设置默认传输参数
     */
    public function setDefaultTransportParameter(string $key, mixed $value): void
    {
        $this->defaultTransportParameters[$key] = $value;
    }

    /**
     * 添加默认事件处理器
     */
    public function addDefaultEventHandler(string $event, callable $handler): void
    {
        $this->defaultEventHandlers[$event][] = $handler;
    }

    /**
     * 设置空闲超时时间
     */
    public function setIdleTimeout(int $timeoutMs): void
    {
        $this->setDefaultTransportParameter('max_idle_timeout', $timeoutMs);
    }

    /**
     * 设置最大数据量
     */
    public function setMaxData(int $maxData): void
    {
        $this->setDefaultTransportParameter('initial_max_data', $maxData);
    }

    /**
     * 设置最大流数据量
     */
    public function setMaxStreamData(int $maxStreamData): void
    {
        $this->setDefaultTransportParameter('initial_max_stream_data_bidi_local', $maxStreamData);
        $this->setDefaultTransportParameter('initial_max_stream_data_bidi_remote', $maxStreamData);
        $this->setDefaultTransportParameter('initial_max_stream_data_uni', $maxStreamData);
    }

    /**
     * 设置最大双向流数量
     */
    public function setMaxBidiStreams(int $maxStreams): void
    {
        $this->setDefaultTransportParameter('initial_max_streams_bidi', $maxStreams);
    }

    /**
     * 设置最大单向流数量
     */
    public function setMaxUniStreams(int $maxStreams): void
    {
        $this->setDefaultTransportParameter('initial_max_streams_uni', $maxStreams);
    }
} 