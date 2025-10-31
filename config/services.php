<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection;

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
    ;

    // 注册需要依赖注入的 QUIC 连接相关服务
    $services->set(ConnectionMonitor::class);
    $services->set(ConnectionStateMachine::class);
    $services->set(IdleTimeoutManager::class);
    $services->set(PathManager::class);

    // 以下类不注册为服务，因为它们是纯业务逻辑类或工厂类：
    // - ConnectionFactory: 工厂类，直接实例化更合适
    // - ConnectionManager: 管理类，不需要依赖注入
    // - Connection: 代表具体的连接实例，应该通过工厂创建
};
