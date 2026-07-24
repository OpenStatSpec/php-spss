<?php

namespace SPSS\Tests;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

class ZsavTest extends TestCase
{
    public function testSignedInt64RoundTripHonorsEndianness(): void
    {
        foreach ([false, true] as $isBigEndian) {
            $buffer = Buffer::factory('', ['memory' => true]);
            $buffer->isBigEndian = $isBigEndian;

            foreach ([-100, 0, 123456789012345] as $value) {
                $buffer->writeInt64($value);
            }

            $buffer->rewind();
            foreach ([-100, 0, 123456789012345] as $value) {
                $this->assertSame($value, $buffer->readInt64());
            }
        }
    }

    public function testSingleBlockRoundTrip(): void
    {
        $bytes = $this->createZsav([
            [
                'name' => 'number',
                'format' => Variable::FORMAT_TYPE_F,
                'width' => 8,
                'data' => [1, 2.5, 999],
            ],
            [
                'name' => 'text',
                'format' => Variable::FORMAT_TYPE_A,
                'width' => 8,
                'data' => ['abc', '', 'õ'],
            ],
        ]);

        $layout = $this->zlibLayout($bytes);
        $reader = Reader::fromString($bytes)->read();

        $this->assertSame(1, $layout['blockCount']);
        $this->assertSame([
            [1.0, 'abc'],
            [2.5, ''],
            [999.0, 'õ'],
        ], $reader->data);
    }

    public function testMultipleBlockRoundTrip(): void
    {
        $value = substr(str_repeat('0123456789abcdef', 2048), 0, 32767);
        $values = array_fill(0, 129, $value);
        $bytes = $this->createZsav([
            [
                'name' => 'very_long',
                'format' => Variable::FORMAT_TYPE_A,
                'width' => 32767,
                'data' => $values,
            ],
        ]);

        $layout = $this->zlibLayout($bytes);
        $reader = Reader::fromString($bytes)->read();

        $this->assertGreaterThan(1, $layout['blockCount']);
        $this->assertCount(129, $reader->data);
        $this->assertSame($value, $reader->data[0][0]);
        $this->assertSame($value, $reader->data[128][0]);
    }

    public function testReadCaseAndRewindUseDecompressedBytecodeStream(): void
    {
        $bytes = $this->createZsav([
            [
                'name' => 'number',
                'format' => Variable::FORMAT_TYPE_F,
                'width' => 8,
                'data' => [10, 20, 30],
            ],
        ]);

        $reader = Reader::fromString($bytes)->readMetaData();

        $this->assertTrue($reader->readCase());
        $this->assertSame([10.0], $reader->getCase());
        $this->assertTrue($reader->readCase());
        $this->assertSame([20.0], $reader->getCase());

        $this->assertTrue($reader->rewindCaseIterator());
        $this->assertTrue($reader->readCase());
        $this->assertSame([10.0], $reader->getCase());
    }

    public function testCorruptHeaderOffsetIsRejected(): void
    {
        $bytes = $this->createSmallZsav();
        $layout = $this->zlibLayout($bytes);
        $bytes = substr_replace(
            $bytes,
            pack('q', $layout['headerOffset'] + 1),
            $layout['headerOffset'],
            8,
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('header offset');
        Reader::fromString($bytes)->read();
    }

    public function testCorruptChecksumIsRejected(): void
    {
        $bytes = $this->createSmallZsav();
        $layout = $this->zlibLayout($bytes);
        $checksumOffset = $layout['compressedOffset'] + $layout['compressedSize'] - 1;
        $bytes[$checksumOffset] = \chr(\ord($bytes[$checksumOffset]) ^ 0xff);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('checksum');
        Reader::fromString($bytes)->read();
    }

    public function testCorruptDescriptorSizeIsRejected(): void
    {
        $bytes = $this->createSmallZsav();
        $layout = $this->zlibLayout($bytes);
        $sizeOffset = $layout['trailerOffset'] + 40;
        $bytes = substr_replace(
            $bytes,
            pack('i', $layout['uncompressedSize'] + 1),
            $sizeOffset,
            4,
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('size mismatch');
        Reader::fromString($bytes)->read();
    }

    /**
     * @param list<array<string, mixed>> $variables
     */
    private function createZsav(array $variables): string
    {
        $writer = new Writer([
            'header' => [
                'recType' => Header::ZLIB_REC_TYPE,
                'compression' => 2,
            ],
            'variables' => $variables,
        ]);
        $stream = $writer->getBuffer()->getStream();
        $streamInfo = fstat($stream);
        $this->assertNotFalse($streamInfo);

        $bytes = stream_get_contents($stream, $streamInfo['size'], 0);
        $this->assertIsString($bytes);

        return $bytes;
    }

    private function createSmallZsav(): string
    {
        return $this->createZsav([
            [
                'name' => 'number',
                'format' => Variable::FORMAT_TYPE_F,
                'width' => 8,
                'data' => [1, 2, 3],
            ],
        ]);
    }

    /**
     * @return array{
     *     headerOffset: int,
     *     trailerOffset: int,
     *     blockCount: int,
     *     compressedOffset: int,
     *     uncompressedSize: int,
     *     compressedSize: int
     * }
     */
    private function zlibLayout(string $bytes): array
    {
        $metadata = Reader::fromString($bytes)->readMetaData();
        $headerOffset = $metadata->dataPosition + 4;
        $trailerOffset = $this->int64At($bytes, $headerOffset + 8);
        $blockCount = $this->int32At($bytes, $trailerOffset + 20);

        return [
            'headerOffset' => $headerOffset,
            'trailerOffset' => $trailerOffset,
            'blockCount' => $blockCount,
            'compressedOffset' => $this->int64At($bytes, $trailerOffset + 32),
            'uncompressedSize' => $this->int32At($bytes, $trailerOffset + 40),
            'compressedSize' => $this->int32At($bytes, $trailerOffset + 44),
        ];
    }

    private function int64At(string $bytes, int $offset): int
    {
        $value = unpack('qvalue', substr($bytes, $offset, 8));

        $this->assertIsArray($value);

        return $value['value'];
    }

    private function int32At(string $bytes, int $offset): int
    {
        $value = unpack('ivalue', substr($bytes, $offset, 4));

        $this->assertIsArray($value);

        return $value['value'];
    }
}
