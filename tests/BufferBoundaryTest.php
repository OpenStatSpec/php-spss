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
        } catch (\InvalidArgumentException $invalidArgumentException) {
            self::assertSame('Read length cannot be negative.', $invalidArgumentException->getMessage());
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

    public function testFactoryHonoursMemoryOption(): void
    {
        $temporary = Buffer::factory('abc');
        $memory = Buffer::factory('abc', ['memory' => true]);

        self::assertSame('php://temp', $temporary->getMetaData()['uri']);
        self::assertSame('php://memory', $memory->getMetaData()['uri']);
    }

    public function testZeroLengthAllocationIsValidAndDoesNotMoveTheBuffer(): void
    {
        $buffer = Buffer::factory('abc');
        $allocated = $buffer->allocate(0);

        self::assertSame(0, $allocated->remaining());
        self::assertSame(0, $buffer->position());
    }

    public function testWriteStreamHonoursMaximumLength(): void
    {
        $source = fopen('php://memory', 'rb+');
        self::assertIsResource($source);
        fwrite($source, 'abcd');
        rewind($source);

        $buffer = Buffer::factory('', ['memory' => true]);
        self::assertSame(2, $buffer->writeStream($source, 2));
        self::assertSame(2, $buffer->position());
        $buffer->rewind();
        self::assertSame('ab', $buffer->read());
    }

    public function testReadStringConvertsFromBufferCharsetToExplicitTargetCharset(): void
    {
        $buffer = Buffer::factory("\xC3\xA4");
        $buffer->charset = 'UTF-8';

        self::assertSame("\xE4", $buffer->readString(2, charset: 'ISO-8859-1'));
    }

    public function testWriteStringUsesExplicitSourceCharset(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->charset = 'UTF-8';

        self::assertSame(2, $buffer->writeString("\xE4", '*', 'ISO-8859-1'));
        $buffer->rewind();
        self::assertSame("\xC3\xA4", $buffer->read(2));
    }

    public function testFixedWidthStringTruncatesOnEncodedCharacterBoundaryAndPadsToExactWidth(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->charset = 'UTF-8';

        self::assertSame(3, $buffer->writeString("\u{00E4}\u{00E4}", 3));
        self::assertSame(2, $buffer->encodedStringLength("\u{00E4}\u{00E4}", 3));
        self::assertSame(3, $buffer->position());
        $buffer->rewind();
        self::assertSame("\xC3\xA4 ", $buffer->read(3));
    }

    public function testWriteStringAcceptsVeryLongLeadingZeroNumericStringLength(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $length = str_repeat('0', 1024) . '8';

        self::assertSame(8, $buffer->writeString('value', $length));
        $buffer->rewind();
        self::assertSame('value   ', $buffer->read(8));
    }

    public function testWriteStringHandlesUnboundedAndAllZeroLengths(): void
    {
        $unbounded = Buffer::factory('', ['memory' => true]);
        self::assertSame(5, $unbounded->writeString('value'));
        self::assertSame(5, $unbounded->position());
        $unbounded->rewind();
        self::assertSame('value', $unbounded->read());

        $zero = Buffer::factory('', ['memory' => true]);
        self::assertSame(0, $zero->writeString('ignored', '0000'));
        self::assertSame(0, $zero->position());
        $zero->rewind();
        self::assertSame('', $zero->read());
    }

    public function testWriteStringRejectsFractionalNumericStringLength(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-negative integer');
        $buffer->writeString('value', '8.0');
    }

    public function testWriteStringRejectsNegativeLengthWithoutMoving(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);

        try {
            $buffer->writeString('value', -1);
            self::fail('A negative string length must be rejected.');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            self::assertSame('String length cannot be negative.', $invalidArgumentException->getMessage());
        }

        self::assertSame(0, $buffer->position());
    }

    public function testWriteStringRejectsOverflowingNumericStringWithoutMoving(): void
    {
        $maximum = (string) PHP_INT_MAX;
        $lastDigit = (int) $maximum[-1];
        self::assertLessThan(9, $lastDigit);
        $sameLengthOverflow = substr($maximum, 0, -1) . ($lastDigit + 1);

        foreach ([PHP_INT_MAX . '0', $sameLengthOverflow] as $overflow) {
            $buffer = Buffer::factory('', ['memory' => true]);
            try {
                $buffer->writeString('value', $overflow);
                self::fail('An overflowing string length must be rejected.');
            } catch (\InvalidArgumentException $invalidArgumentException) {
                self::assertSame(
                    'String length exceeds the platform integer range.',
                    $invalidArgumentException->getMessage(),
                );
            }

            self::assertSame(0, $buffer->position());
        }
    }

    public function testEncodedStringLengthRejectsNegativeMaximum(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Maximum string length cannot be negative.');
        $buffer->encodedStringLength('value', -1);
    }

    public function testEncodeStringRejectsNegativeMaximum(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);

        try {
            $buffer->encodeString('value', -1);
            self::fail('A negative maximum string length must be rejected.');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            self::assertSame(
                'Maximum string length cannot be negative.',
                $invalidArgumentException->getMessage(),
            );
        }
    }
}
