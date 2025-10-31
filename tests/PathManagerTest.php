<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Enum\PathState;
use Tourze\QUIC\Connection\PathManager;

/**
 * PathManager 类单元测试
 *
 * @internal
 */
#[CoversClass(PathManager::class)]
final class PathManagerTest extends TestCase
{
    private PathManager $pathManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pathManager = new PathManager(false);
    }

    public function testInitializePath(): void
    {
        $this->pathManager->initializePath('192.168.1.10', 8080, '192.168.1.100', 443);

        $activePath = $this->pathManager->getActivePath();

        $this->assertNotNull($activePath);
        $this->assertEquals('192.168.1.10', $activePath['local_address']);
        $this->assertEquals(8080, $activePath['local_port']);
        $this->assertEquals('192.168.1.100', $activePath['remote_address']);
        $this->assertEquals(443, $activePath['remote_port']);
        $this->assertEquals(PathState::VALIDATED, $activePath['state']);
        $this->assertIsInt($activePath['validated_at']);
    }

    public function testProbePath(): void
    {
        $this->pathManager->probePath('192.168.1.20', 9090, '192.168.1.200', 8080);

        $probingPaths = $this->pathManager->getProbingPaths();

        $this->assertCount(1, $probingPaths);

        $path = array_values($probingPaths)[0];
        $this->assertEquals('192.168.1.20', $path['local_address']);
        $this->assertEquals(9090, $path['local_port']);
        $this->assertEquals('192.168.1.200', $path['remote_address']);
        $this->assertEquals(8080, $path['remote_port']);
        $this->assertEquals(PathState::PROBING, $path['state']);
        $this->assertIsInt($path['probe_start']);
    }

    public function testProbeDuplicatePath(): void
    {
        // 探测同一个路径两次
        $this->pathManager->probePath('192.168.1.20', 9090, '192.168.1.200', 8080);
        $this->pathManager->probePath('192.168.1.20', 9090, '192.168.1.200', 8080);

        $probingPaths = $this->pathManager->getProbingPaths();

        // 应该只有一个路径
        $this->assertCount(1, $probingPaths);
    }

    public function testHandlePathChallenge(): void
    {
        $challengeData = 'test_challenge_data';
        $sourcePath = '192.168.1.10:8080-192.168.1.100:443';

        $initialProbingCount = count($this->pathManager->getProbingPaths());

        // 这个方法应该不抛出异常
        $this->pathManager->handlePathChallenge($challengeData, $sourcePath);

        // 验证方法调用后状态没有意外改变
        $this->assertCount($initialProbingCount, $this->pathManager->getProbingPaths());
    }

    public function testHandlePathResponseInvalid(): void
    {
        // 没有进行路径探测时的响应应该返回false
        $result = $this->pathManager->handlePathResponse('invalid_response');

        $this->assertFalse($result);
    }

    public function testCleanupTimeoutPaths(): void
    {
        // 添加一个探测路径
        $this->pathManager->probePath('192.168.1.20', 9090, '192.168.1.200', 8080);

        $this->assertCount(1, $this->pathManager->getProbingPaths());

        // 清理超时路径（由于时间较短，这里主要测试方法调用）
        $this->pathManager->cleanupTimeoutPaths();

        // 路径应该仍然存在（因为刚刚创建）
        $this->assertCount(1, $this->pathManager->getProbingPaths());
    }

    public function testSetPreferredAddressAsServer(): void
    {
        $serverPathManager = new PathManager(true);

        $serverPathManager->setPreferredAddress('192.168.1.200', 443);

        $preferredAddress = $serverPathManager->getPreferredAddress();

        $this->assertNotNull($preferredAddress);
        $this->assertEquals('192.168.1.200', $preferredAddress['address']);
        $this->assertEquals(443, $preferredAddress['port']);
    }

    public function testSetPreferredAddressAsClientShouldFail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('只有服务端可以设置首选地址');

        $this->pathManager->setPreferredAddress('192.168.1.200', 443);
    }

    public function testGetValidatedPathsInitiallyEmpty(): void
    {
        $validatedPaths = $this->pathManager->getValidatedPaths();
        $this->assertEmpty($validatedPaths);
    }

    public function testGetActivePathInitiallyNull(): void
    {
        $activePath = $this->pathManager->getActivePath();

        $this->assertNull($activePath);
    }

    public function testGetPreferredAddressInitiallyNull(): void
    {
        $preferredAddress = $this->pathManager->getPreferredAddress();

        $this->assertNull($preferredAddress);
    }

    public function testSwitchToPath(): void
    {
        // 首先初始化一个路径作为当前活跃路径
        $this->pathManager->initializePath('192.168.1.10', 8080, '192.168.1.100', 443);

        // 创建一个新路径用于切换
        $newPath = [
            'local_address' => '192.168.1.20',
            'local_port' => 9090,
            'remote_address' => '192.168.1.200',
            'remote_port' => 8080,
            'state' => PathState::VALIDATED,
            'validated_at' => time(),
        ];

        $newPathKey = '192.168.1.20:9090-192.168.1.200:8080';

        // 切换到新路径
        $this->pathManager->switchToPath($newPathKey, $newPath);

        // 验证新路径已成为活跃路径
        $activePath = $this->pathManager->getActivePath();
        $this->assertNotNull($activePath);
        $this->assertEquals('192.168.1.20', $activePath['local_address']);
        $this->assertEquals(9090, $activePath['local_port']);
        $this->assertEquals('192.168.1.200', $activePath['remote_address']);
        $this->assertEquals(8080, $activePath['remote_port']);

        // 验证原来的活跃路径被移动到已验证路径
        $validatedPaths = $this->pathManager->getValidatedPaths();
        $this->assertCount(1, $validatedPaths);
    }
}
