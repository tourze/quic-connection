<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Unit\Exception;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tourze\QUIC\Connection\Exception\QuicConnectionException;

/**
 * @covers \Tourze\QUIC\Connection\Exception\QuicConnectionException
 */
class QuicConnectionExceptionTest extends TestCase
{
    public function testIsRuntimeException(): void
    {
        $exception = new QuicConnectionException('Test message');
        
        $this->assertInstanceOf(RuntimeException::class, $exception);
        $this->assertInstanceOf(QuicConnectionException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Connection error occurred';
        $exception = new QuicConnectionException($message);
        
        $this->assertEquals($message, $exception->getMessage());
    }

    public function testCode(): void
    {
        $code = 12345;
        $exception = new QuicConnectionException('Test', $code);
        
        $this->assertEquals($code, $exception->getCode());
    }
}