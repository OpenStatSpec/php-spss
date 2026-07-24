<?php

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Sav\Reader;
use SPSS\Sav\Record;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

class SavBinaryLayerTest extends TestCase
{
    public function testUncompressedNumericAndStringRoundTrip(): void
    {
        $reader = $this->roundTrip([
            'header' => ['compression' => 0],
            'variables' => [
                [
                    'name' => 'number',
                    'format' => Variable::FORMAT_TYPE_F,
                    'width' => 8,
                    'data' => [1.5, 2],
                ],
                [
                    'name' => 'text',
                    'format' => Variable::FORMAT_TYPE_A,
                    'width' => 8,
                    'data' => ['abc', 'def'],
                ],
            ],
        ]);

        $this->assertSame([
            [1.5, 'abc'],
            [2.0, 'def'],
        ], $reader->data);
    }

    public function testShortStringUtf8MissingValueRoundTrip(): void
    {
        $reader = $this->roundTrip([
            'header' => ['compression' => 1],
            'info' => ['characterEncoding' => 'UTF-8'],
            'variables' => [
                [
                    'name' => 'short',
                    'format' => Variable::FORMAT_TYPE_A,
                    'width' => 8,
                    'missing' => ['ÕNN'],
                    'data' => ['ÕNN', ''],
                ],
            ],
        ]);

        $this->assertSame(1, $reader->variables[0]->missingValuesFormat);
        $this->assertSame(['ÕNN'], $reader->variables[0]->missingValues);
        $this->assertSame([['ÕNN'], ['']], $reader->data);
    }

    public function testLongStringUtf8MissingAndValueLabelRoundTrip(): void
    {
        $reader = $this->roundTrip([
            'header' => ['compression' => 1],
            'info' => ['characterEncoding' => 'UTF-8'],
            'variables' => [
                [
                    'name' => 'põld_long',
                    'format' => Variable::FORMAT_TYPE_A,
                    'width' => 9,
                    'missing' => ['ÕNN'],
                    'values' => ['õ' => 'Tähe'],
                    'data' => ['õ'],
                ],
            ],
        ]);

        $valueLabels = $reader->info[Record\Info\LongStringValueLabels::SUBTYPE]->toArray();
        $missingValues = $reader->info[Record\Info\LongStringMissingValues::SUBTYPE]->toArray();

        $this->assertSame(
            [
                'põld_long' => [
                    'width' => 9,
                    'values' => ['õ' => 'Tähe'],
                ],
            ],
            $valueLabels,
        );
        $this->assertSame(
            [$reader->variables[0]->name => ['ÕNN']],
            $missingValues,
        );
        $this->assertSame([['õ']], $reader->data);
    }

    /**
     * @return iterable<string, array{int, int, list<string>}>
     */
    public static function invalidMissingValueRecords(): iterable
    {
        yield 'unsupported format' => [8, 4, ['A', 'B', 'C', 'D']];
        yield 'value count mismatch' => [8, 3, ['A', 'B']];
        yield 'string range' => [8, -2, ['A', 'B']];
    }

    /**
     * @param list<string> $missingValues
     */
    #[DataProvider('invalidMissingValueRecords')]
    public function testInvalidVariableMissingValueRecordsAreRejected(
        int $width,
        int $format,
        array $missingValues,
    ): void {
        $variable = new Record\Variable([
            'width' => $width,
            'missingValuesFormat' => $format,
            'missingValues' => $missingValues,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $variable->write(Buffer::factory('', ['memory' => true]));
    }

    public function testAllocatedBufferInheritsBinaryContext(): void
    {
        $buffer = Buffer::factory('12345678');
        $buffer->charset = 'windows-1257';
        $buffer->isBigEndian = true;

        $allocated = $buffer->allocate(8);

        $this->assertSame('windows-1257', $allocated->charset);
        $this->assertTrue($allocated->isBigEndian);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function roundTrip(array $data): Reader
    {
        $writer = new Writer($data);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        return Reader::fromString($buffer->getStream())->read();
    }
}
