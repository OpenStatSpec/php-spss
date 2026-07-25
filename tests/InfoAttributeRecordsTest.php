<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Record\Info\DataFileAttributes;
use SPSS\Sav\Record\Info\VariableAttributes;
use SPSS\Sav\Record\InfoCollection;

class InfoAttributeRecordsTest extends TestCase
{
    public function testDataFileAttributesRoundTripPreservesAttributeAndValueOrder(): void
    {
        $expected = [
            'project' => ['first', 'second/value:with:colon', "O'Brien"],
            '$empty' => [''],
        ];
        $record = new DataFileAttributes(['data' => $expected]);

        self::assertSame(
            "project('first'\n'second/value:with:colon'\n'O'Brien'\n)\$empty(''\n)",
            $this->serializedPayload($record),
        );
        self::assertSame($expected, $this->roundTrip($record)->toArray());
    }

    public function testDataFileAttributesMergeRepeatedNamesWhileDecoding(): void
    {
        $payload = "source('first'\n)source('second'\n'third'\n)";
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt(1);
        $buffer->writeInt(strlen($payload));
        $buffer->write($payload);
        $buffer->rewind();

        $record = DataFileAttributes::fill($buffer);
        self::assertSame(['source' => ['first', 'second', 'third']], $record->toArray());
    }

    public function testVariableAttributesRoundTripSupportsMultipleVariablesAndValueDelimiters(): void
    {
        $expected = [
            'longOne' => [
                '$@Role' => ['0'],
                'custom' => ['slash/value', 'colon:value', "O'Brien"],
            ],
            'longTwo' => [
                'note' => ['hello'],
            ],
        ];
        $record = new VariableAttributes(['data' => $expected]);

        self::assertSame(
            "longOne:\$@Role('0'\n)custom('slash/value'\n'colon:value'\n'O'Brien'\n)/longTwo:note('hello'\n)",
            $this->serializedPayload($record),
        );
        self::assertSame($expected, $this->roundTrip($record)->toArray());
    }

    public function testAttributeRecordCountUsesEncodedBytes(): void
    {
        $expected = ['label' => ['Vastus õ']];
        $record = new DataFileAttributes(['data' => $expected]);
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->charset = 'ISO-8859-1';

        $record->write($buffer);
        $buffer->rewind();
        self::assertSame(7, $buffer->readInt());
        self::assertSame(DataFileAttributes::SUBTYPE, $buffer->readInt());
        self::assertSame(1, $buffer->readInt());
        $count = $buffer->readInt();
        $payload = $buffer->read($count);
        self::assertIsString($payload);
        self::assertSame("label('Vastus \xF5'\n)", $payload);
        self::assertStringContainsString("\xF5", $payload);

        $buffer->rewind();
        self::assertSame(7, $buffer->readInt());
        $parsed = new InfoCollection()->fill($buffer)[DataFileAttributes::SUBTYPE];
        self::assertSame($expected, $parsed->toArray());
    }

    public function testInfoCollectionPreservesAndMergesDuplicateVariableAttributeRecords(): void
    {
        $firstData = [
            'longOne' => [
                'first' => ['a'],
                'shared' => ['one'],
            ],
            'longTwo' => ['second' => ['b']],
        ];
        $secondData = [
            'longThree' => ['third' => ['c']],
            'longOne' => [
                'shared' => ['two'],
                'later' => ['z'],
            ],
        ];
        $expectedMerged = [
            'longOne' => [
                'first' => ['a'],
                'shared' => ['one', 'two'],
                'later' => ['z'],
            ],
            'longTwo' => ['second' => ['b']],
            'longThree' => ['third' => ['c']],
        ];

        $buffer = Buffer::factory('', ['memory' => true]);
        new VariableAttributes(['data' => $firstData])->write($buffer);
        new VariableAttributes(['data' => $secondData])->write($buffer);
        $buffer->rewind();

        $collection = new InfoCollection();
        self::assertSame(7, $buffer->readInt());
        $collection->fill($buffer);
        self::assertSame(7, $buffer->readInt());
        $collection->fill($buffer);

        self::assertCount(2, $collection->records);
        self::assertSame($firstData, $collection->records[0]->toArray());
        self::assertSame($secondData, $collection->records[1]->toArray());
        self::assertSame($secondData, $collection->data[VariableAttributes::SUBTYPE]->toArray());

        $merged = $collection->mergedData[VariableAttributes::SUBTYPE];
        self::assertInstanceOf(VariableAttributes::class, $merged);
        self::assertSame($expectedMerged, $merged->toArray());
        self::assertSame($expectedMerged, $this->roundTrip($merged)->toArray());
    }

