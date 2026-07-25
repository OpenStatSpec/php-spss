<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Exception;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

final class DeterministicMutationFuzzTest extends TestCase
{
    private const int MUTATIONS_PER_FORMAT = 64;

    private const int MAX_INPUT_BYTES = 4_096;

    private const int MAX_CASES = 16;

    private const int MAX_VARIABLES = 32;

    private const int MAX_VALUE_BYTES = 1_024;

    private const int LOOP_GUARD_SECONDS = 2;

    /** @return iterable<string, array{bool, int}> */
    public static function mutationProvider(): iterable
    {
        foreach ([false, true] as $zsav) {
            $format = $zsav ? 'zsav' : 'sav';

            for ($index = 0; $index < self::MUTATIONS_PER_FORMAT; $index++) {
                yield sprintf('%s mutation %02d', $format, $index) => [$zsav, $index];
            }
        }
    }

    #[DataProvider('mutationProvider')]
    public function testMutationHasOnlyBoundedSuccessOrPublicValidationFailure(
        bool $zsav,
        int $index,
    ): void {
        $previousAsyncSignals = $this->armLoopGuard();

        try {
            $base = $this->createContainer($zsav);
            $bytes = $this->mutate($base, $index, $zsav ? 0x5a5a : 0x5a19);
            $useIterator = 0 === $index % 8;
            self::assertLessThanOrEqual(self::MAX_INPUT_BYTES, strlen($bytes));

            set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
                throw new \ErrorException($message, 0, $severity, $file, $line);
            });

            try {
                if ($useIterator) {
                    $this->assertBoundedIteratorResult($bytes);
                } else {
                    $this->assertBoundedBulkResult($bytes);
                }
            } catch (Exception|\InvalidArgumentException|\UnexpectedValueException $exception) {
                self::assertNotSame('', $exception->getMessage());
            } finally {
                restore_error_handler();
            }
        } finally {
            $this->disarmLoopGuard($previousAsyncSignals);
        }
    }

    private function armLoopGuard(): ?bool
    {
        if (
            !function_exists('pcntl_alarm')
            || !function_exists('pcntl_async_signals')
            || !function_exists('pcntl_signal')
        ) {
            return null;
        }

        $previousAsyncSignals = pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): never {
            throw new \RuntimeException('Mutation loop exceeded its bounded execution guard.');
        });
        pcntl_alarm(self::LOOP_GUARD_SECONDS);

        return $previousAsyncSignals;
    }

    private function disarmLoopGuard(?bool $previousAsyncSignals): void
    {
        if (null === $previousAsyncSignals) {
            return;
        }

        pcntl_alarm(0);
        pcntl_signal(SIGALRM, SIG_DFL);
        pcntl_async_signals($previousAsyncSignals);
    }

    private function assertBoundedBulkResult(string $bytes): void
    {
        $reader = Reader::fromString($bytes)->read();

        self::assertLessThanOrEqual(self::MAX_VARIABLES, count($reader->variables));
        self::assertLessThanOrEqual(self::MAX_CASES, count($reader->data));
        foreach ($reader->data as $row) {
            $this->assertBoundedRow($row);
        }
    }

    private function assertBoundedIteratorResult(string $bytes): void
    {
        $reader = Reader::fromString($bytes)->readMetaData();
        self::assertLessThanOrEqual(self::MAX_VARIABLES, count($reader->variables));

        $cases = 0;
        while ($cases < self::MAX_CASES && $reader->readCase()) {
            $this->assertBoundedRow($reader->getCase());
            $cases++;
        }

        self::assertLessThan(self::MAX_CASES, $cases);
    }

    /** @param array<int, mixed> $row */
    private function assertBoundedRow(array $row): void
    {
        self::assertLessThanOrEqual(self::MAX_VARIABLES, count($row));
        foreach ($row as $value) {
            if (is_string($value)) {
                self::assertLessThanOrEqual(self::MAX_VALUE_BYTES, strlen($value));
            } else {
                self::assertTrue(is_float($value) || is_int($value) || null === $value);
            }
        }
    }

    private function createContainer(bool $zsav): string
    {
        $writer = new Writer([
            'header' => $zsav
                ? ['recType' => Header::ZLIB_REC_TYPE, 'compression' => 2]
                : ['compression' => 1],
            'documents' => ['deterministic mutation corpus'],
            'variables' => [
                [
                    'name' => 'number',
                    'format' => Variable::FORMAT_TYPE_F,
                    'width' => 8,
                    'data' => [1, -2.5, 300],
                ],
                [
                    'name' => 'text',
                    'format' => Variable::FORMAT_TYPE_A,
                    'width' => 12,
                    'data' => ['alpha', 'beta', 'gamma'],
                ],
            ],
        ]);
        $stream = $writer->getBuffer()->getStream();
        $streamInfo = fstat($stream);
        if (false === $streamInfo) {
            throw new \RuntimeException('Unable to determine mutation corpus size.');
        }

        $bytes = stream_get_contents($stream, $streamInfo['size'], 0);
        if (false === $bytes) {
            throw new \RuntimeException('Unable to read mutation corpus.');
        }

        return $bytes;
    }

    private function mutate(string $bytes, int $index, int $seed): string
    {
        $length = strlen($bytes);
        $state = $this->mix($seed, $index);

        if ($index < 16) {
            return substr($bytes, 0, 1 + ($state % ($length - 1)));
        }

        if ($index < 32) {
            $offset = $state % $length;
            $bytes[$offset] = chr(ord($bytes[$offset]) ^ (1 << (($state >> 8) % 8)));

            return $bytes;
        }

        if ($index < 40) {
            $offset = $state % $length;
            $chunkLength = 1 + (($state >> 8) % min(8, $length - $offset));

            return substr($bytes, 0, $offset) . substr($bytes, $offset + $chunkLength);
        }

        if ($index < 48) {
            $source = $state % ($length - 8);
            $chunkLength = 1 + (($state >> 8) % 8);
            $insertion = ($state >> 16) % ($length + 1);
            $chunk = substr($bytes, $source, $chunkLength);

            return substr($bytes, 0, $insertion) . $chunk . substr($bytes, $insertion);
        }

        $offset = $state % ($length - 3);
        $values = [0, -1, 2_147_483_647, -2_147_483_648];

        return substr_replace($bytes, pack('i', $values[$index % count($values)]), $offset, 4);
    }

    private function mix(int $seed, int $index): int
    {
        $value = ($seed ^ (($index + 1) * 0x45d9f3b)) & 0x7fffffff;
        $value = (($value ^ ($value >> 16)) * 0x45d9f3b) & 0x7fffffff;

        return $value ^ ($value >> 16);
    }
}
