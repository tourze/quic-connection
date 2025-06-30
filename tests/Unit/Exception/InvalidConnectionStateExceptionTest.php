<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Unit\Exception;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Exception\InvalidConnectionStateException;

/**
 * @covers \Tourze\QUIC\Connection\Exception\InvalidConnectionStateException
 */
class InvalidConnectionStateExceptionTest extends TestCase
{
    public function testIsInvalidArgumentException(): void
    {
        $exception = new InvalidConnectionStateException('Test message');
        
        $this->assertInstanceOf(InvalidArgumentException::class, $exception);
        $this->assertInstanceOf(InvalidConnectionStateException::class, $exception);
    }

    public function testMessage(): void
    {
        $message = 'Invalid connection state transition';
        $exception = new InvalidConnectionStateException($message);
        
        $this->assertEquals($message, $exception->getMessage());
    }

    public function testCode(): void
    {
        $code = 54321;
        $exception = new InvalidConnectionStateException('Test', $code);
        
        $this->assertEquals($code, $exception->getCode());
    }
}