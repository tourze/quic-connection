<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Tourze\QUIC\Connection\Exception\QuicConnectionException;
use Tourze\QUIC\Core\Constants;
use Tourze\QUIC\Core\Enum\ConnectionState;
use Tourze\QUIC\Frames\Frame;
use Tourze\QUIC\Packets\Packet;

/**
 * QUIC连接主类
 *
 * 整合连接状态管理、路径管理、空闲超时等功能
 * 参考：RFC 9000
 */
class Connection
{
    private readonly ConnectionStateMachine $stateMachine;

    private readonly PathManager $pathManager;

    private readonly IdleTimeoutManager $idleTimeoutManager;

    /**
     * 本地连接ID
     */
    private readonly string $localConnectionId;

    /**
     * 远程连接ID
     */
    private ?string $remoteConnectionId = null;

    /**
     * 传输参数
     * @var array<string, mixed>
     */
    private array $transportParameters;

    /**
     * 待发送的帧队列
     * @var array<int, Frame>
     */
    private array $pendingFrames = [];

    /**
     * 连接事件回调
     * @var array<string, array<int, callable>>
     */
    private array $eventCallbacks = [];

    public function __construct(
        private readonly bool $isServer = false,
        ?string $localConnectionId = null,
    ) {
        $this->localConnectionId = $localConnectionId ?? $this->generateConnectionId();

        $this->stateMachine = new ConnectionStateMachine($this);
        $this->pathManager = new PathManager($isServer);
        $this->idleTimeoutManager = new IdleTimeoutManager($this->stateMachine);
        $this->idleTimeoutManager->setConnection($this);

        /** @var array<string, int> $defaultParams */
        $defaultParams = Constants::getDefaultTransportParameters();
        $this->transportParameters = $defaultParams;
    }

    /**
     * 建立连接
     */
    public function connect(string $remoteAddress, int $remotePort, string $localAddress = '0.0.0.0', int $localPort = 0): bool
    {
        if (ConnectionState::NEW !== $this->stateMachine->getState()) {
            throw new QuicConnectionException('连接状态错误');
        }

        try {
            $this->pathManager->initializePath($localAddress, $localPort, $remoteAddress, $remotePort);
            $this->stateMachine->transitionTo(ConnectionState::HANDSHAKING);
            $this->triggerEvent('connecting', ['remote_address' => $remoteAddress, 'remote_port' => $remotePort]);

            return true;
        } catch (\Exception $e) {
            $this->triggerEvent('error', ['exception' => $e]);

            return false;
        }
    }

    /**
     * 处理接收到的包
     */
    public function handlePacket(Packet $packet, string $sourceAddress, int $sourcePort): void
    {
        // 更新活动时间
        $this->idleTimeoutManager->updateActivity();

        // 更新远程连接ID
        if (null === $this->remoteConnectionId && method_exists($packet, 'getSourceConnectionId')) {
            $sourceId = $packet->getSourceConnectionId();
            $this->remoteConnectionId = is_string($sourceId) ? $sourceId : null;
        }

        // 处理包中的帧
        $frames = $this->extractFrames($packet);
        foreach ($frames as $frame) {
            $this->handleFrame($frame, $sourceAddress, $sourcePort);
        }
    }

    /**
     * 处理帧
     */
    private function handleFrame(Frame $frame, string $sourceAddress, int $sourcePort): void
    {
        // TODO: 根据帧类型进行处理
        // 这里需要根据具体的帧类型实现相应的处理逻辑
    }

    /**
     * 从包中提取帧
     * @return array<int, Frame>
     */
    private function extractFrames(Packet $packet): array
    {
        // TODO: 实现帧解析逻辑
        return [];
    }

    /**
     * 发送帧
     */
    public function sendFrame(Frame $frame): void
    {
        if (!$this->stateMachine->canSendData()) {
            throw new QuicConnectionException('连接状态不允许发送数据');
        }

        $this->pendingFrames[] = $frame;
        $this->idleTimeoutManager->updateActivity();
    }

    /**
     * 关闭连接
     */
    public function close(int $errorCode = 0, string $reason = ''): void
    {
        $this->stateMachine->close($errorCode, $reason);
    }

    /**
     * 处理定期任务
     */
    public function processPendingTasks(): void
    {
        // 检查空闲超时
        $this->idleTimeoutManager->checkTimeout();

        // 清理路径超时
        $this->pathManager->cleanupTimeoutPaths();

        // 发送PING（如果需要）
        if ($this->idleTimeoutManager->shouldSendPing()) {
            $this->sendPing();
        }

        // 处理待发送的帧
        $this->flushPendingFrames();
    }

