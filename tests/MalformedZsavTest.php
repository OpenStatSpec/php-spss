<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Exception;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

final class MalformedZsavTest extends TestCase
{
    public function testTruncatedZlibHeaderIsRejected(): void
    {
        $bytes = $this->createZsav();
        $layout = $this->layout($bytes);
        $bytes = substr($bytes, 0, $layout['headerOffset'] + 16);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('truncated ZLIB header');
        Reader::fromString($bytes)->read();
    }

    public function testTruncatedDescriptorTrailerIsRejected(): void
    {
        $bytes = substr($this->createZsav(), 0, -1);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('trailer offsets or length');
        Reader::fromString($bytes)->read();
    }

    public function testOverflowingTrailerOffsetIsRejected(): void
    {
        $bytes = $this->createZsav();
        $layout = $this->layout($bytes);
        $bytes = substr_replace($bytes, pack('q', PHP_INT_MAX), $layout['headerOffset'] + 8, 8);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('trailer offsets or length');
        Reader::fromString($bytes)->read();
    }

    public function testOverflowingTrailerLengthIsRejected(): void
    {
        $bytes = $this->createZsav();
        $layout = $this->layout($bytes);
        $bytes = substr_replace($bytes, pack('q', PHP_INT_MAX), $layout['headerOffset'] + 16, 8);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('trailer offsets or length');
        Reader::fromString($bytes)->read();
    }

    public function testDescriptorUncompressedOffsetMustBeContiguous(): void
    {
        $bytes = $this->createZsav();
        $layout = $this->layout($bytes);
        $bytes = substr_replace($bytes, pack('q', $layout['headerOffset'] + 1), $layout['trailerOffset'] + 24, 8);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('inconsistent ZLIB block descriptor');
        Reader::fromString($bytes)->read();
    }

    public function testDescriptorCompressedOffsetMustBeContiguous(): void
    {
        $bytes = $this->createZsav();
        $layout = $this->layout($bytes);
        $compressedOffset = $this->int64At($bytes, $layout['trailerOffset'] + 32);
        $bytes = substr_replace($bytes, pack('q', $compressedOffset + 1), $layout['trailerOffset'] + 32, 8);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('inconsistent ZLIB block descriptor');
        Reader::fromString($bytes)->read();
    }

    public function testDescriptorRejectsZeroUncompressedSize(): void
    {
        $bytes = $this->createZsav();
        $layout = $this->layout($bytes);
        $bytes = substr_replace($bytes, pack('i', 0), $layout['trailerOffset'] + 40, 4);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('inconsistent ZLIB block descriptor');
        Reader::fromString($bytes)->read();
    }

    public function testDescriptorRejectsCompressedSizeBeyondTrailer(): void
    {
        $bytes = $this->createZsav();
        $layout = $this->layout($bytes);
        $bytes = substr_replace($bytes, pack('i', 2_147_483_647), $layout['trailerOffset'] + 44, 4);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('inconsistent ZLIB block descriptor');
        Reader::fromString($bytes)->read();
    }

    public function testTrailerBlockCountMustMatchDescriptors(): void
    {
        $bytes = $this->createZsav();
        $layout = $this->layout($bytes);
        $bytes = substr_replace($bytes, pack('i', 2), $layout['trailerOffset'] + 20, 4);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('inconsistent ZLIB trailer');
        Reader::fromString($bytes)->read();
    }

    private function createZsav(): string
    {
        $writer = new Writer([
            'header' => [
                'recType' => Header::ZLIB_REC_TYPE,
                'compression' => 2,
            ],
            'variables' => [
                [
                    'name' => 'number',
                    'format' => Variable::FORMAT_TYPE_F,
                    'width' => 8,
                    'data' => [1, 2, 3],
                ],
            ],
        ]);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $bytes = $buffer->read();
        self::assertIsString($bytes);

        return $bytes;
    }

    /** @return array{headerOffset: int, trailerOffset: int} */
    private function layout(string $bytes): array
    {
        $reader = Reader::fromString($bytes)->readMetaData();
        $headerOffset = $reader->dataPosition + 4;

        return [
            'headerOffset' => $headerOffset,
            'trailerOffset' => $this->int64At($bytes, $headerOffset + 8),
        ];
    }

    private function int64At(string $bytes, int $offset): int
    {
        $value = unpack('qvalue', substr($bytes, $offset, 8));
        self::assertIsArray($value);

        return $value['value'];
    }
}
