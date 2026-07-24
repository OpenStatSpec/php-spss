<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Sav\Record\Info\MultipleResponseSets;
use SPSS\Sav\Record\Info\VariableSets;
use SPSS\Sav\Record\InfoCollection;

class InfoSetRecordsTest extends TestCase
{
    public function testVariableSetsRoundTripPreservesSetAndMemberOrder(): void
    {
        $expected = [
            'FirstSet' => ['LongVariableOne', 'LongVariableTwo'],
            'EmptySet' => [],
            'LastSet' => ['LastVariable'],
        ];

        $record = new VariableSets(['data' => $expected]);
        $parsed = $this->roundTrip($record);

        self::assertInstanceOf(VariableSets::class, $parsed);
        self::assertSame($expected, $parsed->toArray());
    }

    public function testVariableSetsAcceptCrLf(): void
    {
        $record = $this->readRecord(5, "One= longName anotherName\r\nEmpty= \r\n");

        self::assertSame([
            'One' => ['longName', 'anotherName'],
            'Empty' => [],
        ], $record->toArray());
    }

    public function testLegacyMultipleResponseSetsRoundTrip(): void
    {
        $expected = [
            '$a' => [
                'type' => 'C',
                'countedValue' => null,
                'label' => 'my mcgroup',
                'labelSource' => null,
                'variables' => ['a', 'b', 'c'],
            ],
            '$b' => [
                'type' => 'D',
                'countedValue' => '55',
                'label' => '',
                'labelSource' => null,
                'variables' => ['g', 'e', 'f', 'd'],
            ],
            '$c' => [
                'type' => 'D',
                'countedValue' => 'Yes',
                'label' => 'mdgroup #2',
                'labelSource' => null,
                'variables' => ['h', 'i', 'j'],
            ],
        ];

        $record = new MultipleResponseSets(['data' => $expected]);
        self::assertSame(
            '$a=C 10 my mcgroup a b c' . "\n"
            . '$b=D2 55 0  g e f d' . "\n"
            . '$c=D3 Yes 10 mdgroup #2 h i j' . "\n",
            $this->serializedPayload($record),
        );
        $parsed = $this->roundTrip($record);

        self::assertInstanceOf(MultipleResponseSets::class, $parsed);
        self::assertSame(MultipleResponseSets::SUBTYPE, $parsed->subtype);
        self::assertSame($expected, $parsed->toArray());
    }

    public function testCountedValuesMultipleResponseSetsRoundTripPreservesBothLabelSources(): void
    {
        $expected = [
            '$d' => [
                'type' => 'E',
                'countedValue' => '34',
                'label' => 'third mdgroup',
                'labelSource' => 1,
                'variables' => ['k', 'l', 'm'],
            ],
            '$e' => [
                'type' => 'E',
                'countedValue' => 'choice',
                'label' => '',
                'labelSource' => 11,
                'variables' => ['n', 'o', 'p'],
            ],
        ];

        $record = new MultipleResponseSets([
            'subtype' => MultipleResponseSets::COUNTED_VALUES_SUBTYPE,
            'data' => $expected,
        ]);
        self::assertSame(
            '$d=E 1 2 34 13 third mdgroup k l m' . "\n"
            . '$e=E 11 6 choice 0  n o p' . "\n",
            $this->serializedPayload($record),
        );
        $parsed = $this->roundTrip($record);

        self::assertInstanceOf(MultipleResponseSets::class, $parsed);
        self::assertSame(MultipleResponseSets::COUNTED_VALUES_SUBTYPE, $parsed->subtype);
        self::assertSame($expected, $parsed->toArray());
    }

    public function testMultipleResponseParserUsesByteLengthsInTheFileEncoding(): void
    {
        $expected = [
            '$utf8' => [
                'type' => 'D',
                'countedValue' => 'Jah',
                'label' => 'Vastus õ',
                'labelSource' => null,
                'variables' => ['answer1', 'answer2'],
            ],
        ];

        $record = new MultipleResponseSets(['data' => $expected]);
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->charset = 'ISO-8859-1';

        $record->write($buffer);
        $buffer->rewind();
        self::assertSame(7, $buffer->readInt());

        $collection = new InfoCollection();
        $parsed = $collection->fill($buffer)[7];

        self::assertSame($expected, $parsed->toArray());
    }

    #[DataProvider('malformedRecordProvider')]
    public function testMalformedRecordsHaveClearErrors(int $subtype, int $size, string $payload, string $message): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt($subtype);
        $buffer->writeInt($size);
        $buffer->writeInt(strlen($payload));
        $buffer->write($payload);
        $buffer->rewind();

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($message);

        new InfoCollection()->fill($buffer);
    }

    /** @return iterable<string, array{int, int, string, string}> */
    public static function malformedRecordProvider(): iterable
    {
        yield 'variable set requires size one' => [5, 2, "A= x\n", 'element size must be 1'];
        yield 'variable set requires final line feed' => [5, 1, 'A= x', 'final set must end with a line feed'];
        yield 'variable set requires exact separator' => [5, 1, "A=x\n", 'expected "name= member ..."'];
        yield 'legacy record rejects E' => [7, 1, '$a=E 1 1 x 0  a b' . "\n", 'type "E" is invalid for subtype 7'];
        yield 'counted-values record rejects D' => [19, 1, '$a=D1 x 0  a b' . "\n", 'type "D" is invalid for subtype 19'];
        yield 'E validates label source' => [19, 1, '$a=E 2 1 x 0  a b' . "\n", 'label source must be 1 or 11'];
        yield 'counted value length is exact' => [7, 1, '$a=D99 x' . "\n", 'counted value declares 99 bytes'];
        yield 'record requires final line feed' => [7, 1, '$a=C 0  a b', 'set must end with a line feed'];
    }

    private function roundTrip(VariableSets|MultipleResponseSets $record): VariableSets|MultipleResponseSets
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $record->write($buffer);
        $buffer->rewind();
        self::assertSame(7, $buffer->readInt());

        $parsed = new InfoCollection()->fill($buffer);

        $subtype = $record instanceof MultipleResponseSets ? $record->subtype : VariableSets::SUBTYPE;
        $parsedRecord = $parsed[$subtype];
        if (!$parsedRecord instanceof VariableSets && !$parsedRecord instanceof MultipleResponseSets) {
            throw new \LogicException(sprintf('Unexpected record type %s.', $parsedRecord::class));
        }

        return $parsedRecord;
    }

    private function readRecord(int $subtype, string $payload): VariableSets|MultipleResponseSets
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt($subtype);
        $buffer->writeInt(1);
        $buffer->writeInt(strlen($payload));
        $buffer->write($payload);
        $buffer->rewind();

        $record = new InfoCollection()->fill($buffer)[$subtype];
        if (!$record instanceof VariableSets && !$record instanceof MultipleResponseSets) {
            throw new \LogicException(sprintf('Unexpected record type %s.', $record::class));
        }

        return $record;
    }

    private function serializedPayload(VariableSets|MultipleResponseSets $record): string
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $record->write($buffer);
        $buffer->rewind();
        self::assertSame(7, $buffer->readInt());
        self::assertSame(
            $record instanceof MultipleResponseSets ? $record->subtype : VariableSets::SUBTYPE,
            $buffer->readInt(),
        );
        self::assertSame(1, $buffer->readInt());
        $count = $buffer->readInt();
        $payload = $buffer->read($count);
        self::assertIsString($payload);

        return $payload;
    }
}
