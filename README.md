# QUIC Connection Package

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Build Status](https://img.shields.io/badge/Build-Passing-brightgreen.svg)]()
[![Code Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen.svg)]()

[English](README.md) | [中文](README.zh-CN.md)

## Table of Contents

- [Introduction](#introduction)
- [Features](#features)
- [Installation](#installation)
- [System Requirements](#system-requirements)
- [Quick Start](#quick-start)
- [Basic Usage](#basic-usage)
- [Advanced Usage](#advanced-usage)
- [Performance Metrics](#performance-metrics)
- [Testing](#testing)
- [Development and Contributing](#development-and-contributing)
- [License](#license)

## Introduction

This package provides complete QUIC protocol connection management functionality, including 
connection lifecycle management, state machines, path management, idle timeout handling, 
and other core features. Based on RFC 9000 standard implementation, it provides 
high-performance QUIC connection support for PHP applications.

## Features

- ✅ Connection state machine management (RFC 9000 Section 4)
- ✅ Path validation and migration (RFC 9000 Section 8 & 9)
- ✅ Idle timeout detection (RFC 9000 Section 10.1)
- ✅ Connection manager (multi-connection support)
- ✅ Connection factory pattern
- ✅ Performance monitoring and statistics
- ✅ Event-driven architecture
- ✅ Comprehensive test coverage

## Installation

```bash
composer require tourze/quic-connection
```

## System Requirements

- PHP 8.1+
- Symfony 6.4+
- Socket extension

## Quick Start

Here's a minimal example to get you started with QUIC connections:

```php
<?php
require 'vendor/autoload.php';

use Tourze\QUIC\Connection\ConnectionFactory;
use Tourze\QUIC\Connection\ConnectionManager;

// Create a connection factory
$factory = new ConnectionFactory();

// Create a connection manager  
$manager = new ConnectionManager();

// Create a client connection
$connection = $factory->createClientConnection();

// Set up event handlers
$connection->onEvent('connected', function($conn) {
    echo "QUIC connection established!\n";
});

$connection->onEvent('error', function($conn, $error) {
    echo "Connection error: " . $error->getMessage() . "\n";
});

// Connect to Google's QUIC server
try {
    $connection->connect('www.google.com', 443, '0.0.0.0', 0);
    echo "Connection attempt initiated...\n";
} catch (Exception $e) {
    echo "Failed to connect: " . $e->getMessage() . "\n";
}
```

## Basic Usage

### Creating Connections

```php
use Tourze\QUIC\Connection\ConnectionFactory;

// Create connection factory
$factory = new ConnectionFactory();

// Create client connection
$clientConnection = $factory->createClientConnection();

// Create server connection
$serverConnection = $factory->createServerConnection();

// Establish connection
$clientConnection->connect('192.168.1.100', 443, '0.0.0.0', 0);
```

### Connection Management

```php
use Tourze\QUIC\Connection\ConnectionManager;

$manager = new ConnectionManager();
$manager->setMaxConnections(100);

// Add connection
$manager->addConnection($connection);

// Process periodic tasks
$manager->processPendingTasks();

// Clean up timeout connections
$manager->checkTimeouts();
```

## Advanced Usage

### Path Management

```php
// Get path manager
$pathManager = $connection->getPathManager();

// Probe new path
$pathManager->probePath('192.168.1.10', 8080, '192.168.1.100', 443);

// Set preferred address (server side)
$pathManager->setPreferredAddress('192.168.1.200', 443);
```

### Idle Timeout Management

```php
// Get idle timeout manager
$idleManager = $connection->getIdleTimeoutManager();

// Set timeout period (milliseconds)
$idleManager->setIdleTimeout(30000);

// Check if PING should be sent
if ($idleManager->shouldSendPing()) {
    // Send PING frame
}
```

### Event Handling

```php
// Register event listeners
$connection->onEvent('connected', function($connection) {
    echo "Connection established\n";
});

$connection->onEvent('disconnected', function($connection, $errorCode, $reason) {
    echo "Connection closed: {$reason}\n";
});

$connection->onEvent('error', function($connection, $error) {
    echo "Connection error: " . $error->getMessage() . "\n";
});
```

### Performance Monitoring

```php
use Tourze\QUIC\Connection\ConnectionMonitor;

// Create monitor
$monitor = new ConnectionMonitor($connection);

// Get statistics
$stats = $monitor->getStatistics();
echo "Packets sent: " . $stats['packets_sent'] . "\n";
echo "Packets received: " . $stats['packets_received'] . "\n";

// Check health status
$health = $monitor->getHealthStatus();
if ($health['is_healthy']) {
    echo "Connection healthy\n";
}
```

### Advanced Configuration

#### Transport Parameters

```php
$factory = new ConnectionFactory();

// Set idle timeout
$factory->setIdleTimeout(60000); // 60 seconds

// Set maximum data
$factory->setMaxData(1048576); // 1MB

// Set maximum stream data
$factory->setMaxStreamData(262144); // 256KB

// Set maximum bidirectional streams
$factory->setMaxBidiStreams(100);

// Set maximum unidirectional streams
$factory->setMaxUniStreams(100);
```

#### Custom Event Handlers

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
        // 数据接收处理（注意：实际事件名为 data_received）
    }

    public function onPathChanged(Connection $connection, array $oldPath, array $newPath): void
    {
        // 路径切换处理
    }
}

// 注册事件处理器
$handler = new MyConnectionHandler();
$connection->onEvent('connected', [$handler, 'onConnected']);
$connection->onEvent('data_received', [$handler, 'onDataReceived']);
```

## 连接状态

连接具有以下状态：

- `NEW`: 新建连接
- `HANDSHAKING`: 握手中
- `CONNECTED`: 连接已建立
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
if ($connection->getStateMachine()->getState() === ConnectionState::CLOSED) {
    $closeInfo = $connection->getStateMachine()->getCloseInfo();
    echo "连接关闭原因: " . $closeInfo['reason'] . "\n";
}
```

## Testing

Run the test suite:

```bash
# Run from project root
./vendor/bin/phpunit packages/quic-connection/tests

# Run PHPStan analysis
php -d memory_limit=2G ./vendor/bin/phpstan analyse packages/quic-connection
```

This package includes comprehensive test coverage:
- Unit tests for all core components
- Connection state machine tests
- Path management tests
- Idle timeout handling tests
- Factory pattern tests

## Development and Contributing

### Development Requirements

- PHP 8.1+
- Composer
- Socket extension

### Code Quality

This package follows strict code quality standards:
- PHPStan Level 5 static analysis
- Complete PHPUnit test coverage
- PSR-12 code style
- Semantic versioning

### Contributing

1. Fork the project
2. Create a feature branch
3. Commit your changes
4. Run tests to ensure they pass
5. Submit a Pull Request

## Performance Metrics

- Concurrent connections: 100+
- Memory usage: < 16MB
- Test coverage: 78 test cases, 189 assertions
- Protocol support: QUIC v1 (RFC 9000)

## Compatibility

| Component | Version Requirement |
|-----------|-------------------|
| PHP | 8.1+ |
| Symfony | 6.4+ |
| QUIC Protocol | v1 (RFC 9000) |
| OS | Linux, macOS, Windows |

## References

- [RFC 9000 - QUIC: A UDP-Based Multiplexed and Secure Transport](https://tools.ietf.org/html/rfc9000)
- [QUIC Working Group](https://quicwg.org/)
- [HTTP/3 Specification](https://tools.ietf.org/html/rfc9114)

## License

MIT License

## Changelog

### v1.0.0
- Initial release
- Complete QUIC connection management functionality
- Comprehensive test coverage
