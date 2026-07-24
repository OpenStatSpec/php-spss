<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Sav\Reader;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

final class ValueLabelRoundTripTest extends TestCase
{
    public function testNumericAndShortStringValueTypesAndIndexesSurviveRoundTrip(): void
    {
        $writer = new Writer([
            'header' => ['fileLabel' => 'value labels'],
            'variables' => [
                [
                    'name' => 'number',
                    'width' => 8,
                    'format' => Variable::FORMAT_TYPE_F,
                    'values' => [1 => 'One', 2 => 'Two'],
                    'data' => [1, 2],
                ],
                [
                    'name' => 'text',
                    'width' => 8,
                    'format' => Variable::FORMAT_TYPE_A,
                    'values' => ['A' => 'Alpha', 'B' => 'Beta'],
                    'data' => ['A', 'B'],
                ],
            ],
        ]);

        $buffer = $writer->getBuffer();
        $buffer->rewind();
        $reader = Reader::fromString($buffer->getStream())->read();

        $this->assertCount(2, $reader->valueLabels);
        $this->assertSame([0], $reader->valueLabels[0]->indexes);
        $this->assertSame([1], $reader->valueLabels[1]->indexes);
        $this->assertFalse($reader->valueLabels[0]->stringValues);
        $this->assertTrue($reader->valueLabels[1]->stringValues);
        $this->assertSame([
            ['value' => 1.0, 'label' => 'One'],
            ['value' => 2.0, 'label' => 'Two'],
        ], $reader->valueLabels[0]->labels);
        $this->assertSame([
            ['value' => 'A', 'label' => 'Alpha'],
            ['value' => 'B', 'label' => 'Beta'],
        ], $reader->valueLabels[1]->labels);
        $this->assertSame([[1.0, 'A'], [2.0, 'B']], $reader->data);
    }
}
