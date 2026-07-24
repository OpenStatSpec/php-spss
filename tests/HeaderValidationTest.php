<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Exception\EncryptedFileException;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;

final class HeaderValidationTest extends TestCase
{
    public function testEncryptedSavHasDedicatedError(): void
    {
        $wrapper = pack('V2', 28, 0) . 'ENCRYPTED' . 'SAV' . pack('V', 21) . str_repeat("\0", 12);

        $this->expectException(EncryptedFileException::class);
        $this->expectExceptionMessage('Encrypted SAV files are not supported');

        Reader::fromString($wrapper)->readHeader();
    }

    public function testFl3RequiresZlibCompression(): void
    {
        $header = new Header([
            'recType' => Header::ZLIB_REC_TYPE,
            'compression' => 1,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('$FL3 requires ZLIB compression mode 2');

        $header->write(Buffer::factory());
    }

    public function testZlibCompressionRequiresFl3(): void
    {
        $header = new Header([
            'recType' => Header::NORMAL_REC_TYPE,
            'compression' => 2,
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('compression mode 2 requires $FL3');

        $header->write(Buffer::factory());
    }

    public function testValidCompressionHeaderPairsCanBeWritten(): void
    {
        $savBuffer = Buffer::factory();
        new Header(['recType' => Header::NORMAL_REC_TYPE, 'compression' => 1])->write($savBuffer);
        $this->assertSame(176, $savBuffer->position());

        $zsavBuffer = Buffer::factory();
        new Header(['recType' => Header::ZLIB_REC_TYPE, 'compression' => 2])->write($zsavBuffer);
        $this->assertSame(176, $zsavBuffer->position());
    }
}