    /**
     * 发送PING帧
     */
    private function sendPing(): void
    {
        // TODO: 创建并发送PING帧
        // $pingFrame = new PingFrame();
        // $this->sendFrame($pingFrame);

        $this->idleTimeoutManager->updateActivity();
    }

    /**
     * 刷新待发送的帧
     */
    private function flushPendingFrames(): void
    {
        if ([] === $this->pendingFrames) {
            return;
        }

        // TODO: 将帧打包成包并发送
        // $packet = $this->buildPacket($this->pendingFrames);
        // $this->sendPacket($packet);

        $this->pendingFrames = [];
    }

    /**
     * 生成连接ID
     */
    private function generateConnectionId(): string
    {
        return random_bytes(Constants::DEFAULT_CONNECTION_ID_LENGTH);
    }

    /**
     * 获取本地连接ID
     */
    public function getLocalConnectionId(): string
    {
        return $this->localConnectionId;
    }

    /**
     * 获取远程连接ID
     */
    public function getRemoteConnectionId(): ?string
    {
        return $this->remoteConnectionId;
    }

    /**
     * 判断是否为服务端
     */
    public function isServer(): bool
    {
        return $this->isServer;
    }

    /**
     * 获取状态机
     */
    public function getStateMachine(): ConnectionStateMachine
    {
        return $this->stateMachine;
    }

    /**
     * 获取路径管理器
     */
    public function getPathManager(): PathManager
    {
        return $this->pathManager;
    }

    /**
     * 获取空闲超时管理器
     */
    public function getIdleTimeoutManager(): IdleTimeoutManager
    {
        return $this->idleTimeoutManager;
    }

    /**
     * 设置传输参数
     */
    public function setTransportParameter(string $key, mixed $value): void
    {
        $this->transportParameters[$key] = $value;
    }

    /**
     * 获取传输参数
     */
    public function getTransportParameter(string $key): mixed
    {
        return $this->transportParameters[$key] ?? null;
    }

    /**
     * 获取所有传输参数
     * @return array<string, mixed>
     */
    public function getTransportParameters(): array
    {
        return $this->transportParameters;
    }

    /**
     * 注册事件回调
     */
    public function onEvent(string $event, callable $callback): void
    {
        $this->eventCallbacks[$event][] = $callback;
    }

    /**
     * 触发事件
     * @param array<string, mixed> $data
     */
    public function triggerEvent(string $event, array $data = []): void
    {
        if (isset($this->eventCallbacks[$event])) {
            foreach ($this->eventCallbacks[$event] as $callback) {
                $callback($this, $data);
            }
        }
    }

    /**
     * 发送数据
     */
    public function sendData(string $data): int
    {
        if (!$this->stateMachine->canSendData()) {
            throw new QuicConnectionException('连接状态不允许发送数据');
        }

        // TODO: 实现实际的数据发送逻辑
        // 这里需要将数据封装成流帧并发送

        $this->triggerEvent('data_sent', ['bytes' => strlen($data)]);
        $this->idleTimeoutManager->updateActivity();

        return strlen($data);
    }

    /**
     * 设置QUIC版本
     */
    public function setVersion(int $version): void
    {
        $this->transportParameters['initial_version'] = $version;
    }

    /**
     * 设置初始版本
     */
    public function setInitialVersion(int $version): void
    {
        $this->setVersion($version);
    }

    /**
     * 设置初始最大数据量
     */
    public function setInitialMaxData(int $maxData): void
    {
        $this->transportParameters['initial_max_data'] = $maxData;
    }

    /**
     * 设置初始最大流数据量
     */
    public function setInitialMaxStreamData(int $maxStreamData): void
    {
        $this->transportParameters['initial_max_stream_data_bidi_local'] = $maxStreamData;
        $this->transportParameters['initial_max_stream_data_bidi_remote'] = $maxStreamData;
        $this->transportParameters['initial_max_stream_data_uni'] = $maxStreamData;
    }

    /**
     * 获取连接监控器
     */
    public function getMonitor(): ConnectionMonitor
    {
        if (!isset($this->monitor)) {
            $this->monitor = new ConnectionMonitor($this);
        }

        return $this->monitor;
    }

    private ?ConnectionMonitor $monitor = null;
}
