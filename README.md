# QUIC Connection Package

## 简介

本包提供了完整的QUIC协议连接管理功能，包括连接生命周期管理、状态机、路径管理、空闲超时处理等核心特性。

## 特性

- ✅ 连接状态机管理（RFC 9000 Section 4）
- ✅ 路径验证和迁移（RFC 9000 Section 8 & 9）
- ✅ 空闲超时检测（RFC 9000 Section 10.1）
- ✅ 连接管理器（多连接支持）
- ✅ 连接工厂模式
- ✅ 性能监控和统计
- ✅ 事件驱动架构

## 安装

```bash
composer require tourze/quic-connection
```

## 基本使用

### 创建连接

```php
use Tourze\QUIC\Connection\ConnectionFactory;

// 创建连接工厂
$factory = new ConnectionFactory();

// 创建客户端连接
$clientConnection = $factory->createClientConnection();

// 创建服务端连接
$serverConnection = $factory->createServerConnection();

// 建立连接
$clientConnection->connect('192.168.1.100', 443, '0.0.0.0', 0);
```

### 连接管理

```php
use Tourze\QUIC\Connection\ConnectionManager;

$manager = new ConnectionManager();
$manager->setMaxConnections(100);

// 添加连接
$manager->addConnection($connection);

// 处理定期任务
$manager->processPendingTasks();

// 清理超时连接
$manager->checkTimeouts();
```

### 路径管理

```php
// 获取路径管理器
$pathManager = $connection->getPathManager();

// 探测新路径
$pathManager->probePath('192.168.1.10', 8080, '192.168.1.100', 443);

// 设置首选地址（服务端）
$pathManager->setPreferredAddress('192.168.1.200', 443);
```

### 空闲超时管理

```php
// 获取空闲超时管理器
$idleManager = $connection->getIdleTimeoutManager();

// 设置超时时间（毫秒）
$idleManager->setIdleTimeout(30000);

// 检查是否需要发送PING
if ($idleManager->shouldSendPing()) {
    // 发送PING帧
}
```

### 事件处理

```php
// 注册事件监听器
$connection->onEvent('connected', function($connection) {
    echo "连接已建立\n";
});

$connection->onEvent('disconnected', function($connection, $errorCode, $reason) {
    echo "连接已断开: {$reason}\n";
});

$connection->onEvent('error', function($connection, $error) {
    echo "连接错误: " . $error->getMessage() . "\n";
});
```

### 性能监控

```php
use Tourze\QUIC\Connection\ConnectionMonitor;

// 创建监控器
$monitor = new ConnectionMonitor($connection);

// 获取统计信息
$stats = $monitor->getStatistics();
echo "发送包数: " . $stats['packets_sent'] . "\n";
echo "接收包数: " . $stats['packets_received'] . "\n";

// 检查健康状态
$health = $monitor->getHealthStatus();
if ($health['is_healthy']) {
    echo "连接健康\n";
}
```

## 高级配置

### 传输参数配置

```php
$factory = new ConnectionFactory();

// 设置空闲超时
$factory->setIdleTimeout(60000); // 60秒

// 设置最大数据量
$factory->setMaxData(1048576); // 1MB

// 设置最大流数据量
$factory->setMaxStreamData(262144); // 256KB

// 设置最大双向流数量
$factory->setMaxBidiStreams(100);

// 设置最大单向流数量
$factory->setMaxUniStreams(100);
```

### 自定义事件处理器

```php
use Tourze\QUIC\Connection\ConnectionEventInterface;

class MyConnectionHandler implements ConnectionEventInterface
{
    public function onConnected(Connection $connection): void
    {
        // 连接建立处理
    }

    public function onDisconnected(Connection $connection, int $errorCode, string $reason): void
    {
        // 连接断开处理
    }

    public function onError(Connection $connection, \Throwable $error): void
    {
        // 错误处理
    }

    public function onDataReceived(Connection $connection, string $data): void
    {
        // 数据接收处理
    }

    public function onPathChanged(Connection $connection, array $oldPath, array $newPath): void
    {
        // 路径切换处理
    }
}

// 注册事件处理器
$handler = new MyConnectionHandler();
$factory->addDefaultEventHandler('connected', [$handler, 'onConnected']);
```

## 连接状态

连接具有以下状态：

- `NEW`: 新建连接
- `HANDSHAKING`: 握手中
- `OPEN`: 连接已建立
- `CLOSING`: 正在关闭
- `DRAINING`: 排空状态
- `CLOSED`: 已关闭

## 路径状态

路径具有以下状态：

- `UNVALIDATED`: 未验证
- `PROBING`: 探测中
- `VALIDATED`: 已验证
- `FAILED`: 失败

## 错误处理

```php
try {
    $connection->connect('invalid-host', 443);
} catch (\RuntimeException $e) {
    echo "连接错误: " . $e->getMessage() . "\n";
}

// 检查连接状态
if ($connection->getStateMachine()->getState()->isClosed()) {
    $closeInfo = $connection->getStateMachine()->getCloseInfo();
    echo "连接关闭原因: " . $closeInfo['reason'] . "\n";
}
```

## 参考资料

- [RFC 9000 - QUIC: A UDP-Based Multiplexed and Secure Transport](https://tools.ietf.org/html/rfc9000)
- [QUIC协议文档](https://quicwg.org/)

## 许可证

MIT License
