<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Enum\PathState;
use Tourze\QUIC\Connection\PathManager;

/**
 * PathManager 类单元测试
 */
class PathManagerTest extends TestCase
{
    private PathManager $pathManager;

    protected function setUp(): void
    {
        $this->pathManager = new PathManager(false);
    }

    public function test_initialize_path(): void
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

    public function test_probe_path(): void
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

    public function test_probe_duplicate_path(): void
    {
        // 探测同一个路径两次
        $this->pathManager->probePath('192.168.1.20', 9090, '192.168.1.200', 8080);
        $this->pathManager->probePath('192.168.1.20', 9090, '192.168.1.200', 8080);

        $probingPaths = $this->pathManager->getProbingPaths();

        // 应该只有一个路径
        $this->assertCount(1, $probingPaths);
    }

    public function test_handle_path_challenge(): void
    {
        $challengeData = 'test_challenge_data';
        $sourcePath = '192.168.1.10:8080-192.168.1.100:443';

        // 这个方法应该不抛出异常
        $this->pathManager->handlePathChallenge($challengeData, $sourcePath);

        $this->assertTrue(true); // 如果没有异常，测试通过
    }

    public function test_handle_path_response_invalid(): void
    {
        // 没有进行路径探测时的响应应该返回false
        $result = $this->pathManager->handlePathResponse('invalid_response');

        $this->assertFalse($result);
    }

    public function test_cleanup_timeout_paths(): void
    {
        // 添加一个探测路径
        $this->pathManager->probePath('192.168.1.20', 9090, '192.168.1.200', 8080);

        $this->assertCount(1, $this->pathManager->getProbingPaths());

        // 清理超时路径（由于时间较短，这里主要测试方法调用）
        $this->pathManager->cleanupTimeoutPaths();

        // 路径应该仍然存在（因为刚刚创建）
        $this->assertCount(1, $this->pathManager->getProbingPaths());
    }

    public function test_set_preferred_address_as_server(): void
    {
        $serverPathManager = new PathManager(true);

        $serverPathManager->setPreferredAddress('192.168.1.200', 443);

        $preferredAddress = $serverPathManager->getPreferredAddress();

        $this->assertNotNull($preferredAddress);
        $this->assertEquals('192.168.1.200', $preferredAddress['address']);
        $this->assertEquals(443, $preferredAddress['port']);
    }

    public function test_set_preferred_address_as_client_should_fail(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('只有服务端可以设置首选地址');

        $this->pathManager->setPreferredAddress('192.168.1.200', 443);
    }

    public function test_get_validated_paths_initially_empty(): void
    {
        $validatedPaths = $this->pathManager->getValidatedPaths();
        $this->assertEmpty($validatedPaths);
    }

    public function test_get_active_path_initially_null(): void
    {
        $activePath = $this->pathManager->getActivePath();

        $this->assertNull($activePath);
    }

    public function test_get_preferred_address_initially_null(): void
    {
        $preferredAddress = $this->pathManager->getPreferredAddress();

        $this->assertNull($preferredAddress);
    }
} 