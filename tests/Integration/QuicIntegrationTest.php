<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Connection;
use Tourze\QUIC\Connection\ConnectionFactory;
use Tourze\QUIC\Connection\ConnectionManager;
use Tourze\QUIC\Core\Enum\ConnectionState;
use Tourze\QUIC\Transport\TransportManager;
use Tourze\QUIC\Transport\UDPTransport;

/**
 * QUIC协议集成测试
 *
 * 针对公开的QUIC服务器进行协议兼容性测试
 */
class QuicIntegrationTest extends TestCase
{
    private ConnectionFactory $factory;
    private ConnectionManager $manager;
    private TransportManager $transport;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->factory = new ConnectionFactory();
        $this->manager = new ConnectionManager();
        
        // 创建传输层管理器
        $udpTransport = new UDPTransport('0.0.0.0', 0); // 自动分配端口
        $this->transport = new TransportManager($udpTransport);
    }

    protected function tearDown(): void
    {
        $this->transport->stop();
        parent::tearDown();
    }

    /**
     * 测试基本QUIC连接建立
     *
     * @dataProvider quicTestServersProvider
     */
    public function testBasicQuicConnection(string $hostname, int $port, string $description): void
    {
        $this->markTestSkipped('需要完整的QUIC协议栈实现后才能运行此测试');
    }

    /**
     * 测试QUIC版本协商
     *
     * @dataProvider quicTestServersProvider
     */
    public function testVersionNegotiation(string $hostname, int $port, string $description): void
    {
        $this->markTestSkipped('需要完整的QUIC协议栈实现后才能运行此测试');
    }

    /**
     * 测试基本数据传输
     *
     * @depends testBasicQuicConnection
     * @dataProvider quicTestServersProvider
     */
    public function testBasicDataTransfer(string $hostname, int $port, string $description): void
    {
        $this->markTestSkipped('需要完整的QUIC协议栈实现后才能运行此测试');
    }

    /**
     * 测试连接超时处理
     */
    public function testConnectionTimeout(): void
    {
        $connection = $this->factory->createClientConnection();
        
        // 设置很短的超时时间
        $connection->getIdleTimeoutManager()->setIdleTimeout(1000); // 1秒
        
        $timeoutTriggered = false;
        $connection->onEvent('timeout', function() use (&$timeoutTriggered) {
            $timeoutTriggered = true;
        });
        
        // 连接到不存在的地址
        $this->transport->start();
        $connection->connect('192.0.2.1', 443, '0.0.0.0', 0); // RFC5737测试地址
        
        // 等待超时
        sleep(2);
        
        // 先在连接对象上直接检查超时
        $timedOut = $connection->getIdleTimeoutManager()->checkTimeout();
        
        // 然后通过管理器检查（这会清理连接）
        $this->manager->checkTimeouts();
        
        $this->assertTrue($timedOut, "checkTimeout 应该返回 true");
        $this->assertTrue($timeoutTriggered, "连接超时事件未触发");
        $this->assertTrue(
            $connection->getStateMachine()->getState()->isClosed(),
            "连接未正确关闭"
        );
    }

    /**
     * 测试连接状态机
     */
    public function testConnectionStateMachine(): void
    {
        $connection = $this->factory->createClientConnection();
        
        $this->assertEquals(
            ConnectionState::NEW,
            $connection->getStateMachine()->getState(),
            "新连接状态应为NEW"
        );
        
        // 模拟状态转换
        $connection->getStateMachine()->transitionTo(ConnectionState::HANDSHAKING);
        $this->assertEquals(
            ConnectionState::HANDSHAKING,
            $connection->getStateMachine()->getState()
        );
        
        $connection->getStateMachine()->transitionTo(ConnectionState::CONNECTED);
        $this->assertEquals(
            ConnectionState::CONNECTED,
            $connection->getStateMachine()->getState()
        );
    }

    /**
     * 提供QUIC测试服务器数据
     */
    public static function quicTestServersProvider(): array
    {
        return [
            'Cloudflare QUIC' => ['cloudflare-quic.com', 443, 'Cloudflare QUIC测试服务器'],
            'Google' => ['www.google.com', 443, 'Google QUIC服务'],
            // 'HTTP3Check' => ['http3check.net', 443, 'HTTP/3检查工具'],
        ];
    }

}