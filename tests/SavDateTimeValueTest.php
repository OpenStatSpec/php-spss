<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;
use SPSS\Utils;

class SavDateTimeValueTest extends TestCase
{
    /** @return list<array{0: int, 1: string}> */
    public static function invalidValueProvider(): array
    {
        return [
            [Variable::FORMAT_TYPE_DATE, '31-Feb-2024'],
            [Variable::FORMAT_TYPE_DATE, '1970-01-01'],
            [Variable::FORMAT_TYPE_DATE, '01-Abc-1970'],
            [Variable::FORMAT_TYPE_TIME, '12:60'],
            [Variable::FORMAT_TYPE_TIME, '12:30:60'],
            [Variable::FORMAT_TYPE_TIME, str_repeat('9', 400) . ':00'],
            [Variable::FORMAT_TYPE_TIME, '1' . str_repeat('0', 305) . ':00'],
            [Variable::FORMAT_TYPE_DATETIME, '01-Jan-1970 24:00'],
            [Variable::FORMAT_TYPE_DATETIME, '01-Jan-1970 1:00'],
            [Variable::FORMAT_TYPE_DATETIME, '01-Jan-1970'],
            [999, '01-Jan-1970'],
        ];
    }

    /** @return iterable<string, array{float, int}> */
    public static function nonIntegralBiasOpcodeProvider(): iterable
    {
        yield 'lower opcode bound' => [-99.5, 1];
        yield 'upper opcode bound' => [150.5, 251];
        yield 'below opcode bound' => [-100.5, 253];
        yield 'above opcode bound' => [151.5, 253];
        yield 'non-representable fraction' => [1.25, 253];
        yield 'integer with fractional bias' => [1.0, 253];
        yield 'positive infinity' => [INF, 253];
        yield 'negative infinity' => [-INF, 253];
        yield 'not a number' => [NAN, 253];
    }

    public function testExplicitParsingProducesNumericBinaryRoundTrip(): void
    {
        $data = $this->writerData([
            [
                'name' => 'DATE_VALUE',
                'format' => Variable::FORMAT_TYPE_DATE,
                'width' => 11,
                'data' => [
                    Utils::parseSpssDateTime('14-Oct-1582', Variable::FORMAT_TYPE_DATE),
                    Utils::parseSpssDateTime('01-Jan-1970', Variable::FORMAT_TYPE_DATE),
                    123.5,
                ],
            ],
            [
                'name' => 'TIME_VALUE',
                'format' => Variable::FORMAT_TYPE_TIME,
                'width' => 12,
                'decimals' => 2,
                'data' => [
                    Utils::parseSpssDateTime('00:00', Variable::FORMAT_TYPE_TIME),
                    Utils::parseSpssDateTime('59:59:59.25', Variable::FORMAT_TYPE_TIME),
                    1.0,
                ],
            ],
            [
                'name' => 'DATETIME_VALUE',
                'format' => Variable::FORMAT_TYPE_DATETIME,
                'width' => 24,
                'decimals' => 3,
                'data' => [
                    Utils::parseSpssDateTime('14-Oct-1582 00:00', Variable::FORMAT_TYPE_DATETIME),
                    Utils::parseSpssDateTime('01-Jan-1970 01:02:03.5', Variable::FORMAT_TYPE_DATETIME),
                    -86400.0,
                ],
            ],
        ]);

        $writer = new Writer($data);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $actual = Reader::fromString($buffer->getStream())->read();

        self::assertSame([
            [0.0, 0.0, 0.0],
            [12219379200.0, 215999.25, 12219382923.5],
            [123.5, 1.0, -86400.0],
        ], $actual->data);
    }

    public function testSpssEpochArithmeticDoesNotDependOnDefaultTimezone(): void
    {
        $originalTimezone = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Auckland');
            $auckland = Utils::parseSpssDateTime(
                '01-Jan-1970 01:02:03.5',
                Variable::FORMAT_TYPE_DATETIME,
            );
            date_default_timezone_set('America/New_York');
            $newYork = Utils::parseSpssDateTime(
                '01-Jan-1970 01:02:03.5',
                Variable::FORMAT_TYPE_DATETIME,
            );
        } finally {
            date_default_timezone_set($originalTimezone);
        }

