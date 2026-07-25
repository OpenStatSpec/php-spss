<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

final class MalformedContainerTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function truncatedRecordTypeProvider(): iterable
    {
        yield 'no record bytes' => [''];
        yield 'one record byte' => ["\x02"];
        yield 'two record bytes' => ["\x02\x00"];
        yield 'three record bytes' => ["\x02\x00\x00"];
    }

    #[DataProvider('truncatedRecordTypeProvider')]
    public function testMetadataBodyEofIsRejected(string $body): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unexpected end of SPSS metadata before the data record.');

        Reader::fromString($this->container($body))->readMetaData();
    }

    public function testUnknownMetadataRecordTypeIsRejected(): void
    {
        $body = Buffer::factory('', ['memory' => true]);
        $body->writeInt(42);
        $body->rewind();

        $bytes = $body->read();
        self::assertIsString($bytes);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Unsupported SPSS record type 42 in the metadata body.');

        Reader::fromString($this->container($bytes))->readMetaData();
    }

    public function testHugeHeaderCaseCountIsRejectedAgainstActualPayload(): void
    {
        $bytes = $this->savBytes(0);
        $bytes = substr_replace($bytes, pack('i', 2_147_483_647), 80, 4);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('header declares 2147483647 cases');
        Reader::fromString($bytes)->read();
    }

    public function testTruncatedUncompressedCaseDoesNotReturnFalseData(): void
    {
        $bytes = substr($this->savBytes(0), 0, -4);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('payload can contain at most 0');
        Reader::fromString($bytes)->read();
    }

    public function testTruncatedCompressedOpcodeClusterIsRejected(): void
    {
        $bytes = $this->savBytes(1);
        $metadata = Reader::fromString($bytes)->readMetaData();
        $bytes = substr($bytes, 0, $metadata->dataPosition + 8);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('truncated compressed opcode cluster');
        Reader::fromString($bytes)->read();
    }

    private function savBytes(int $compression): string
    {
        $writer = new Writer([
            'header' => ['compression' => $compression],
            'variables' => [
                [
                    'name' => 'number',
                    'format' => Variable::FORMAT_TYPE_F,
                    'width' => 8,
                    'data' => [1],
                ],
            ],
        ]);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $bytes = $buffer->read();
        self::assertIsString($bytes);

        return $bytes;
    }

    private function container(string $body): string
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        new Header()->write($buffer);
        $buffer->write($body);
        $buffer->rewind();

        $bytes = $buffer->read();
        self::assertIsString($bytes);

        return $bytes;
    }
}
