<?php

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Info\LongVariableNames;
use SPSS\Sav\Writer;

class NamingTest extends TestCase
{
    /**
     * @return list<array{string}>
     */
    public static function illegalNameProvider(): array
    {
        return [
            ['#FOO'],
            ['$FOO'],
            ['.FOO'],
            ['FOO.'],
            ['FOO_'],
        ];
    }

    public function testReservedNames(): void
    {
        $data = [
            'header'    => [
                'prodName'     => '@(#) IBM SPSS STATISTICS',
                'layoutCode'   => 2,
                'creationDate' => date('d M y'),
                'creationTime' => date('H:i:s'),
            ],
            'variables' => [
                [
                    'name'   => 'WITH',
                    'width'  => 16,
                    'format' => 1,
                ],
                [
                    'name'   => 'OR',
                    'format' => 5,
                ],
            ],
        ];
        $writer = new Writer($data);

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();

        $this->assertMatchesRegularExpression('/^' . $data['variables'][0]['name'] . '[\w]{13}$/', $reader->info[LongVariableNames::SUBTYPE]['V00001']);
        $this->assertMatchesRegularExpression('/^' . $data['variables'][1]['name'] . '[\w]{13}$/', $reader->info[LongVariableNames::SUBTYPE]['V00002']);
    }

    #[DataProvider('illegalNameProvider')]
    public function testIllegalNames(string $name): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(sprintf('Variable name `%s` contains an illegal character.', $name));

        $data = [
            'header'    => [
                'prodName'     => '@(#) IBM SPSS STATISTICS',
                'layoutCode'   => 2,
                'creationDate' => date('d M y'),
                'creationTime' => date('H:i:s'),
            ],
            'variables' => [
                [
                    'name'   => $name,
                    'width'  => 16,
                    'format' => 1,
                ],
            ],
        ];

        new Writer($data);
    }
}
