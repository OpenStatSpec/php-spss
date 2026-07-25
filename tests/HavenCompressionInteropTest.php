<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;

final class HavenCompressionInteropTest extends TestCase
{
    private const string BYTE_COMPRESSED_SAV = 'JEZMMkAoIykgU1BTUyBEQVRBIEZJTEUgLSBodHRwczovL2dpdGh1Yi5jb20vV2l6YXJkTWFjL1JlYWRTdGF0IAIAAAACAAAAAQAAAAAAAAADAAAAAAAAAAAAWUAyNSBKdWwgMjYxNToxMTo1NiAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAAAAACAAAAAAAAAAAAAAAAAAAAAggFAAIIBQBOVU1CRVIgIAIAAAADAAAAAAAAAAAAAAAAAwEAAAMBAFRFWFQgICAgBwAAAAMAAAAEAAAACAAAABQAAAAAAAAAAAAAAP////8BAAAAAQAAAAIAAADp/QAABwAAAAQAAAAIAAAAAwAAAP///////+//////////73/+///////v/wcAAAALAAAABAAAAAYAAAADAAAACAAAAAEAAAABAAAACAAAAAAAAAAHAAAADQAAAAEAAAAXAAAATlVNQkVSPW51bWJlcglURVhUPXRleHQHAAAAEAAAAAgAAAACAAAAAQAAAAAAAAADAAAAAAAAAOcDAAAAAAAAZf0AAAAAAABhYmMgICAgIP3+AAAAAAAAAAAAAAAABED9/fwAAAAAAAAAAAAAOI9AeHl6ICAgICA=';

    private const string ZLIB_COMPRESSED_ZSAV = 'JEZMM0AoIykgU1BTUyBEQVRBIEZJTEUgLSBodHRwczovL2dpdGh1Yi5jb20vV2l6YXJkTWFjL1JlYWRTdGF0IAIAAAACAAAAAgAAAAAAAAADAAAAAAAAAAAAWUAyNSBKdWwgMjYxNToxMjowMyAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAAAAACAAAAAAAAAAAAAAAAAAAAAggFAAIIBQBOVU1CRVIgIAIAAAADAAAAAAAAAAAAAAAAAwEAAAMBAFRFWFQgICAgBwAAAAMAAAAEAAAACAAAABQAAAAAAAAAAAAAAP////8BAAAAAQAAAAIAAADp/QAABwAAAAQAAAAIAAAAAwAAAP///////+//////////73/+///////v/wcAAAALAAAABAAAAAYAAAADAAAACAAAAAEAAAABAAAACAAAAAAAAAAHAAAADQAAAAEAAAAXAAAATlVNQkVSPW51bWJlcglURVhUPXRleHQHAAAAEAAAAAgAAAACAAAAAQAAAAAAAAADAAAAAAAAAOcDAAAAAAAAvwEAAAAAAAD9AQAAAAAAADAAAAAAAAAAeJxL/csABolJyQog8PcfAxJgcfj79w+Ca9HvUFFZBVYHAEyYC3Cc/////////wAAAAAAAAAAAPA/AAEAAAC/AQAAAAAAANcBAAAAAAAAOAAAACYAAAA=';

    #[DataProvider('havenFixtureProvider')]
    public function testReadsHavenCompressedRowsWithPaddedOpcodeClusters(
        string $fixture,
        string $recordType,
        int $compression,
    ): void {
        $bytes = base64_decode($fixture, true);
        self::assertIsString($bytes);

        $reader = Reader::fromString($bytes)->read();

        self::assertSame($recordType, $reader->header->recType);
        self::assertSame($compression, $reader->header->compression);
        self::assertStringContainsString('ReadStat', $reader->header->prodName);
        self::assertSame(['NUMBER', 'TEXT'], array_map(
            static fn(\SPSS\Sav\Record\Variable $variable): string => $variable->name,
            $reader->variables,
        ));
        self::assertSame([
            [1.0, 'abc'],
            [2.5, ''],
            [999.0, 'xyz'],
        ], $reader->data);

        $iterator = Reader::fromString($bytes)->readMetaData();
        $rows = [];
        while ($iterator->readCase()) {
            $rows[] = $iterator->getCase();
        }

        self::assertSame($reader->data, $rows);
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function havenFixtureProvider(): iterable
    {
        yield 'haven 2.5.5 byte-compressed SAV' => [
            self::BYTE_COMPRESSED_SAV,
            Header::NORMAL_REC_TYPE,
            1,
        ];
        yield 'haven 2.5.5 ZLIB-compressed ZSAV' => [
            self::ZLIB_COMPRESSED_ZSAV,
            Header::ZLIB_REC_TYPE,
            2,
        ];
    }
}
