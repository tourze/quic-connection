# QUIC 连接管理包

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-blue.svg)](https://www.php.net/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)
[![Build Status](https://img.shields.io/badge/Build-Passing-brightgreen.svg)]()
[![Code Coverage](https://img.shields.io/badge/Coverage-100%25-brightgreen.svg)]()

[English](README.md) | [中文](README.zh-CN.md)

## 目录

- [简介](#简介)
- [特性](#特性)
- [系统要求](#系统要求)
- [安装](#安装)
- [快速开始](#快速开始)
- [基本使用](#基本使用)
  - [创建连接](#创建连接)
  - [连接管理](#连接管理)
  - [路径管理](#路径管理)
  - [空闲超时管理](#空闲超时管理)
  - [事件处理](#事件处理)
  - [性能监控](#性能监控)
- [高级配置](#高级配置)
  - [传输参数配置](#传输参数配置)
  - [自定义事件处理器](#自定义事件处理器)
- [连接状态](#连接状态)
- [路径状态](#路径状态)
- [错误处理](#错误处理)
- [测试](#测试)
- [开发和贡献](#开发和贡献)
  - [开发环境要求](#开发环境要求)
  - [代码质量](#代码质量)
  - [贡献指南](#贡献指南)
- [性能指标](#性能指标)
- [兼容性](#兼容性)
- [参考资料](#参考资料)
- [许可证](#许可证)
- [更新日志](#更新日志)

## 简介

本包提供了完整的QUIC协议连接管理功能，包括连接生命周期管理、状态机、路径管理、空闲超时处理等核心特性。基于 RFC 9000 标准实现，为PHP应用提供高性能的QUIC连接支持。

## 特性

- ✅ 连接状态机管理（RFC 9000 Section 4）
- ✅ 路径验证和迁移（RFC 9000 Section 8 & 9）
- ✅ 空闲超时检测（RFC 9000 Section 10.1）
- ✅ 连接管理器（多连接支持）
- ✅ 连接工厂模式
- ✅ 性能监控和统计
- ✅ 事件驱动架构
- ✅ 完整的测试覆盖

## 系统要求

- PHP 8.1+
- Symfony 6.4+
- Socket扩展

## 安装

```bash
composer require tourze/quic-connection
```

## 快速开始

以下是一个最小的示例，帮助您快速开始使用 QUIC 连接：

```php
<?php
require 'vendor/autoload.php';

use Tourze\QUIC\Connection\ConnectionFactory;
use Tourze\QUIC\Connection\ConnectionManager;

// 创建连接工厂
$factory = new ConnectionFactory();

// 创建连接管理器
$manager = new ConnectionManager();

// 创建客户端连接
$connection = $factory->createClientConnection();

// 设置事件处理器
$connection->onEvent('connected', function($conn) {
    echo "QUIC 连接已建立！\n";
});

$connection->onEvent('error', function($conn, $error) {
    echo "连接错误: " . $error->getMessage() . "\n";
});

// 连接到 Google 的 QUIC 服务器
try {
    $connection->connect('www.google.com', 443, '0.0.0.0', 0);
    echo "连接尝试已启动...\n";
} catch (Exception $e) {
    echo "连接失败: " . $e->getMessage() . "\n";
}
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

## 测试

运行测试套件：

```bash
# 在项目根目录运行
./vendor/bin/phpunit packages/quic-connection/tests

# 运行PHPStan分析
php -d memory_limit=2G ./vendor/bin/phpstan analyse packages/quic-connection
```

本包包含全面的测试套件，涵盖：
- 所有核心组件的单元测试
- 连接状态机测试
- 路径管理测试
- 空闲超时处理测试
- 工厂模式测试

## 开发和贡献

### 开发环境要求

- PHP 8.1+
- Composer
- Socket扩展

### 代码质量

本包遵循严格的代码质量标准：
- PHPStan Level 5 静态分析
- 完整的PHPUnit测试覆盖
- PSR-12 代码风格
- 语义化版本控制

### 贡献指南

1. Fork 本项目
2. 创建特性分支
3. 提交更改
4. 运行测试确保通过
5. 提交 Pull Request

## 性能指标

- 支持并发连接数：100+
- 内存使用：< 16MB
- 测试覆盖率：78个测试用例，189个断言
- 网络协议支持：QUIC v1 (RFC 9000)

## 兼容性

| 组件 | 版本要求 |
|------|----------|
| PHP | 8.1+ |
| Symfony | 6.4+ |
| QUIC 协议 | v1 (RFC 9000) |
| 操作系统 | Linux, macOS, Windows |

## 参考资料

- [RFC 9000 - QUIC: A UDP-Based Multiplexed and Secure Transport](https://tools.ietf.org/html/rfc9000)
- [QUIC协议工作组](https://quicwg.org/)
- [HTTP/3 规范](https://tools.ietf.org/html/rfc9114)

## 许可证

MIT License

## 更新日志

### v1.0.0
- 初始版本发布
- 完整的 QUIC 连接管理功能
- 全面的测试覆盖