        self::assertSame(12219382923.5, $auckland);
        self::assertSame($auckland, $newYork);
    }

    #[DataProvider('invalidValueProvider')]
    public function testInvalidValueFailsExplicitly(int $format, string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Utils::parseSpssDateTime($value, $format);
    }

    public function testIntegerLikeFloatUsesSameCompressedEncodingAsInteger(): void
    {
        $data = $this->writerData([[
            'name' => 'NUMERIC_VALUE',
            'format' => Variable::FORMAT_TYPE_F,
            'width' => 8,
            'data' => [1],
        ]]);

        $integerWriter = new Writer($data);
        $data['variables'][0]['data'] = [1.0];
        $floatWriter = new Writer($data);

        $integerStream = $integerWriter->getBuffer()->getStream();
        $floatStream = $floatWriter->getBuffer()->getStream();
        rewind($integerStream);
        rewind($floatStream);
        $integerBytes = stream_get_contents($integerStream);
        $floatBytes = stream_get_contents($floatStream);
        self::assertIsString($integerBytes);
        self::assertIsString($floatBytes);
        self::assertSame($integerBytes, $floatBytes);
    }

    public function testNegativeZeroIsNotIntegerCompressed(): void
    {
        $data = $this->writerData([[
            'name' => 'NUMERIC_VALUE',
            'format' => Variable::FORMAT_TYPE_F,
            'width' => 8,
            'data' => [-0.0],
        ]]);

        $writer = new Writer($data);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $actual = Reader::fromString($buffer->getStream())->read();

        self::assertSame(
            pack('d', -0.0),
            pack('d', $actual->data[0][0]),
        );
    }

    #[DataProvider('nonIntegralBiasOpcodeProvider')]
    public function testNonIntegralBiasOnlyUsesExactlyRepresentableOpcodes(float $value, int $expectedOpcode): void
    {
        self::assertSame($expectedOpcode, $this->firstDataOpcode($value, 100.5));
    }

    public function testNonIntegralBiasValuesRoundTripWithoutCorruption(): void
    {
        $values = [-99.5, 150.5, -100.5, 151.5, 1.25, 1.0, INF, -INF, NAN];
        $data = $this->writerData([[
            'name' => 'NUMERIC_VALUE',
            'format' => Variable::FORMAT_TYPE_F,
            'width' => 8,
            'data' => $values,
        ]]);
        $data['header']['bias'] = 100.5;

        $writer = new Writer($data);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $actual = Reader::fromString($buffer->getStream())->read();

        foreach (array_slice($values, 0, -1) as $index => $expected) {
            self::assertSame($expected, $actual->data[$index][0]);
        }
        self::assertNan($actual->data[8][0]);
    }

    private function firstDataOpcode(float $value, float $bias): int
    {
        $data = $this->writerData([[
            'name' => 'NUMERIC_VALUE',
            'format' => Variable::FORMAT_TYPE_F,
            'width' => 8,
            'data' => [$value],
        ]]);
        $data['header']['bias'] = $bias;

        $writer = new Writer($data);
        $stream = $writer->getBuffer()->getStream();
        rewind($stream);
        $bytes = stream_get_contents($stream);
        self::assertIsString($bytes);

        $reader = Reader::fromString($bytes)->readMetaData();
        $opcode = $bytes[$reader->dataPosition + 4] ?? null;
        self::assertIsString($opcode);

        return \ord($opcode);
    }

    /**
     * @param list<array<string, mixed>> $variables
     *
     * @return array<string, mixed>
     */
    private function writerData(array $variables): array
    {
        return [
            'header' => [
                'recType' => Header::NORMAL_REC_TYPE,
                'prodName' => '@(#) SPSS DATA FILE',
                'layoutCode' => 2,
                'nominalCaseSize' => \count($variables),
                'casesCount' => 0,
                'compression' => 1,
                'weightIndex' => 0,
                'bias' => 100,
                'creationDate' => '01 Jan 70',
                'creationTime' => '00:00:00',
                'fileLabel' => 'date/time values',
            ],
            'variables' => $variables,
        ];
    }
}
