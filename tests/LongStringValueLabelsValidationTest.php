<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Buffer;
use SPSS\Sav\Record\Info\LongStringValueLabels;
use SPSS\Sav\Record\InfoCollection;

final class LongStringValueLabelsValidationTest extends TestCase
{
    public function testLabelsRoundTripPreservesOrderDuplicatesAndFileEncoding(): void
    {
        $expectedLabels = [
            ['value' => 'õ', 'label' => 'Esimene'],
            ['value' => 'õ', 'label' => 'Teine'],
            ['value' => '', 'label' => ''],
        ];
        $record = new LongStringValueLabels([
            'data' => [
                'põld' => [
                    'width' => 9,
                    'labels' => $expectedLabels,
                ],
            ],
        ]);
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->charset = 'UTF-8';

        $record->write($buffer);
        $buffer->rewind();
        self::assertSame(7, $buffer->readInt());
        $parsed = new InfoCollection()->fill($buffer)[LongStringValueLabels::SUBTYPE];

        self::assertSame(
            [
                'põld' => [
                    'width' => 9,
                    'values' => ['õ' => 'Teine', '' => ''],
                    'labels' => $expectedLabels,
                ],
            ],
            $parsed->toArray(),
        );
    }

    public function testEmptyLabelRecordEmitsNoInfoRecord(): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        new LongStringValueLabels()->write($buffer);

        self::assertSame(0, $buffer->position());
        self::assertSame('', $buffer->read());
    }

    /** @return iterable<string, array{string, string}> */
    public static function malformedPayloadProvider(): iterable
    {
        $name = pack('i', 1) . 'v';
        $variable = $name . pack('i', 9);
        $value = pack('i', 9) . str_repeat('x', 9);

        yield 'zero variable name length' => [pack('i', 0), 'Invalid variable name length'];
        yield 'truncated variable name' => [pack('i', 3) . 'x', 'Unable to read variable name'];
        yield 'invalid variable width' => [$name . pack('i', 8), 'width must be between 9 and 32767'];
        yield 'negative label count' => [$variable . pack('i', -1), 'Invalid value label count'];
        yield 'value length differs from width' => [$variable . pack('i', 1) . pack('i', 8), 'Value length must equal'];
        yield 'truncated value' => [$variable . pack('i', 1) . pack('i', 9) . 'x', 'Unable to read value label value'];
        yield 'label too long' => [
            $variable . pack('i', 1) . $value . pack('i', 121),
            'label must not exceed 120 bytes',
        ];
        yield 'truncated label' => [
            $variable . pack('i', 1) . $value . pack('i', 3) . 'x',
            'Unable to read value label',
        ];
    }

    #[DataProvider('malformedPayloadProvider')]
    public function testReaderRejectsMalformedPayload(string $payload, string $message): void
    {
        $buffer = Buffer::factory('', ['memory' => true]);
        $buffer->writeInt(1);
        $buffer->writeInt(strlen($payload));
        $buffer->write($payload);
        $buffer->rewind();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        LongStringValueLabels::fill($buffer);
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function malformedWriterDataProvider(): iterable
    {
        yield 'missing width' => [['v' => ['values' => ['x' => 'label']]], 'width required'];
        yield 'missing values and labels' => [['v' => ['width' => 9]], 'values or labels required'];
        yield 'values must be array' => [['v' => ['width' => 9, 'values' => 'invalid']], 'values must be an array'];
        yield 'labels must be array' => [['v' => ['width' => 9, 'labels' => 'invalid']], 'labels must be an array'];
        yield 'width below range' => [['v' => ['width' => 8, 'values' => ['x' => 'label']]], 'width must be between'];
        yield 'entry must be mapping' => [['v' => ['width' => 9, 'labels' => ['invalid']]], 'requires value and label keys'];
        yield 'entry requires both keys' => [
            ['v' => ['width' => 9, 'labels' => [['value' => 'x']]]],
            'requires value and label keys',
        ];
        yield 'entry values must be strings' => [
            ['v' => ['width' => 9, 'labels' => [['value' => 1, 'label' => 'one']]]],
            'values and labels must be strings',
        ];
        yield 'value exceeds width' => [
            ['v' => ['width' => 9, 'labels' => [['value' => '0123456789', 'label' => 'long']]]],
            'value exceeds the variable width',
        ];
        yield 'label exceeds limit' => [
            ['v' => ['width' => 9, 'labels' => [['value' => 'x', 'label' => str_repeat('L', 121)]]]],
            'label must not exceed 120 bytes',
        ];
    }

    /** @param array<array-key, mixed> $data */
    #[DataProvider('malformedWriterDataProvider')]
    public function testWriterRejectsMalformedData(array $data, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new LongStringValueLabels(['data' => $data])->write(Buffer::factory('', ['memory' => true]));
    }
}
