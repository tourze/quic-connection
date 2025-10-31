<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\QUIC\Connection\Exception\InvalidConnectionStateException;

/**
 * @internal
 */
#[CoversClass(InvalidConnectionStateException::class)]
final class InvalidConnectionStateExceptionTest extends AbstractExceptionTestCase
{
    public function testIsInvalidArgumentException(): void
    {
        $exception = new InvalidConnectionStateException('Test message');

        $this->assertInstanceOf(\InvalidArgumentException::class, $exception);
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
