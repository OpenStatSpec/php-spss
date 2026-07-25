<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Record\Info\VariableDisplayParam;
use SPSS\Sav\Record\Info\VeryLongString;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

final class VeryLongStringWriterTest extends TestCase
{
    #[DataProvider('compressionProvider')]
    public function testWriterEmitsDictionaryEntriesForEveryPhysicalSegment(
        string $recordType,
        int $compression,
    ): void {
        $longValue = str_repeat("\xC3\x95", 320);
        self::assertSame(640, strlen($longValue));

        $writer = new Writer([
            'header' => [
                'recType' => $recordType,
                'compression' => $compression,
            ],
            'info' => ['characterEncoding' => 'UTF-8'],
            'variables' => [
                [
                    'name' => 'long_text',
                    'format' => Variable::FORMAT_TYPE_A,
                    'width' => 700,
                    'measure' => Variable::MEASURE_ORDINAL,
                    'columns' => 37,
                    'alignment' => Variable::ALIGN_CENTER,
                    'data' => [$longValue, ''],
                ],
                [
                    'name' => 'short_text',
                    'format' => Variable::FORMAT_TYPE_A,
                    'width' => 8,
                    'measure' => Variable::MEASURE_NOMINAL,
                    'columns' => 11,
                    'alignment' => Variable::ALIGN_LEFT,
                    'data' => ['short', 'text'],
                ],
            ],
        ]);

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();

        self::assertSame(['LONG_' => 700], $reader->info[VeryLongString::SUBTYPE]->toArray());
        self::assertSame([
            [Variable::MEASURE_ORDINAL, 37, Variable::ALIGN_CENTER],
            [Variable::MEASURE_ORDINAL, 37, Variable::ALIGN_CENTER],
            [Variable::MEASURE_ORDINAL, 37, Variable::ALIGN_CENTER],
            [Variable::MEASURE_NOMINAL, 11, Variable::ALIGN_LEFT],
        ], $reader->info[VariableDisplayParam::SUBTYPE]->toArray());
        self::assertSame([
            [$longValue, 'short'],
            ['', 'text'],
        ], $reader->data);

        $buffer->rewind();
        $dataset = Reader::fromString($buffer->getStream())->readDataset();
        $longVariable = $dataset->variable('long_text');
        $shortVariable = $dataset->variable('short_text');

        self::assertNotNull($longVariable);
        self::assertNotNull($shortVariable);
        self::assertSame(700, $longVariable->width);
        self::assertSame(37, $longVariable->columns);
        self::assertSame(11, $shortVariable->columns);
        self::assertSame([
            [$longValue, 'short'],
            ['', 'text'],
        ], $dataset->rows());
    }

    /** @return iterable<string, array{string, int}> */
    public static function compressionProvider(): iterable
    {
        yield 'byte-compressed SAV' => [Header::NORMAL_REC_TYPE, 1];
        yield 'ZLIB-compressed ZSAV' => [Header::ZLIB_REC_TYPE, 2];
    }
}
