<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Examples;

use Tourze\QUIC\Connection\ConnectionFactory;
use Tourze\QUIC\Connection\ConnectionManager;
use Tourze\QUIC\Core\Enum\ConnectionState;
use Tourze\QUIC\Transport\TransportManager;
use Tourze\QUIC\Transport\UDPTransport;

/**
 * 基本QUIC客户端示例
 *
 * 演示如何使用QUIC连接包创建基本的客户端连接
 */
class BasicQuicClient
{
    private ConnectionFactory $factory;

    private ConnectionManager $manager;

    private TransportManager $transport;

    public function __construct()
    {
        $this->factory = new ConnectionFactory();
        $this->manager = new ConnectionManager();

        // 创建UDP传输层
        $udpTransport = new UDPTransport('0.0.0.0', 0);
        $this->transport = new TransportManager($udpTransport);
    }

    /**
     * 连接到QUIC服务器
     */
    public function connect(string $hostname, int $port = 443): void
    {
        echo "正在连接到 {$hostname}:{$port}...\n";

        // 创建客户端连接
        $connection = $this->factory->createClientConnection();
        $this->manager->addConnection($connection);

        // 注册事件监听器
        $this->setupEventHandlers($connection);

        // 启动传输层
        $this->transport->start();

        try {
            // 尝试建立连接
            $success = $connection->connect($hostname, $port, '0.0.0.0', 0);

            if (!$success) {
                throw new \RuntimeException("无法初始化连接到 {$hostname}:{$port}");
            }

            // 等待连接建立
            $this->waitForConnection($connection);

            // 发送测试数据
            $this->sendTestData($connection, $hostname);

            // 运行事件循环
            $this->runEventLoop();
        } catch (\Exception $e) {
            echo '连接失败: ' . $e->getMessage() . "\n";
        } finally {
            $this->transport->stop();
        }
    }

    /**
     * 设置事件处理器
     * @param mixed $connection
     */
    private function setupEventHandlers($connection): void
    {
        $connection->onEvent('connected', function (): void {
            echo "✅ 连接已建立\n";
        });

        $connection->onEvent('disconnected', function ($conn, $errorCode, $reason): void {
            echo "❌ 连接已断开: {$reason} (错误码: {$errorCode})\n";
        });

        $connection->onEvent('error', function ($conn, $error): void {
            echo '🚨 连接错误: ' . $error->getMessage() . "\n";
        });

        $connection->onEvent('data_received', function ($conn, $data): void {
            echo '📨 收到数据: ' . substr($data, 0, 100) . "...\n";
        });

        $connection->onEvent('data_sent', function ($conn, $bytes): void {
            echo "📤 发送数据: {$bytes} 字节\n";
        });

        $connection->onEvent('handshake_completed', function ($conn): void {
            echo "🤝 握手完成\n";
        });

        $connection->onEvent('stream_created', function ($conn, $streamId): void {
            echo "🌊 创建流: {$streamId}\n";
        });

        $connection->onEvent('path_changed', function ($conn, $oldPath, $newPath): void {
            echo '🛤️ 路径变更: ' . json_encode($oldPath) . ' -> ' . json_encode($newPath) . "\n";
        });
    }

    /**
     * 等待连接建立
     * @param mixed $connection
     */
    private function waitForConnection($connection, int $timeoutSeconds = 10): void
    {
        echo "等待连接建立...\n";

        $startTime = time();
        while (time() - $startTime < $timeoutSeconds) {
            $this->transport->processPendingEvents();
            $this->manager->processPendingTasks();

            $state = $connection->getStateMachine()->getState();

            if (ConnectionState::OPEN === $state) {
                echo "✅ 连接已建立!\n";

                return;
            }

            if ($state->isClosed()) {
                $closeInfo = $connection->getStateMachine()->getCloseInfo();
                throw new \RuntimeException('连接已关闭: ' . $closeInfo['reason']);
            }

            usleep(100000); // 100ms
        }

        throw new \RuntimeException('连接超时');
    }

    /**
     * 发送测试数据
     * @param mixed $connection
     */
    private function sendTestData($connection, string $hostname): void
    {
        echo "发送HTTP/3测试请求...\n";

        // 构造简单的HTTP/3请求
        $request = "GET / HTTP/3\r\n" .
                  "Host: {$hostname}\r\n" .
                  "User-Agent: QUIC-PHP-Client/1.0\r\n" .
                  "Accept: */*\r\n" .
                  "\r\n";

        try {
            $bytesSent = $connection->sendData($request);
            echo "📤 发送了 {$bytesSent} 字节数据\n";
        } catch (\Exception $e) {
            echo '❌ 发送数据失败: ' . $e->getMessage() . "\n";
        }
    }

    /**
     * 运行事件循环
     */
    private function runEventLoop(int $durationSeconds = 10): void
    {
        echo "运行事件循环 {$durationSeconds} 秒...\n";

        $startTime = time();
        while (time() - $startTime < $durationSeconds) {
            $this->transport->processPendingEvents();
            $this->manager->processPendingTasks();
            $this->manager->checkTimeouts();

            usleep(50000); // 50ms
        }

        echo "事件循环结束\n";
    }

    /**
     * 获取连接统计信息
     * @param mixed $connection
     */
    public function getConnectionStats($connection): array
    {
        $monitor = $connection->getMonitor();
        $stats = $monitor->getStatistics();

        return [
            'state' => $connection->getStateMachine()->getState()->name,
            'packets_sent' => $stats['packets_sent'] ?? 0,
            'packets_received' => $stats['packets_received'] ?? 0,
            'bytes_sent' => $stats['bytes_sent'] ?? 0,
            'bytes_received' => $stats['bytes_received'] ?? 0,
            'streams_created' => $stats['streams_created'] ?? 0,
            'health_status' => $monitor->getHealthStatus(),
        ];
    }
}

// 如果直接运行此脚本
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'])) {
    $client = new BasicQuicClient();

    // 测试目标
    $testTargets = [
        ['cloudflare-quic.com', 443],
        ['www.google.com', 443],
    ];

    foreach ($testTargets as [$hostname, $port]) {
        echo "\n" . str_repeat('=', 50) . "\n";
        echo "测试目标: {$hostname}:{$port}\n";
        echo str_repeat('=', 50) . "\n";

        try {
            $client->connect($hostname, $port);
        } catch (\Exception $e) {
            echo '测试失败: ' . $e->getMessage() . "\n";
        }

        echo "\n";
    }
}
