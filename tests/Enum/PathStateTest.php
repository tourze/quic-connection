<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Enum;

use PHPUnit\Framework\Attributes\CoversClass;
use Tourze\PHPUnitEnum\AbstractEnumTestCase;
use Tourze\QUIC\Connection\Enum\PathState;

/**
 * @internal
 */
#[CoversClass(PathState::class)]
final class PathStateTest extends AbstractEnumTestCase
{
    public function testEnumCases(): void
    {
        $this->assertEquals('unvalidated', PathState::UNVALIDATED->value);
        $this->assertEquals('probing', PathState::PROBING->value);
        $this->assertEquals('validated', PathState::VALIDATED->value);
        $this->assertEquals('failed', PathState::FAILED->value);
    }

    public function testEnumValues(): void
    {
        $cases = PathState::cases();

        $this->assertCount(4, $cases);
        $this->assertContains(PathState::UNVALIDATED, $cases);
        $this->assertContains(PathState::PROBING, $cases);
        $this->assertContains(PathState::VALIDATED, $cases);
        $this->assertContains(PathState::FAILED, $cases);
    }

    public function testFromValue(): void
    {
        $this->assertEquals(PathState::UNVALIDATED, PathState::from('unvalidated'));
        $this->assertEquals(PathState::PROBING, PathState::from('probing'));
        $this->assertEquals(PathState::VALIDATED, PathState::from('validated'));
        $this->assertEquals(PathState::FAILED, PathState::from('failed'));
    }

    public function testCanSendData(): void
    {
        $this->assertFalse(PathState::UNVALIDATED->canSendData());
        $this->assertFalse(PathState::PROBING->canSendData());
        $this->assertTrue(PathState::VALIDATED->canSendData());
        $this->assertFalse(PathState::FAILED->canSendData());
    }

    public function testToArray(): void
    {
        $instance = PathState::VALIDATED;
        $array = $instance->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('value', $array);
        $this->assertArrayHasKey('label', $array);

        $this->assertEquals('validated', $array['value']);
        $this->assertEquals('已验证', $array['label']);
    }
}
