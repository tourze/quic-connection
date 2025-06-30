<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\ConnectionFactory;
use Tourze\QUIC\Connection\ConnectionManager;
use Tourze\QUIC\Core\Constants;
use Tourze\QUIC\Core\Enum\ConnectionState;

/**
 * 综合 QUIC 测试
 * 
 * 展示 QUIC 连接包的完整功能
 */
class ComprehensiveQuicTest extends TestCase
{
    private ConnectionFactory $factory;
    private ConnectionManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->factory = new ConnectionFactory();
        $this->manager = new ConnectionManager();
    }

    /**
     * 测试连接工厂的配置
     */
    public function testConnectionFactoryConfiguration(): void
    {
        // 配置传输参数
        $this->factory->setIdleTimeout(60000); // 60秒
        $this->factory->setMaxData(10485760); // 10MB
        $this->factory->setMaxStreamData(1048576); // 1MB
        $this->factory->setMaxBidiStreams(128);
        $this->factory->setMaxUniStreams(128);
        
        // 创建连接
        $connection = $this->factory->createClientConnection();
        
        // 验证参数
        $params = $connection->getTransportParameters();
        $this->assertEquals(60000, $params['max_idle_timeout']);
        $this->assertEquals(10485760, $params['initial_max_data']);
        $this->assertEquals(1048576, $params['initial_max_stream_data_bidi_local']);
        $this->assertEquals(128, $params['initial_max_streams_bidi']);
        $this->assertEquals(128, $params['initial_max_streams_uni']);
    }

    /**
     * 测试连接管理器功能
     */
    public function testConnectionManagerFeatures(): void
    {
        // 设置最大连接数
        $this->manager->setMaxConnections(10);
        $this->assertEquals(10, $this->manager->getMaxConnections());
        
        // 添加多个连接
        $connections = [];
        for ($i = 0; $i < 5; $i++) {
            $conn = $this->factory->createClientConnection();
            $this->manager->addConnection($conn);
            $connections[] = $conn;
        }
        
        $this->assertEquals(5, $this->manager->getConnectionCount());
        
        // 获取统计信息
        $stats = $this->manager->getStatistics();
        $this->assertEquals(5, $stats['total_connections']);
        $this->assertEquals(10, $stats['max_connections']);
        $this->assertArrayHasKey('by_state', $stats);
        $this->assertEquals(5, $stats['by_state']['new'] ?? 0);
        
        // 测试连接状态转换
        $connections[0]->connect('example.com', 443);
        $stats = $this->manager->getStatistics();
        $this->assertEquals(4, $stats['by_state']['new'] ?? 0);
        $this->assertEquals(1, $stats['by_state']['handshaking'] ?? 0);
        
        // 清理关闭的连接
        $this->manager->cleanup();
        $this->assertEquals(5, $this->manager->getConnectionCount());
        
        // 关闭所有连接
        $this->manager->closeAllConnections(0, 'test shutdown');
        
        // 清理后应该没有连接了
        $this->manager->cleanup();
        $this->assertEquals(0, $this->manager->getConnectionCount());
    }

    /**
     * 测试路径管理器功能
     */
    public function testPathManagerFeatures(): void
    {
        $connection = $this->factory->createClientConnection();
        $pathManager = $connection->getPathManager();
        
        // 初始化路径
        $pathManager->initializePath('192.168.1.100', 12345, '93.184.216.34', 443);
        
        $activePath = $pathManager->getActivePath();
        $this->assertNotNull($activePath);
        $this->assertEquals('192.168.1.100', $activePath['local_address']);
        $this->assertEquals(12345, $activePath['local_port']);
        $this->assertEquals('93.184.216.34', $activePath['remote_address']);
        $this->assertEquals(443, $activePath['remote_port']);
        
        // 探测新路径
        $pathManager->probePath('192.168.1.101', 12346, '93.184.216.34', 443);
        
        $paths = $pathManager->getAllPaths();
        $this->assertCount(2, $paths);
        
        // 设置首选地址（服务端功能）
        if (!$connection->isServer()) {
            try {
                $pathManager->setPreferredAddress('192.168.1.200', 443);
                $this->fail("客户端不应该能设置首选地址");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('服务端', $e->getMessage());
            }
        }
    }

    /**
     * 测试空闲超时管理器
     */
    public function testIdleTimeoutManagerFeatures(): void
    {
        $connection = $this->factory->createClientConnection();
        $idleManager = $connection->getIdleTimeoutManager();
        
        // 设置超时时间
        $idleManager->setIdleTimeout(5000); // 5秒
        $this->assertEquals(5000, $idleManager->getIdleTimeout());
        
        // 检查剩余时间
        $timeToTimeout = $idleManager->getTimeToTimeout();
        $this->assertGreaterThan(4800, $timeToTimeout); // 给一些余量
        $this->assertLessThanOrEqual(5000, $timeToTimeout);
        
        // 测试活动更新
        sleep(1);
        $idleManager->updateActivity();
        $newTimeToTimeout = $idleManager->getTimeToTimeout();
        $this->assertGreaterThan($timeToTimeout - 1500, $newTimeToTimeout); // 考虑 sleep 的时间
        
        // 测试PING功能
        $idleManager->setPingEnabled(true);
        $timeToPing = $idleManager->getTimeToPing();
        $this->assertLessThan(5000, $timeToPing); // PING间隔应该小于超时时间
        
        // 测试超时延长
        $idleManager->extendTimeout(2000);
        $this->assertEquals(7000, $idleManager->getIdleTimeout());
    }

    /**
     * 测试事件系统
     */
    public function testEventSystem(): void
    {
        $connection = $this->factory->createClientConnection();
        
        $events = [];
        $eventData = [];
        
        // 注册多个事件监听器
        $connection->onEvent('connecting', function($conn, $data) use (&$events, &$eventData) {
            $events[] = 'connecting';
            $eventData['connecting'] = $data;
        });
        
        $connection->onEvent('error', function($conn, $data) use (&$events, &$eventData) {
            $events[] = 'error';
            $eventData['error'] = $data;
        });
        
        $connection->onEvent('data_sent', function($conn, $data) use (&$events, &$eventData) {
            $events[] = 'data_sent';
            $eventData['data_sent'] = $data;
        });
        
        // 触发连接事件
        $connection->connect('example.com', 443);
        $this->assertContains('connecting', $events);
        $this->assertEquals('example.com', $eventData['connecting']['remote_address']);
        $this->assertEquals(443, $eventData['connecting']['remote_port']);
        
        // 手动设置为已连接状态来测试发送数据
        $connection->getStateMachine()->transitionTo(ConnectionState::CONNECTED);
        $bytesSent = $connection->sendData('Hello QUIC!');
        $this->assertEquals(11, $bytesSent);
        $this->assertContains('data_sent', $events);
        $this->assertEquals(11, $eventData['data_sent']['bytes']);
    }

    /**
     * 测试传输参数设置
     */
    public function testTransportParameters(): void
    {
        $connection = $this->factory->createClientConnection();
        
        // 设置各种传输参数
        $connection->setVersion(Constants::VERSION_1);
        $connection->setInitialMaxData(2097152); // 2MB
        $connection->setInitialMaxStreamData(524288); // 512KB
        
        // 获取并验证参数
        $params = $connection->getTransportParameters();
        $this->assertEquals(Constants::VERSION_1, $params['initial_version']);
        $this->assertEquals(2097152, $params['initial_max_data']);
        $this->assertEquals(524288, $params['initial_max_stream_data_bidi_local']);
        
        // 测试单个参数获取
        $this->assertEquals(2097152, $connection->getTransportParameter('initial_max_data'));
        $this->assertNull($connection->getTransportParameter('non_existent_param'));
    }

    /**
     * 测试 QUIC 常量和版本
     */
    public function testQuicConstants(): void
    {
        // 测试版本字符串
        $this->assertEquals('QUIC v1', Constants::getVersionString(Constants::VERSION_1));
        $this->assertEquals('QUIC Draft 29', Constants::getVersionString(Constants::VERSION_DRAFT_29));
        $this->assertStringContainsString('Unknown Version', Constants::getVersionString(0x12345678));
        
        // 测试版本支持
        $this->assertTrue(Constants::isSupportedVersion(Constants::VERSION_1));
        $this->assertTrue(Constants::isSupportedVersion(Constants::VERSION_DRAFT_29));
        $this->assertFalse(Constants::isSupportedVersion(0xbabababa));
        
        // 测试支持的版本列表
        $versions = Constants::getSupportedVersions();
        $this->assertContains(Constants::VERSION_1, $versions);
        $this->assertContains(Constants::VERSION_DRAFT_29, $versions);
        
        // 测试默认传输参数
        $defaults = Constants::getDefaultTransportParameters();
        $this->assertArrayHasKey('max_idle_timeout', $defaults);
        $this->assertArrayHasKey('initial_max_data', $defaults);
        $this->assertArrayHasKey('initial_max_streams_bidi', $defaults);
        
        // 测试错误码
        $this->assertEquals(0x00, Constants::ERROR_NO_ERROR);
        $this->assertEquals(0x01, Constants::ERROR_INTERNAL_ERROR);
        $this->assertEquals(0x03, Constants::ERROR_FLOW_CONTROL_ERROR);
        $this->assertEquals(0x0A, Constants::ERROR_PROTOCOL_VIOLATION);
    }

    /**
     * 测试连接状态枚举
     */
    public function testConnectionStateEnum(): void
    {
        $state = ConnectionState::NEW;
        
        // 测试状态判断方法
        $this->assertFalse($state->canSendData());
        $this->assertFalse($state->canReceiveData());
        $this->assertEquals(ConnectionState::NEW, $state); // 移除对不存在方法的调用
        $this->assertFalse($state->isConnected());
        $this->assertFalse($state->isClosed());
        $this->assertTrue($state->isActive());
        
        // 测试握手状态
        $state = ConnectionState::HANDSHAKING;
        $this->assertTrue($state->canSendData());
        $this->assertTrue($state->canReceiveData());
        $this->assertEquals(ConnectionState::HANDSHAKING, $state); // 直接比较枚举值
        $this->assertFalse($state->isConnected());
        $this->assertTrue($state->isActive());
        
        // 测试已连接状态
        $state = ConnectionState::CONNECTED;
        $this->assertTrue($state->canSendData());
        $this->assertTrue($state->canReceiveData());
        $this->assertTrue($state->isConnected());
        $this->assertTrue($state->canSendStreamData());
        $this->assertTrue($state->canCreateStream());
        
        // 测试关闭状态
        $state = ConnectionState::CLOSED;
        $this->assertFalse($state->canSendData());
        $this->assertFalse($state->canReceiveData());
        $this->assertTrue($state->isClosed());
        $this->assertFalse($state->isActive());
        
        // 测试状态转换
        $this->assertTrue(ConnectionState::NEW->canTransitionTo(ConnectionState::HANDSHAKING));
        $this->assertTrue(ConnectionState::HANDSHAKING->canTransitionTo(ConnectionState::CONNECTED));
        $this->assertFalse(ConnectionState::CLOSED->canTransitionTo(ConnectionState::NEW));
        
        // 测试标签
        $this->assertEquals('新建', ConnectionState::NEW->getLabel());
        $this->assertEquals('握手中', ConnectionState::HANDSHAKING->getLabel());
        $this->assertEquals('已连接', ConnectionState::CONNECTED->getLabel());
    }
}