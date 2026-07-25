<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;

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
