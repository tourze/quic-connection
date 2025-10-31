<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Exception;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitBase\AbstractExceptionTestCase;
use Tourze\QUIC\Connection\Exception\QuicConnectionException;

/**
 * @internal
 */
#[CoversClass(QuicConnectionException::class)]
final class QuicConnectionExceptionTest extends AbstractExceptionTestCase
{
    public function testIsRuntimeException(): void
    {
        $exception = new QuicConnectionException('Test message');

        $this->assertInstanceOf(\RuntimeException::class, $exception);
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
