<?php

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Record\Info\LongVariableNames;
use SPSS\Sav\Record\Info\MachineInteger;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

class WriteMultibyteTest extends TestCase
{
    public function testMultiByteLabel(): void
    {
        $data = [
            'header' => [
                'prodName'     => '@(#) IBM SPSS STATISTICS',
                'layoutCode'   => 2,
                'creationDate' => date('d M y'),
                'creationTime' => date('H:i:s'),
            ],
            'variables' => [
                [
                    'name'   => 'longname_longerthanexpected',
                    'label'  => 'Data zákończenia',
                    'width'  => 16,
                    'format' => 1,
                ],
                [
                    'name'   => 'ccc',
                    'label'  => 'áá345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901233á',
                    'format' => 5,
                    'values' => [
                        1 => 'áá345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901234567890123456789012345678901233á',
                    ],
                ],
            ],
        ];
        $writer = new Writer($data);

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();

        // Short variable label
        $this->assertEquals($data['variables'][0]['label'], $reader->variables[0]->label);

        // Long variable label
        $this->assertEquals(
            mb_substr($data['variables'][1]['values'][1], 0, -2, 'UTF-8'),
            $reader->variables[1]->label,
        );

        // Long value label
        $this->assertEquals(
            mb_substr($data['variables'][1]['label'], 0, -2, 'UTF-8'),
            $reader->valueLabels[0]->labels[0]['label'],
        );
    }

    /**
     * ISSUE #20.
     * Chinese value labels seem to work fine, but free text does not work
     */
    public function testChinese(): void
    {
        $input = [
            'header' => [
                'prodName'     => '@(#) IBM SPSS STATISTICS 64-bit Macintosh 23.0.0.0',
                'creationDate' => '05 Oct 18',
                'creationTime' => '01:36:53',
                'weightIndex'  => 0,
            ],
            'variables' => [
                [
                    'name'     => 'test1',
                    'format'   => Variable::FORMAT_TYPE_F,
                    'width'    => 4,
                    'decimals' => 2,
                    'label'    => 'test',
                    'values'   => [
                        1 => '1测试中文标签1',
                        2 => '2测试中文标签2',
                    ],
                    'missing'    => [],
                    'columns'    => 5,
                    'alignment'  => Variable::ALIGN_RIGHT,
                    'measure'    => Variable::MEASURE_SCALE,
                    'attributes' => [
                        '$@Role' => Variable::ROLE_PARTITION,
                    ],
                    'data' => [1, 1, 1],
                ],
                [
                    'name'       => 'test2',
                    'format'     => Variable::FORMAT_TYPE_A,
                    'width'      => 100,
                    'label'      => 'test',
                    'columns'    => 100,
                    'alignment'  => Variable::ALIGN_LEFT,
                    'measure'    => Variable::MEASURE_NOMINAL,
                    'attributes' => [
                        '$@Role' => Variable::ROLE_SPLIT,
                    ],
                    'data' => [
                        '测试中文数据1',
                        '测试中文数据2',
                        '测试中文数据3',
                    ],
                ],
            ],
        ];

        $writer = new Writer($input);

        // Uncomment if you want to really save and check the resulting file in SPSS
        // $writer->save('chinese1.sav');

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();
        $expected = [];
        $expected[0][0] = $input['variables'][0]['data'][0];
        $expected[0][1] = $input['variables'][1]['data'][0];
        $expected[1][0] = $input['variables'][0]['data'][1];
        $expected[1][1] = $input['variables'][1]['data'][1];
        $expected[2][0] = $input['variables'][0]['data'][2];
        $expected[2][1] = $input['variables'][1]['data'][2];
        $this->assertEquals($expected, $reader->data);
    }

    public function testMultiByteVariableName(): void
    {
        $data = [
            'header' => [
                'prodName'     => '@(#) IBM SPSS STATISTICS',
                'layoutCode'   => 2,
                'creationDate' => date('d M y'),
                'creationTime' => date('H:i:s'),
            ],
            'variables' => [
                [
                    'name'   => 'Å',
                    'width'  => 16,
                    'format' => 1,
                ],
                [
                    'name'   => 'DSADÆØØÅÅÅÅÅSAAA',
                    'format' => 5,
                ],
            ],
        ];
        $writer = new Writer($data);

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();

        // Short variable name
        $this->assertEquals($data['variables'][0]['name'], $reader->info[LongVariableNames::SUBTYPE]['V00001']);
        // Long variable name
        $this->assertEquals($data['variables'][1]['name'], $reader->info[LongVariableNames::SUBTYPE]['V00002']);
    }

