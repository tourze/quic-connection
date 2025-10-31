<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Tourze\QUIC\Connection\Enum\PathState;
use Tourze\QUIC\Connection\Exception\InvalidConnectionStateException;
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
     * @var array<string, mixed>|null
     */
    private ?array $activePath = null;

    /**
     * 正在探测的路径
     * @var array<string, array<string, mixed>>
     */
    private array $probingPaths = [];

    /**
     * 已验证的路径
     * @var array<string, array<string, mixed>>
     */
    private array $validatedPaths = [];

    /**
     * 首选地址
     * @var array<string, mixed>|null
     */
    private ?array $preferredAddress = null;

    public function __construct(
        private readonly bool $isServer = false,
    ) {
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
            if (PathState::PROBING === $path['state']) {
                $validatedPath = $path;
                $validatedPath['state'] = PathState::VALIDATED;
                $validatedPath['validated_at'] = time();

                // 移到已验证路径
                $this->validatedPaths[$pathKey] = $validatedPath;
                unset($this->probingPaths[$pathKey]);

                // 如果是首选地址路径，切换到此路径
                if ($this->isPreferredAddressPath($validatedPath)) {
                    $this->switchToPath($pathKey, $validatedPath);
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
    /**
     * @param array<string, mixed> $path
     */
    public function switchToPath(string $pathKey, array $path): void
    {
        // 将当前活跃路径移到已验证路径
        if (null !== $this->activePath) {
            $localAddr = $this->activePath['local_address'];
            $localPort = $this->activePath['local_port'];
            $remoteAddr = $this->activePath['remote_address'];
            $remotePort = $this->activePath['remote_port'];

            $oldPathKey = $this->getPathKey(
                is_string($localAddr) ? $localAddr : '',
                is_int($localPort) ? $localPort : 0,
                is_string($remoteAddr) ? $remoteAddr : '',
                is_int($remotePort) ? $remotePort : 0
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
            throw new InvalidConnectionStateException('只有服务端可以设置首选地址');
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
    /**
     * @param array<string, mixed> $path
     */
    private function isPreferredAddressPath(array $path): bool
    {
        if (null === $this->preferredAddress) {
            return false;
        }

        return $path['remote_address'] === $this->preferredAddress['address']
               && $path['remote_port'] === $this->preferredAddress['port'];
    }

    /**
     * 清理超时的探测路径
     */
    public function cleanupTimeoutPaths(): void
    {
        $now = time();

        foreach ($this->probingPaths as $pathKey => $path) {
            $probeStart = $path['probe_start'];
            if (is_int($probeStart) && $now - $probeStart > 30) { // 30秒超时
                $path['state'] = PathState::FAILED;
                unset($this->probingPaths[$pathKey]);
            }
        }

        // 清理验证超时
        if (null !== $this->validationTimeout && $now > $this->validationTimeout) {
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
     * @return array<string, mixed>|null
     */
    public function getActivePath(): ?array
    {
        return $this->activePath;
    }

    /**
     * 获取所有已验证路径
     * @return array<string, array<string, mixed>>
     */
    public function getValidatedPaths(): array
    {
        return $this->validatedPaths;
    }

    /**
     * 获取正在探测的路径
     * @return array<string, array<string, mixed>>
     */
    public function getProbingPaths(): array
    {
        return $this->probingPaths;
    }

    /**
     * 获取首选地址
     * @return array<string, mixed>|null
     */
    public function getPreferredAddress(): ?array
    {
        return $this->preferredAddress;
    }

    /**
     * 获取所有路径（包括活跃、已验证和探测中的）
     * @return array<int, array<string, mixed>>
     */
    public function getAllPaths(): array
    {
        $paths = [];

        // 添加活跃路径
        if (null !== $this->activePath) {
            $localAddr = $this->activePath['local_address'];
            $localPort = $this->activePath['local_port'];
            $remoteAddr = $this->activePath['remote_address'];
            $remotePort = $this->activePath['remote_port'];

            $key = $this->getPathKey(
                is_string($localAddr) ? $localAddr : '',
                is_int($localPort) ? $localPort : 0,
                is_string($remoteAddr) ? $remoteAddr : '',
                is_int($remotePort) ? $remotePort : 0
            );
            $paths[$key] = $this->activePath;
        }

        // 添加已验证路径
        foreach ($this->validatedPaths as $key => $path) {
            $paths[$key] = $path;
        }

        // 添加探测中的路径
        foreach ($this->probingPaths as $key => $path) {
            $paths[$key] = $path;
        }

        return array_values($paths);
    }
}
