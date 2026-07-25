<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Buffer;
use SPSS\Exception;

final class BufferBoundaryTest extends TestCase
{
    public function testExactAndUnboundedReadsTrackActualBytes(): void
    {
        $buffer = Buffer::factory('abcd');

        self::assertSame('ab', $buffer->read(2));
        self::assertSame(2, $buffer->position());
        self::assertSame('cd', $buffer->read());
        self::assertSame(4, $buffer->position());
        self::assertSame('', $buffer->read());
        self::assertSame(4, $buffer->position());
    }

    public function testPartialAndEofReadsFailWithoutOverAdvancing(): void
    {
        $buffer = Buffer::factory('abc');

        self::assertFalse($buffer->read(5));
        self::assertSame(3, $buffer->position());
        self::assertFalse($buffer->read(1));
        self::assertSame(3, $buffer->position());
    }

    public function testPartialStringAndNumericReadsFailStrictly(): void
    {
        $stringBuffer = Buffer::factory('abc');
        self::assertFalse($stringBuffer->readString(4));
        self::assertSame(3, $stringBuffer->position());

        $numericBuffer = Buffer::factory("\x01\x02");
        self::assertFalse($numericBuffer->readInt());
        self::assertSame(2, $numericBuffer->position());
    }

    public function testRoundedStringRequiresAllPaddingBytes(): void
    {
        $truncated = Buffer::factory('abc');
        self::assertFalse($truncated->readString(3, 4));
        self::assertSame(3, $truncated->position());

        $complete = Buffer::factory("abc\0");
        self::assertSame('abc', $complete->readString(3, 4));
        self::assertSame(4, $complete->position());
    }

    public function testNegativeReadLengthIsRejectedWithoutMoving(): void
    {
        $buffer = Buffer::factory('abc');

        try {
            $buffer->read(-1);
            self::fail('A negative read length must be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('Read length cannot be negative.', $exception->getMessage());
        }

        self::assertSame(0, $buffer->position());
    }

    public function testTruncatedAllocationFailsAtTheActualPosition(): void
    {
        $buffer = Buffer::factory('abc');

        try {
            $buffer->allocate(5);
            self::fail('A truncated allocation must be rejected.');
        } catch (Exception $exception) {
            self::assertStringContainsString('only 3 bytes are available', $exception->getMessage());
        }

        self::assertSame(3, $buffer->position());
    }
}