    public function testLabelsUseEncodedByteLengthForSingleByteTargetCharset(): void
    {
        $label = str_repeat("\xC3\xA4", 255);
        $data = [
            'header' => [
                'prodName'     => '@(#) IBM SPSS STATISTICS',
                'layoutCode'   => 2,
                'creationDate' => date('d M y'),
                'creationTime' => date('H:i:s'),
            ],
            'info' => [
                'characterEncoding' => 'ISO-8859-1',
            ],
            'variables' => [[
                'name'   => 'encoded',
                'label'  => $label,
                'format' => 5,
                'values' => [1 => $label],
                'data'   => [1],
            ]],
        ];

        $writer = new Writer($data);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();

        self::assertSame($label, $reader->variables[0]->label);
        self::assertSame($label, $reader->valueLabels[0]->labels[0]['label']);
    }

    public function testCompressedNonUtf8StringRoundTripsForSavAndZsav(): void
    {
        $value = "\xC3\xB5\xC3\xA4\xC3\xB6\xC3\xBC\xC3\xA9\xC3\xA0\xC3\xA7\xC3\x9F";
        foreach ([[Header::NORMAL_REC_TYPE, 1], [Header::ZLIB_REC_TYPE, 2]] as [$recordType, $compression]) {
            $writer = new Writer([
                'header' => [
                    'recType' => $recordType,
                    'compression' => $compression,
                ],
                'info' => [
                    'characterEncoding' => 'ISO-8859-1',
                ],
                'variables' => [[
                    'name' => 'encoded',
                    'format' => Variable::FORMAT_TYPE_A,
                    'width' => 8,
                    'data' => [$value],
                ]],
            ]);

            $buffer = $writer->getBuffer();
            $buffer->rewind();
            $reader = Reader::fromString($buffer->getStream())->read();

            self::assertSame([[$value]], $reader->data);
        }
    }

    public function testTargetByteLabelLengthUsesOnlySpacePadding(): void
    {
        $label = "ab\xC3\xA4";
        $record = new \SPSS\Sav\Record\Variable([
            'name' => 'PAD',
            'width' => 0,
            'label' => $label,
        ]);
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->charset = 'ISO-8859-1';

        $record->write($buffer);
        $buffer->seek(32);

        self::assertSame(3, $buffer->readInt());
        self::assertSame("ab\xE4 ", $buffer->read(4));
    }

    public function testStandardValueLabelUsesDeclaredTargetBytesThenSpaces(): void
    {
        $label = "ab\xC3\xA4";
        $record = new \SPSS\Sav\Record\ValueLabel([
            'labels' => [['value' => 1, 'label' => $label]],
            'indexes' => [0],
            'stringValues' => false,
        ]);
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->charset = 'ISO-8859-1';

        $record->write($buffer);
        $buffer->seek(16);

        $lengthByte = $buffer->read(1);
        self::assertIsString($lengthByte);
        self::assertSame(3, ord($lengthByte));
        self::assertSame("ab\xE4    ", $buffer->read(7));
    }

    #[DataProvider('encodingAliasProvider')]
    public function testEncodingAliasEmitsMatchingMachineCodeAndRoundTrips(
        string $encoding,
        int $characterCode,
    ): void {
        $value = "\xC3\xA4";
        $writer = new Writer([
            'header' => [
                'recType' => Header::NORMAL_REC_TYPE,
                'compression' => 1,
            ],
            'info' => ['characterEncoding' => $encoding],
            'variables' => [[
                'name' => 'encoded',
                'format' => Variable::FORMAT_TYPE_A,
                'width' => 8,
                'data' => [$value],
            ]],
        ]);

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();
        $machineInteger = $reader->info[MachineInteger::SUBTYPE];

        self::assertInstanceOf(MachineInteger::class, $machineInteger);
        self::assertSame($characterCode, $machineInteger->characterCode);
        self::assertSame([[$value]], $reader->data);
    }

    /** @return iterable<string, array{string, int}> */
    public static function encodingAliasProvider(): iterable
    {
        yield 'ISO standard spelling' => ['ISO8859-1', 28591];
        yield 'Latin-1 alias' => ['latin1', 28591];
        yield 'Windows code page alias' => ['CP1252', 1252];
        yield 'Windows compact spelling' => ['Windows1252', 1252];
    }
}
