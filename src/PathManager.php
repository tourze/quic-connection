<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Tourze\QUIC\Connection\Enum\PathState;
use Tourze\QUIC\Core\Constants;

/**
 * QUIC路径管理器
 * 
 * 管理连接路径的验证、迁移和切换
 * 参考：RFC 9000 Section 8 & 9
 */
class PathManager
{
    /**
     * 路径验证挑战数据
     */
    private ?string $validationChallenge = null;
    
    /**
     * 路径验证超时时间
     */
    private ?int $validationTimeout = null;
    
    /**
     * 当前活跃路径
     */
    private ?array $activePath = null;
    
    /**
     * 正在探测的路径
     */
    private array $probingPaths = [];
    
    /**
     * 已验证的路径
     */
    private array $validatedPaths = [];
    
    /**
     * 首选地址
     */
    private ?array $preferredAddress = null;
    
    /**
     * 是否为服务端
     */
    private readonly bool $isServer;

    public function __construct(bool $isServer = false)
    {
        $this->isServer = $isServer;
    }

    /**
     * 初始化路径（第一个连接路径）
     */
    public function initializePath(string $localAddress, int $localPort, string $remoteAddress, int $remotePort): void
    {
        $this->activePath = [
            'local_address' => $localAddress,
            'local_port' => $localPort,
            'remote_address' => $remoteAddress,
            'remote_port' => $remotePort,
            'state' => PathState::VALIDATED,
            'rtt' => null,
            'validated_at' => time(),
        ];
    }

    /**
     * 开始探测新路径
     */
    public function probePath(string $localAddress, int $localPort, string $remoteAddress, int $remotePort): void
    {
        $pathKey = $this->getPathKey($localAddress, $localPort, $remoteAddress, $remotePort);
        
        // 检查是否已在探测
        if (isset($this->probingPaths[$pathKey])) {
            return;
        }

        $path = [
            'local_address' => $localAddress,
            'local_port' => $localPort,
            'remote_address' => $remoteAddress,
            'remote_port' => $remotePort,
            'state' => PathState::PROBING,
            'probe_start' => time(),
        ];

        $this->probingPaths[$pathKey] = $path;
        $this->initiatePathValidation($pathKey);
    }

    /**
     * 发起路径验证
     */
    private function initiatePathValidation(string $pathKey): void
    {
        $this->validationChallenge = random_bytes(Constants::PATH_CHALLENGE_SIZE);
        $this->validationTimeout = time() + 5; // 5秒超时

        // TODO: 发送PATH_CHALLENGE帧
        // $frame = new PathChallengeFrame($this->validationChallenge);
        // $this->connection->sendFrame($frame, $pathKey);
    }

    /**
     * 处理PATH_CHALLENGE帧
     */
    public function handlePathChallenge(string $challengeData, string $sourcePath): void
    {
        // 回复PATH_RESPONSE帧
        // TODO: 发送PATH_RESPONSE帧
        // $frame = new PathResponseFrame($challengeData);
        // $this->connection->sendFrame($frame, $sourcePath);
    }

    /**
     * 处理PATH_RESPONSE帧
     */
    public function handlePathResponse(string $responseData): bool
    {
        // 验证响应数据
        if ($responseData !== $this->validationChallenge) {
            return false;
        }

        // 检查超时
        if (time() > $this->validationTimeout) {
            return false;
        }

        // 找到对应的探测路径并标记为已验证
        foreach ($this->probingPaths as $pathKey => $path) {
            if ($path['state'] === PathState::PROBING) {
                $path['state'] = PathState::VALIDATED;
                $path['validated_at'] = time();
                
                // 移到已验证路径
                $this->validatedPaths[$pathKey] = $path;
                unset($this->probingPaths[$pathKey]);
                
                // 如果是首选地址路径，切换到此路径
                if ($this->isPreferredAddressPath($path)) {
                    $this->switchToPath($pathKey, $path);
                }
                
                break;
            }
        }

        // 重置验证状态
        $this->validationChallenge = null;
        $this->validationTimeout = null;

        return true;
    }

    /**
     * 切换到新路径
     */
    public function switchToPath(string $pathKey, array $path): void
    {
        // 将当前活跃路径移到已验证路径
        if ($this->activePath !== null) {
            $oldPathKey = $this->getPathKey(
                $this->activePath['local_address'],
                $this->activePath['local_port'],
                $this->activePath['remote_address'],
                $this->activePath['remote_port']
            );
            $this->validatedPaths[$oldPathKey] = $this->activePath;
        }

        // 设置新的活跃路径
        $this->activePath = $path;
        
        // 从已验证路径中移除
        unset($this->validatedPaths[$pathKey]);

        // TODO: 重置拥塞控制
        // $this->connection->resetCongestionControl();
        
        // TODO: 发送NEW_CONNECTION_ID帧
        // $this->connection->sendNewConnectionId();
    }

    /**
     * 设置首选地址（仅服务端）
     */
    public function setPreferredAddress(string $address, int $port): void
    {
        if (!$this->isServer) {
            throw new \InvalidArgumentException('只有服务端可以设置首选地址');
        }

        $this->preferredAddress = [
            'address' => $address,
            'port' => $port,
        ];

        // TODO: 发送首选地址传输参数
        // $this->sendPreferredAddressParameter();
    }

    /**
     * 判断是否为首选地址路径
     */
    private function isPreferredAddressPath(array $path): bool
    {
        if ($this->preferredAddress === null) {
            return false;
        }

        return $path['remote_address'] === $this->preferredAddress['address'] &&
               $path['remote_port'] === $this->preferredAddress['port'];
    }

    /**
     * 清理超时的探测路径
     */
    public function cleanupTimeoutPaths(): void
    {
        $now = time();
        
        foreach ($this->probingPaths as $pathKey => $path) {
            if ($now - $path['probe_start'] > 30) { // 30秒超时
                $path['state'] = PathState::FAILED;
                unset($this->probingPaths[$pathKey]);
            }
        }

        // 清理验证超时
        if ($this->validationTimeout !== null && $now > $this->validationTimeout) {
            $this->validationChallenge = null;
            $this->validationTimeout = null;
        }
    }

    /**
     * 获取路径标识键
     */
    private function getPathKey(string $localAddress, int $localPort, string $remoteAddress, int $remotePort): string
    {
        return sprintf('%s:%d-%s:%d', $localAddress, $localPort, $remoteAddress, $remotePort);
    }

    /**
     * 获取当前活跃路径
     */
    public function getActivePath(): ?array
    {
        return $this->activePath;
    }

    /**
     * 获取所有已验证路径
     */
    public function getValidatedPaths(): array
    {
        return $this->validatedPaths;
    }

    /**
     * 获取正在探测的路径
     */
    public function getProbingPaths(): array
    {
        return $this->probingPaths;
    }

    /**
     * 获取首选地址
     */
    public function getPreferredAddress(): ?array
    {
        return $this->preferredAddress;
    }
} 