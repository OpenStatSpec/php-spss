<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Record\Info\LongVariableNames;
use SPSS\Sav\Record\Info\MachineInteger;
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
        $longName = "\xC3\xB5\xC3\xA4\xC3\xB6\xC3\xBC\xC3\xA9_long_text";
        self::assertSame(640, strlen($longValue));

        $writer = new Writer([
            'header' => [
                'recType' => $recordType,
                'compression' => $compression,
            ],
            'info' => ['characterEncoding' => 'UTF-8'],
            'variables' => [
                [
                    'name' => $longName,
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

        $physicalVariable = $writer->variables[0];
        self::assertSame("\xC3\x95\xC3\x84", $physicalVariable->name);
        self::assertSame("\xC3\x95\xC3\x84_A", $physicalVariable->getSegmentName(0));
        self::assertSame("\xC3\x95\xC3\x84_B", $physicalVariable->getSegmentName(1));

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();

        self::assertSame(["\xC3\x95\xC3\x84" => 700], $reader->info[VeryLongString::SUBTYPE]->toArray());
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
        $longVariable = $dataset->variable($longName);
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

    #[DataProvider('compressionProvider')]
    public function testSingleByteEncodingInfoLengthsAndCharacterCodeRoundTrip(
        string $recordType,
        int $compression,
    ): void {
        $longName = "p\xC3\xB5ld_long_text";
        $longValue = str_repeat("\xC3\xA4", 300);
        $writer = new Writer([
            'header' => [
                'recType' => $recordType,
                'compression' => $compression,
            ],
            'info' => ['characterEncoding' => 'ISO-8859-1'],
            'variables' => [[
                'name' => $longName,
                'format' => Variable::FORMAT_TYPE_A,
                'width' => 300,
                'data' => [$longValue],
            ]],
        ]);

        $physicalName = "P\xC3\x95LD_";
        $physicalVariable = $writer->variables[0];
        self::assertSame($physicalName, $physicalVariable->name);
        self::assertSame($physicalName . '_A', $physicalVariable->getSegmentName(0));

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();

        $machineInteger = $reader->info[MachineInteger::SUBTYPE];
        self::assertInstanceOf(MachineInteger::class, $machineInteger);
        self::assertSame(28591, $machineInteger->characterCode);
        self::assertSame(
            [$physicalName => $longName],
            $reader->info[LongVariableNames::SUBTYPE]->toArray(),
        );
        self::assertSame(
            [$physicalName => 300],
            $reader->info[VeryLongString::SUBTYPE]->toArray(),
        );
        self::assertSame([[$longValue]], $reader->data);
    }

    /** @return iterable<string, array{string, int}> */
    public static function compressionProvider(): iterable
    {
        yield 'byte-compressed SAV' => [Header::NORMAL_REC_TYPE, 1];
        yield 'ZLIB-compressed ZSAV' => [Header::ZLIB_REC_TYPE, 2];
    }
}
