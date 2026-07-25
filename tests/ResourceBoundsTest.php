<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

final class ResourceBoundsTest extends TestCase
{
    private const string MEMORY_LIMIT = '128M';

    public function testFullSavRoundTripWithinMemoryLimit(): void
    {
        $this->withinMemoryLimit(function (): void {
            $bytes = $this->savBytes(2_048, 16);
            $reader = Reader::fromString($bytes)->read();

            self::assertCount(2_048, $reader->data);
            self::assertCount(16, $reader->variables);
            self::assertCount(16, $reader->data[0]);
            self::assertCount(16, $reader->data[1_024]);
            self::assertCount(16, $reader->data[2_047]);
            self::assertSame($this->valueAt(0, 0), $reader->data[0][0]);
            self::assertSame($this->valueAt(1_024, 8), $reader->data[1_024][8]);
            self::assertSame($this->valueAt(2_047, 15), $reader->data[2_047][15]);
        });
    }

    public function testStreamingCaseReadDoesNotAccumulateDataMatrix(): void
    {
        $this->withinMemoryLimit(function (): void {
            $bytes = $this->savBytes(8_192, 4);
            $reader = Reader::fromString($bytes)->readMetaData();

            self::assertSame(8_192, $reader->getNumberOfCases());
            self::assertCount(4, $reader->variables);
            self::assertSame([], $reader->data);

            $rowsRead = 0;
            while ($reader->readCase()) {
                $row = $reader->getCase();
                if (0 === $rowsRead) {
                    self::assertCount(4, $row);
                    self::assertSame($this->valueAt(0, 0), $row[0]);
                } elseif (4_096 === $rowsRead) {
                    self::assertCount(4, $row);
                    self::assertSame($this->valueAt(4_096, 2), $row[2]);
                } elseif (8_191 === $rowsRead) {
                    self::assertCount(4, $row);
                    self::assertSame($this->valueAt(8_191, 3), $row[3]);
                }

                $rowsRead++;
            }

            self::assertSame(8_192, $rowsRead);
            self::assertSame([], $reader->data);
        });
    }

    /** @param \Closure(): void $assertions */
    private function withinMemoryLimit(\Closure $assertions): void
    {
        $previousLimit = ini_get('memory_limit');
        if (false === ini_set('memory_limit', self::MEMORY_LIMIT)) {
            self::markTestSkipped('The PHP memory limit cannot be changed at runtime.');
        }

        try {
            self::assertSame(self::MEMORY_LIMIT, ini_get('memory_limit'));
            $assertions();
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    }

    private function savBytes(int $rowCount, int $columnCount): string
    {
        $variables = [];
        for ($column = 0; $column < $columnCount; $column++) {
            $data = [];
            for ($row = 0; $row < $rowCount; $row++) {
                $data[] = $this->valueAt($row, $column);
            }

            $variables[] = [
                'name' => sprintf('value_%02d', $column),
                'format' => Variable::FORMAT_TYPE_F,
                'width' => 8,
                'data' => $data,
            ];
        }

        $writer = new Writer([
            'header' => [
                'recType' => Header::NORMAL_REC_TYPE,
                'compression' => 1,
            ],
            'variables' => $variables,
        ]);
        $stream = $writer->getBuffer()->getStream();
        $streamInfo = fstat($stream);
        self::assertIsArray($streamInfo);

        $bytes = stream_get_contents($stream, $streamInfo['size'], 0);
        self::assertIsString($bytes);

        return $bytes;
    }

    private function valueAt(int $row, int $column): float
    {
        return $row * 1_000.0 + $column + 0.25;
    }
}
