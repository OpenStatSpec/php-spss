<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Exception;
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

    public function testEmptySetRecordsDecodeToEmptyCollections(): void
    {
        $variableSets = $this->readRecord(VariableSets::SUBTYPE, '');
        $multipleResponseSets = $this->readRecord(MultipleResponseSets::SUBTYPE, '');

        self::assertInstanceOf(VariableSets::class, $variableSets);
        self::assertSame([], $variableSets->toArray());
        self::assertInstanceOf(MultipleResponseSets::class, $multipleResponseSets);
        self::assertSame([], $multipleResponseSets->toArray());
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

        $this->expectException(1 === $size ? \UnexpectedValueException::class : Exception::class);
        $this->expectExceptionMessage($message);

        new InfoCollection()->fill($buffer);
    }

    /** @return iterable<string, array{int, int, string, string}> */
    public static function malformedRecordProvider(): iterable
    {
        yield 'variable set requires size one' => [5, 2, "A= x\n", 'declared payload is 10 bytes'];
        yield 'variable set requires final line feed' => [5, 1, 'A= x', 'final set must end with a line feed'];
        yield 'variable set requires exact separator' => [5, 1, "A=x\n", 'expected "name= member ..."'];
        yield 'variable set rejects duplicate names' => [5, 1, "A= x\nA= y\n", 'duplicate set name "A"'];
        yield 'variable set rejects repeated member separator' => [5, 1, "A= x  y\n", 'separated by one space'];
        yield 'multiple response set requires equals' => [7, 1, "\$a C 0  a\n", 'missing equals sign'];
        yield 'multiple response set name requires dollar' => [7, 1, "a=C 0  a\n", 'must begin with "$"'];
        yield 'multiple response set rejects duplicate names' => [7, 1, "\$a=C 0  a\n\$a=C 0  b\n", 'duplicate set name "$a"'];
        yield 'multiple response set requires type byte' => [7, 1, '$a=', 'missing set type'];
        yield 'legacy record rejects E' => [7, 1, '$a=E 1 1 x 0  a b' . "\n", 'type "E" is invalid for subtype 7'];
        yield 'counted-values record rejects D' => [19, 1, '$a=D1 x 0  a b' . "\n", 'type "D" is invalid for subtype 19'];
        yield 'E validates label source' => [19, 1, '$a=E 2 1 x 0  a b' . "\n", 'label source must be 1 or 11'];
        yield 'D requires positive counted value length' => [7, 1, "\$a=D0  0  a\n", 'counted value length must be positive'];
        yield 'label length requires decimal' => [7, 1, "\$a=C x\n", 'missing decimal label length'];
        yield 'label length rejects integer overflow' => [7, 1, "\$a=C 999999999999999999999  a\n", 'exceeds the supported integer range'];
        yield 'counted value length is exact' => [7, 1, '$a=D99 x' . "\n", 'counted value declares 99 bytes'];
        yield 'record requires final line feed' => [7, 1, '$a=C 0  a b', 'set must end with a line feed'];
        yield 'variable names require one separator' => [7, 1, "\$a=C 0  a  b\n", 'separated by one space'];
        yield 'variable names must be lowercase' => [7, 1, "\$a=C 0  A\n", 'must be lowercase'];
    }

    /** @return iterable<string, array{array<string, list<string>>, string}> */
    public static function invalidVariableSetsProvider(): iterable
    {
        yield 'empty set name' => [['' => ['value']], 'set name'];
        yield 'space in set name' => [['bad name' => ['value']], 'set name'];
        yield 'equals in member name' => [['set' => ['bad=name']], 'variable name'];
        yield 'line break in member name' => [['set' => ["bad\nname"]], 'variable name'];
    }

    /** @param array<string, list<string>> $data */
    #[DataProvider('invalidVariableSetsProvider')]
    public function testVariableSetsWriterRejectsAmbiguousIdentifiers(array $data, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new VariableSets(['data' => $data])->write(Buffer::factory('', ['memory' => true]));
    }

    /** @return iterable<string, array{int, array<string, array<string, mixed>>, string}> */
    public static function invalidMultipleResponseSetsProvider(): iterable
    {
        $category = [
            'type' => 'C',
            'countedValue' => null,
            'label' => '',
            'labelSource' => null,
            'variables' => ['a'],
        ];
        $dichotomy = [
            'type' => 'D',
            'countedValue' => '1',
            'label' => '',
            'labelSource' => null,
            'variables' => ['a'],
        ];
        $counted = [
            'type' => 'E',
            'countedValue' => '1',
            'label' => '',
            'labelSource' => 1,
            'variables' => ['a'],
        ];

        yield 'unsupported subtype' => [8, [], 'subtype must be 7 or 19'];
        yield 'invalid set name' => [7, ['$bad name' => $category], 'must begin with "$"'];
        yield 'invalid legacy type' => [7, ['$a' => [...$category, 'type' => 'E']], 'invalid for subtype 7'];
        yield 'invalid label source' => [19, ['$a' => [...$counted, 'labelSource' => 2]], 'labelSource must be 1 or 11'];
        yield 'label source on legacy type' => [7, ['$a' => [...$category, 'labelSource' => 1]], 'only valid for type E'];
        yield 'missing counted value' => [7, ['$a' => [...$dichotomy, 'countedValue' => null]], 'requires a countedValue'];
        yield 'empty counted value' => [7, ['$a' => [...$dichotomy, 'countedValue' => '']], 'cannot be empty'];
        yield 'counted value on category' => [7, ['$a' => [...$category, 'countedValue' => '1']], 'only valid for type D or E'];
        yield 'invalid variable name' => [7, ['$a' => [...$category, 'variables' => ['Upper']]], 'must be non-empty, lowercase'];
    }

    /** @param array<string, array<string, mixed>> $data */
    #[DataProvider('invalidMultipleResponseSetsProvider')]
    public function testMultipleResponseSetsWriterRejectsInvalidSemantics(
        int $subtype,
        array $data,
        string $message,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new MultipleResponseSets(['subtype' => $subtype, 'data' => $data])
            ->write(Buffer::factory('', ['memory' => true]));
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
