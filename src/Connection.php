<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Tourze\QUIC\Connection\Enum\ConnectionState;
use Tourze\QUIC\Core\Constants;
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
     * 是否为服务端
     */
    private readonly bool $isServer;
    
    /**
     * 传输参数
     */
    private array $transportParameters;
    
    /**
     * 待发送的帧队列
     */
    private array $pendingFrames = [];
    
    /**
     * 连接事件回调
     */
    private array $eventCallbacks = [];

    public function __construct(bool $isServer = false, ?string $localConnectionId = null)
    {
        $this->isServer = $isServer;
        $this->localConnectionId = $localConnectionId ?? $this->generateConnectionId();
        
        $this->stateMachine = new ConnectionStateMachine($this);
        $this->pathManager = new PathManager($isServer);
        $this->idleTimeoutManager = new IdleTimeoutManager($this->stateMachine);
        
        $this->transportParameters = Constants::getDefaultTransportParameters();
    }

    /**
     * 建立连接
     */
    public function connect(string $remoteAddress, int $remotePort, string $localAddress = '0.0.0.0', int $localPort = 0): void
    {
        if ($this->stateMachine->getState() !== ConnectionState::NEW) {
            throw new \RuntimeException('连接状态错误');
        }

        $this->pathManager->initializePath($localAddress, $localPort, $remoteAddress, $remotePort);
        $this->stateMachine->transitionTo(ConnectionState::HANDSHAKING);
    }

    /**
     * 处理接收到的包
     */
    public function handlePacket(Packet $packet, string $sourceAddress, int $sourcePort): void
    {
        // 更新活动时间
        $this->idleTimeoutManager->updateActivity();
        
        // TODO: 更新远程连接ID
        // if ($this->remoteConnectionId === null) {
        //     $this->remoteConnectionId = $packet->getSourceConnectionId();
        // }

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
            throw new \RuntimeException('连接状态不允许发送数据');
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
        if (empty($this->pendingFrames)) {
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
     */
    public function triggerEvent(string $event, array $data = []): void
    {
        if (isset($this->eventCallbacks[$event])) {
            foreach ($this->eventCallbacks[$event] as $callback) {
                $callback($this, $data);
            }
        }
    }
} 