    #[DataProvider('malformedRecordProvider')]
    public function testMalformedAttributeRecordsHaveClearErrors(
        int $subtype,
        int $size,
        string $payload,
        string $message,
    ): void {
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
        yield 'data file attributes require size one' => [17, 2, "a('x'\n)", 'declared payload is 14 bytes'];
        yield 'data file attributes cannot be empty' => [17, 1, '', 'byte count must be positive'];
        yield 'attribute requires opening parenthesis' => [17, 1, 'attribute', 'not followed by an opening parenthesis'];
        yield 'attribute name rejects whitespace' => [17, 1, "bad name('x'\n)", 'attribute name must be non-empty'];
        yield 'attribute requires a value' => [17, 1, 'a()', 'must contain at least one single-quoted value'];
        yield 'attribute value requires opening quote' => [17, 1, "a(x'\n)", 'at least one single-quoted value'];
        yield 'attribute value requires line feed' => [17, 1, "a('x')", 'not terminated by a quote and line feed'];
        yield 'attribute value requires quote before line feed' => [17, 1, "a('x\n)", 'must end with a single quote'];
        yield 'attribute requires closing parenthesis' => [17, 1, "a('x'\n", 'not followed by a closing parenthesis'];
        yield 'variable name requires colon' => [18, 1, "longName('x'\n)", 'variable name is not followed by a colon'];
        yield 'variable set cannot trail slash' => [18, 1, "longName:a('x'\n)/", 'cannot end with a variable-set delimiter'];
    }

    /** @return iterable<string, array{array<string, list<string>>, string}> */
    public static function invalidDataFileAttributesProvider(): iterable
    {
        yield 'empty set' => [[], 'at least one attribute'];
        yield 'empty attribute name' => [['' => ['value']], 'must be non-empty'];
        yield 'attribute name delimiter' => [['bad/name' => ['value']], 'cannot contain whitespace'];
        yield 'empty values' => [['empty' => []], 'must contain at least one value'];
        yield 'line feed in value' => [['note' => ["first\nsecond"]], 'cannot contain line feeds'];
    }

    /** @param array<string, list<string>> $data */
    #[DataProvider('invalidDataFileAttributesProvider')]
    public function testWriterRejectsMalformedAttributeSets(array $data, string $message): void
    {
        $record = new DataFileAttributes(['data' => $data]);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $record->write(Buffer::factory('', ['memory' => true]));
    }

    private function roundTrip(DataFileAttributes|VariableAttributes $record): DataFileAttributes|VariableAttributes
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $record->write($buffer);
        $buffer->rewind();
        self::assertSame(7, $buffer->readInt());
        $parsed = new InfoCollection()->fill($buffer)[$record::SUBTYPE];
        if (!$parsed instanceof DataFileAttributes && !$parsed instanceof VariableAttributes) {
            throw new \LogicException(sprintf('Unexpected record type %s.', $parsed::class));
        }

        return $parsed;
    }

    private function serializedPayload(DataFileAttributes|VariableAttributes $record): string
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $record->write($buffer);
        $buffer->rewind();
        self::assertSame(7, $buffer->readInt());
        self::assertSame($record::SUBTYPE, $buffer->readInt());
        self::assertSame(1, $buffer->readInt());
        $count = $buffer->readInt();
        $payload = $buffer->read($count);
        self::assertIsString($payload);

        return $payload;
    }
}
