<?php

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Sav\Reader;
use SPSS\Sav\Record;
use SPSS\Sav\Writer;
use SPSS\Utils;

/**
 * @phpstan-type RandomDataset array{header: array<string, bool|float|int|string|null>, variables: list<array{name: string, label: string, columns: int, alignment: int, measure: int, width: int, format: int, decimals: int, data: list<string>}>, documents: list<string>}
 */
class SavRandomReadWriteTest extends TestCase
{
    /**
     * @return \Generator<int, array{0: RandomDataset}>
     */
    public static function provider(): iterable
    {
        mt_srand(20260724);

        $header = [
            'recType'         => Record\Header::NORMAL_REC_TYPE,
            'prodName'        => '@(#) SPSS DATA FILE',
            'layoutCode'      => 2,
            'nominalCaseSize' => 0,
            'casesCount'      => mt_rand(10, 100),
            'compression'     => 1,
            'weightIndex'     => 0,
            'bias'            => 100,
            'creationDate'    => '24 Jul 26',
            'creationTime'    => '12:00:00',
            'fileLabel'       => 'test read/write',
        ];

        $documents = [
            self::generateRandomString(mt_rand(5, Record\Document::LENGTH)),
            self::generateRandomString(mt_rand(5, Record\Document::LENGTH)),
        ];

        $variables = [];

        // Generate random variables

        $count = 1; // mt_rand(1, 20);
        for ($i = 0; $i < $count; $i++) {
            $var = self::generateVariable(
                [
                    'id'         => self::generateRandomString(mt_rand(2, 100)) . 'a',
                    'casesCount' => $header['casesCount'],
                ],
            );
            $header['nominalCaseSize'] += Utils::widthToOcts($var['width']);
            $variables[] = $var;
        }

        yield [['header' => $header, 'variables' => $variables, 'documents' => $documents]];

        $header['casesCount'] = 5;
        for ($i = 0; $i < 100; $i++) {
            $variable = self::generateVariable([
                'id'         => self::generateRandomString(mt_rand(2, 100)) . 'a',
                'casesCount' => $header['casesCount'],
            ]);
            $header['nominalCaseSize'] = Utils::widthToOcts($variable['width']);
            yield [
                [
                    'header'    => $header,
                    'variables' => [$variable],
                    'documents' => $documents,
                ],
            ];
        }
    }

    /**
     * @param RandomDataset $data
     */
    #[DataProvider('provider')]
    public function testWriteRead(array $data): void
    {
        $writer = new Writer($data);

        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $reader = Reader::fromString($buffer->getStream())->read();

        $this->checkHeader($data['header'], $reader);

        if ([] !== $data['documents']) {
            foreach ($data['documents'] as $key => $doc) {
                $this->assertEquals($doc, $reader->documents[$key], 'Invalid document line.');
            }
        }

        if (isset($reader->info[Record\Info\VeryLongString::SUBTYPE])) {
            $veryLongStrings = $reader->info[Record\Info\VeryLongString::SUBTYPE]->toArray();
        } else {
            $veryLongStrings = [];
        }

        $index = 0;

        foreach ($data['variables'] as $var) {
            /** @var Record\Variable $readVariable */
            $readVariable = $reader->variables[$index];

            $this->assertEquals($var['label'], $readVariable->label);
            $this->assertEquals($var['format'], $readVariable->print[1]);
            $this->assertEquals($var['decimals'], $readVariable->print[3]);

            // Check variable data

            foreach ($var['data'] as $case => $value) {
                $this->assertEquals($value, $reader->data[$case][$index]);
            }

            $index += isset($veryLongStrings[$readVariable->name]) ?
                Utils::widthToSegments($veryLongStrings[$readVariable->name]) : 1;
        }

        // TODO: valueLabels
        // TODO: info
    }
}
