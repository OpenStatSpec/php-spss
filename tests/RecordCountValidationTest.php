<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Record\Document;
use SPSS\Sav\Record\InfoCollection;
use SPSS\Sav\Record\ValueLabel;

final class RecordCountValidationTest extends TestCase
{
    #[DataProvider('invalidDocumentCounts')]
    public function testInvalidDocumentCountsAreRejected(int $count, string $message): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt($count);
        $buffer->rewind();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);
        Document::fill($buffer);
    }

    /** @return iterable<string, array{int, string}> */
    public static function invalidDocumentCounts(): iterable
    {
        yield 'negative' => [-1, 'negative line count'];
        yield 'larger than payload' => [2_147_483_647, 'payload can contain at most 0'];
    }

    public function testTruncatedDocumentCountIsRejected(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('missing line count');
        Document::fill(Buffer::factory("\x01\x00"));
    }

    #[DataProvider('invalidLabelCounts')]
    public function testInvalidValueLabelCountsAreRejected(int $count, string $message): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt($count);
        $buffer->rewind();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);
        ValueLabel::fill($buffer);
    }

    /** @return iterable<string, array{int, string}> */
    public static function invalidLabelCounts(): iterable
    {
        yield 'negative' => [-1, 'label count must be non-negative'];
        yield 'larger than payload' => [2_147_483_647, 'payload can contain at most 0'];
    }

    public function testValueLabelVariableCountCannotExceedPayload(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt(0);
        $buffer->writeInt(4);
        $buffer->writeInt(2_147_483_647);
        $buffer->rewind();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('declares 2147483647 variables');
        ValueLabel::fill($buffer);
    }

    #[DataProvider('invalidInfoSizeCounts')]
    public function testInvalidInfoSizeCountsAreRejected(int $size, int $count, string $message): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt(9999);
        $buffer->writeInt($size);
        $buffer->writeInt($count);
        $buffer->rewind();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage($message);
        new InfoCollection()->fill($buffer);
    }

    /** @return iterable<string, array{int, int, string}> */
    public static function invalidInfoSizeCounts(): iterable
    {
        yield 'negative size' => [-1, 1, 'size/count -1/1'];
        yield 'negative count' => [1, -1, 'size/count 1/-1'];
        yield 'zero size with elements' => [0, 1, 'size/count 0/1'];
        yield 'declared payload exceeds input' => [2_147_483_647, 2_147_483_647, 'declared payload'];
    }

    public function testTruncatedInfoCountIsRejected(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt(9999);
        $buffer->writeInt(1);
        $buffer->write("\x01\x00");
        $buffer->rewind();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('truncated size or count');
        new InfoCollection()->fill($buffer);
    }
}
