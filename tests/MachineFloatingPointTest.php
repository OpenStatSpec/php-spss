<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Sav\Record\Info\MachineFloatingPoint;

class MachineFloatingPointTest extends TestCase
{
    /**
     * @return list<array{0: array{sysmis?: int|float, highest?: int|float, lowest?: int|float}, 1: array{sysmis: int|float, highest: int|float, lowest: int|float}}>
     */
    public static function provider(): array
    {
        return [
            [
                [
                    'sysmis'  => -1,
                    'highest' => 5,
                    'lowest'  => -10,
                ],
                [
                    'sysmis'  => -1,
                    'highest' => 5,
                    'lowest'  => -10,
                ],
            ],
            [
                [],
                // -1.7976931348623E+308 php min double -PHP_FLOAT_MAX
                //  1.7976931348623E+308 php max double  PHP_FLOAT_MAX
                [
                    'sysmis'  => -PHP_FLOAT_MAX,
                    'highest' =>  PHP_FLOAT_MAX,
                    'lowest'  => -PHP_FLOAT_MAX,
                ],
            ],
        ];
    }

    /**
     * @param array{sysmis?: int|float, highest?: int|float, lowest?: int|float} $attributes
     * @param array{sysmis: int|float, highest: int|float, lowest: int|float} $expected
     */
    #[DataProvider('provider')]
    public function testWriteRead(array $attributes, array $expected): void
    {
        $subject = new MachineFloatingPoint($attributes);

        $buffer = Buffer::factory('', ['memory' => true]);
        $this->assertEquals(0, $buffer->position());
        $subject->write($buffer);
        $buffer->rewind();
        $buffer->skip(8);

        $read = MachineFloatingPoint::fill($buffer);
        $this->assertEquals(40, $buffer->position());
        $actual = [
            'sysmis' => $read->sysmis,
            'highest' => $read->highest,
            'lowest' => $read->lowest,
        ];

        $this->assertEquals($expected, $actual);
    }
}
