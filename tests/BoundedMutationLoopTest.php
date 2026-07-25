<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Reader;
use SPSS\Sav\Record;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;
use SPSS\Utils;

final class BoundedMutationLoopTest extends TestCase
{
    private const int LOOP_GUARD_SECONDS = 2;

    private ?bool $previousAsyncSignals = null;

    protected function setUp(): void
    {
        if (
            !function_exists('pcntl_alarm')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
        ) {
            return;
        }

        $this->previousAsyncSignals = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): never {
            throw new \RuntimeException('Mutation loop exceeded its bounded execution guard.');
        });
        pcntl_alarm(self::LOOP_GUARD_SECONDS);
    }

    protected function tearDown(): void
    {
        if (null === $this->previousAsyncSignals) {
            return;
        }

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_async_signals($this->previousAsyncSignals);
        $this->previousAsyncSignals = null;
    }

    public function testTruncatedOpcodeReadTerminatesWithValidationException(): void
    {
        $bytes = $this->savBytes([
            [
                'name' => 'number',
                'format' => Variable::FORMAT_TYPE_F,
                'width' => 8,
                'data' => [1],
            ],
        ]);
        $metadata = Reader::fromString($bytes)->readMetaData();
        $bytes = substr($bytes, 0, $metadata->dataPosition + 8);

        $this->expectException(Exception::class);
        Reader::fromString($bytes)->read();
    }

    public function testCompressedStringCaseWriterTerminates(): void
    {
        $bytes = $this->savBytes([
            [
                'name' => 'text',
                'format' => Variable::FORMAT_TYPE_A,
                'width' => 8,
                'data' => ['value'],
            ],
        ]);

        self::assertNotSame('', $bytes);
    }

    public function testValueLabelByteTrimmingTerminates(): void
    {
        $record = new Record\ValueLabel([
            'labels' => [['value' => 1.0, 'label' => 'One']],
            'indexes' => [],
            'stringValues' => false,
        ]);
        $buffer = Buffer::factory('', ['memory' => true]);

        $record->write($buffer);

        self::assertGreaterThan(0, $buffer->position());
    }

    public function testVariableLabelByteTrimmingTerminates(): void
    {
        $variable = new Record\Variable([
            'width' => 8,
            'name' => 'TEXT',
            'label' => 'Label',
            'print' => [0, Variable::FORMAT_TYPE_A, 8, 0],
            'write' => [0, Variable::FORMAT_TYPE_A, 8, 0],
        ]);
        $buffer = Buffer::factory('', ['memory' => true]);

        $variable->write($buffer);

        self::assertGreaterThan(0, $buffer->position());
    }

    public function testBlankRecordLoopTerminatesAtBothBoundaries(): void
    {
        $variable = new Record\Variable();
        $buffer = Buffer::factory('', ['memory' => true]);

        $variable->writeBlank($buffer, 8);
        self::assertSame(0, $buffer->position());

        $variable->writeBlank($buffer, 16);
        self::assertSame(32, $buffer->position());
    }

    public function testInitialSegmentNameTerminates(): void
    {
        $variable = new Record\Variable(['name' => 'sample']);

        self::assertSame('SAMPLE_A', $variable->getSegmentName(0));
    }

    public function testVeryLongWidthProducesBoundedSegmentCount(): void
    {
        self::assertSame(2, Utils::widthToSegments(256));
    }

    public function testShortWidthSegmentGeneratorTerminates(): void
    {
        self::assertSame([8], iterator_to_array(Utils::getSegments(8), false));
    }

    /** @param list<array<string, mixed>> $variables */
    private function savBytes(array $variables): string
    {
        $writer = new Writer([
            'header' => ['compression' => 1],
            'variables' => $variables,
        ]);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $bytes = $buffer->read();
        self::assertIsString($bytes);

        return $bytes;
    }
}
