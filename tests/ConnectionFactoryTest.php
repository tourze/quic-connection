<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\ConnectionFactory;
use Tourze\QUIC\Core\Enum\ConnectionState;

/**
 * ConnectionFactory 类单元测试
 *
 * @internal
 */
#[CoversClass(ConnectionFactory::class)]
final class ConnectionFactoryTest extends TestCase
{
    private ConnectionFactory $factory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->factory = new ConnectionFactory();
    }

    public function testCreateClientConnection(): void
    {
        $connection = $this->factory->createClientConnection();

        $this->assertFalse($connection->isServer());
        $this->assertEquals(ConnectionState::NEW, $connection->getStateMachine()->getState());
        $this->assertNotEmpty($connection->getLocalConnectionId());
    }

    public function testCreateServerConnection(): void
    {
        $connection = $this->factory->createServerConnection();

        $this->assertTrue($connection->isServer());
        $this->assertEquals(ConnectionState::NEW, $connection->getStateMachine()->getState());
        $this->assertNotEmpty($connection->getLocalConnectionId());
    }

    public function testCreateConnectionWithCustomId(): void
    {
        $customId = 'custom-connection-id';

        $clientConnection = $this->factory->createClientConnection($customId);
        $serverConnection = $this->factory->createServerConnection($customId);

        $this->assertEquals($customId, $clientConnection->getLocalConnectionId());
        $this->assertEquals($customId, $serverConnection->getLocalConnectionId());
    }

    public function testSetDefaultTransportParameter(): void
    {
        $this->factory->setDefaultTransportParameter('test_param', 'test_value');

        $connection = $this->factory->createClientConnection();

        $this->assertEquals('test_value', $connection->getTransportParameter('test_param'));
    }

    public function testPresetTransportParameters(): void
    {
        // 通过设置默认参数的方式测试预设参数功能
        $this->factory->setDefaultTransportParameter('custom_param1', 'value1');
        $this->factory->setDefaultTransportParameter('custom_param2', 42);

        $connection = $this->factory->createClientConnection();

        $this->assertEquals('value1', $connection->getTransportParameter('custom_param1'));
        $this->assertEquals(42, $connection->getTransportParameter('custom_param2'));
    }

    public function testSetIdleTimeout(): void
    {
        $this->factory->setIdleTimeout(30000);

        $connection = $this->factory->createClientConnection();

        $this->assertEquals(30000, $connection->getTransportParameter('max_idle_timeout'));
    }

    public function testSetMaxData(): void
    {
        $this->factory->setMaxData(1048576);

        $connection = $this->factory->createClientConnection();

        $this->assertEquals(1048576, $connection->getTransportParameter('initial_max_data'));
    }

    public function testSetMaxStreamData(): void
    {
        $this->factory->setMaxStreamData(262144);

        $connection = $this->factory->createClientConnection();

        $this->assertEquals(262144, $connection->getTransportParameter('initial_max_stream_data_bidi_local'));
        $this->assertEquals(262144, $connection->getTransportParameter('initial_max_stream_data_bidi_remote'));
        $this->assertEquals(262144, $connection->getTransportParameter('initial_max_stream_data_uni'));
    }

    public function testSetMaxBidiStreams(): void
    {
        $this->factory->setMaxBidiStreams(100);

        $connection = $this->factory->createClientConnection();

        $this->assertEquals(100, $connection->getTransportParameter('initial_max_streams_bidi'));
    }

    public function testSetMaxUniStreams(): void
    {
        $this->factory->setMaxUniStreams(50);

        $connection = $this->factory->createClientConnection();

        $this->assertEquals(50, $connection->getTransportParameter('initial_max_streams_uni'));
    }

    public function testAddDefaultEventHandler(): void
    {
        $eventTriggered = false;
        $handler = function () use (&$eventTriggered): void {
            $eventTriggered = true;
        };

        $this->factory->addDefaultEventHandler('test_event', $handler);

        $connection = $this->factory->createClientConnection();
        $connection->triggerEvent('test_event');

        $this->assertTrue($eventTriggered);
    }

    public function testMultipleDefaultEventHandlers(): void
    {
        $callCount = 0;

        $handler1 = function () use (&$callCount): void { ++$callCount; };
        $handler2 = function () use (&$callCount): void { ++$callCount; };

        $this->factory->addDefaultEventHandler('test_event', $handler1);
        $this->factory->addDefaultEventHandler('test_event', $handler2);

        $connection = $this->factory->createClientConnection();
        $connection->triggerEvent('test_event');

        $this->assertEquals(2, $callCount);
    }

    public function testFactoryConfigurationIsolation(): void
    {
        $factory1 = new ConnectionFactory();
        $factory2 = new ConnectionFactory();

        $factory1->setIdleTimeout(30000);
        $factory2->setIdleTimeout(60000);

        $connection1 = $factory1->createClientConnection();
        $connection2 = $factory2->createClientConnection();

        $this->assertEquals(30000, $connection1->getTransportParameter('max_idle_timeout'));
        $this->assertEquals(60000, $connection2->getTransportParameter('max_idle_timeout'));
    }

    public function testAllConvenienceMethodsTogether(): void
    {
        $this->factory->setIdleTimeout(30000);
        $this->factory->setMaxData(1048576);
        $this->factory->setMaxStreamData(262144);
        $this->factory->setMaxBidiStreams(100);
        $this->factory->setMaxUniStreams(50);

        $connection = $this->factory->createServerConnection();

        $this->assertEquals(30000, $connection->getTransportParameter('max_idle_timeout'));
        $this->assertEquals(1048576, $connection->getTransportParameter('initial_max_data'));
        $this->assertEquals(262144, $connection->getTransportParameter('initial_max_stream_data_bidi_local'));
        $this->assertEquals(100, $connection->getTransportParameter('initial_max_streams_bidi'));
        $this->assertEquals(50, $connection->getTransportParameter('initial_max_streams_uni'));
    }
}
