<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Variable;

class SavFixtureTest extends TestCase
{
    public function testReadsKnownSavFixture(): void
    {
        $reader = Reader::fromFile(__DIR__ . '/../examples/data.sav')->read();

        $this->assertSame(3, $reader->header->casesCount);
        $this->assertCount(3, $reader->variables);
        $this->assertCount(3, $reader->data);
        $this->assertSame('V00001', $reader->variables[0]->name);
        $this->assertSame('foo', $reader->data[0][1]);
    }

    public function testVariableRejectsUnknownProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown SPSS\Sav\Variable property "unknown".');

        new Variable(['unknown' => true]);
    }

    public function testRecordRejectsUnknownProperties(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown SPSS\Sav\Record\Header property "unknown".');

        new Header(['unknown' => true]);
    }
}
