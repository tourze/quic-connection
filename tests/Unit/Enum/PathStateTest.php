<?php

declare(strict_types=1);

namespace Tourze\QUIC\Connection\Tests\Unit\Enum;

use PHPUnit\Framework\TestCase;
use Tourze\QUIC\Connection\Enum\PathState;

/**
 * @covers \Tourze\QUIC\Connection\Enum\PathState
 */
class PathStateTest extends TestCase
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
}