<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\FileTechnicalMetadata;
use SPSS\Sav\MissingValues;
use SPSS\Sav\MissingValuesKind;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\ValueLabel;
use SPSS\Sav\ValueLabelSet;
use SPSS\Sav\Variable;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableType;
use SPSS\Sav\Writer;

final class OpenStatSpecAcceptanceTest extends TestCase
{
    #[DataProvider('savContainerProvider')]
    public function testTypedSemanticReadWriteReadPreservesAcceptanceDataset(
        string $recordType,
        int $compression,
    ): void {
        $longUtf8Value = str_repeat('Õ', 320);
        self::assertSame(320, mb_strlen($longUtf8Value));

        $source = $this->acceptanceDataset($recordType, $compression, $longUtf8Value);
        $firstRead = $this->writeAndRead($source);
        $secondRead = $this->writeAndRead($firstRead);

        self::assertSame($recordType, $firstRead->technicalMetadata->recordType);
        self::assertSame($compression, $firstRead->technicalMetadata->compression);
        self::assertSame($this->semanticSnapshot($firstRead), $this->semanticSnapshot($secondRead));

        self::assertSame('', $firstRead->row(0)[5]);
        self::assertSame('', $firstRead->row(2)[8]);
        self::assertSame($longUtf8Value, $firstRead->row(0)[8]);

        $this->assertMissingValues($firstRead);
        $this->assertValueLabels($firstRead);
    }

    /** @return iterable<string, array{string, int}> */
    public static function savContainerProvider(): iterable
    {
        yield 'SAV bytecode compression' => [Header::NORMAL_REC_TYPE, 1];
        yield 'ZSAV ZLIB compression' => [Header::ZLIB_REC_TYPE, 2];
    }

    private function acceptanceDataset(string $recordType, int $compression, string $longUtf8Value): Dataset
    {
        $numericFormat = new VariableFormat(Variable::FORMAT_TYPE_F, 8, 2);
        $shortStringFormat = new VariableFormat(Variable::FORMAT_TYPE_A, 8);
        $longStringFormat = new VariableFormat(Variable::FORMAT_TYPE_A, 255);

        $numeric = static function (
            string $name,
            MissingValues $missingValues,
            ?ValueLabelSet $valueLabels = null,
        ) use ($numericFormat): VariableMetadata {
            return new VariableMetadata(
                name: $name,
                type: VariableType::NUMERIC,
                width: 0,
                printFormat: $numericFormat,
                writeFormat: $numericFormat,
                valueLabels: $valueLabels,
                missingValues: $missingValues,
            );
        };

        $variables = [
            $numeric('missing_one', MissingValues::discrete(-1)),
            $numeric('missing_two', MissingValues::discrete(-1, -2)),
            $numeric('missing_three', MissingValues::discrete(-1, -2, -3)),
            $numeric('missing_range', MissingValues::range(-10, -1)),
            $numeric('missing_range_value', MissingValues::rangeAndValue(-10, -1, 999)),
            new VariableMetadata(
                name: 'empty_short',
                type: VariableType::STRING,
                width: 8,
                printFormat: $shortStringFormat,
                writeFormat: $shortStringFormat,
            ),
            $numeric(
                'numeric_labels',
                MissingValues::none(),
                new ValueLabelSet([
                    new ValueLabel(2.5, 'Two and a half'),
                    new ValueLabel(1.0, 'One'),
                ], ['numeric_labels']),
            ),
            new VariableMetadata(
                name: 'short_labels',
                type: VariableType::STRING,
                width: 8,
                printFormat: $shortStringFormat,
                writeFormat: $shortStringFormat,
                valueLabels: new ValueLabelSet([
                    new ValueLabel('B', 'Beta'),
                    new ValueLabel('A', 'Alpha'),
                ], ['short_labels']),
            ),
            new VariableMetadata(
                name: 'long_utf8',
                type: VariableType::STRING,
                width: 700,
                printFormat: $longStringFormat,
                writeFormat: $longStringFormat,
                valueLabels: new ValueLabelSet([
                    new ValueLabel('võti/üks', 'Esimene'),
                    new ValueLabel('võti:kaks', 'Teine'),
                ], ['long_utf8']),
            ),
        ];

        return new Dataset(
            dictionary: new VariableDictionary($variables),
            rows: [
                [-1, -1, -1, -10, -10, '', 2.5, 'B', $longUtf8Value],
                [0, -2, -2, -5, 999, 'text', 1, 'A', 'võti/üks'],
                [null, 0, -3, 0, 0, 'NA', null, '', ''],
            ],
            metadata: new FileMetadata(label: 'OpenStatSpec acceptance'),
            technicalMetadata: new FileTechnicalMetadata(
                sourceFormat: 'sav',
                recordType: $recordType,
                encoding: 'UTF-8',
                compression: $compression,
            ),
        );
    }

    private function writeAndRead(Dataset $dataset): Dataset
    {
        $writer = new Writer($dataset);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        return Reader::fromString($buffer->getStream())->readDataset();
    }

    private function assertMissingValues(Dataset $dataset): void
    {
        $expected = [
            'missing_one' => [MissingValuesKind::DISCRETE, [-1.0], null, null, null],
            'missing_two' => [MissingValuesKind::DISCRETE, [-1.0, -2.0], null, null, null],
            'missing_three' => [MissingValuesKind::DISCRETE, [-1.0, -2.0, -3.0], null, null, null],
            'missing_range' => [MissingValuesKind::RANGE, [], -10.0, -1.0, null],
            'missing_range_value' => [MissingValuesKind::RANGE_AND_VALUE, [], -10.0, -1.0, 999.0],
        ];

        foreach ($expected as $name => [$kind, $discrete, $lower, $upper, $additional]) {
            $variable = $dataset->variable($name);
            self::assertNotNull($variable);
            self::assertSame($kind, $variable->missingValues->kind);
            self::assertSame($discrete, $variable->missingValues->discreteValues());
            self::assertSame($lower, $variable->missingValues->lower);
            self::assertSame($upper, $variable->missingValues->upper);
            self::assertSame($additional, $variable->missingValues->additionalValue);
        }
    }

    private function assertValueLabels(Dataset $dataset): void
    {
        self::assertSame(
            [
                ['type' => 'float', 'value' => 2.5, 'label' => 'Two and a half'],
                ['type' => 'float', 'value' => 1.0, 'label' => 'One'],
            ],
            $this->valueLabelSnapshot($dataset, 'numeric_labels'),
        );
        self::assertSame(
            [
                ['type' => 'string', 'value' => 'B', 'label' => 'Beta'],
                ['type' => 'string', 'value' => 'A', 'label' => 'Alpha'],
            ],
            $this->valueLabelSnapshot($dataset, 'short_labels'),
        );
        self::assertSame(
            [
                ['type' => 'string', 'value' => 'võti/üks', 'label' => 'Esimene'],
                ['type' => 'string', 'value' => 'võti:kaks', 'label' => 'Teine'],
            ],
            $this->valueLabelSnapshot($dataset, 'long_utf8'),
        );
    }

    /** @return array<string, mixed> */
    private function semanticSnapshot(Dataset $dataset): array
    {
        $variables = [];
        foreach ($dataset->variables() as $variable) {
            $variables[] = [
                'name' => $variable->name,
                'type' => $variable->type->value,
                'width' => $variable->width,
                'printFormat' => [
                    $variable->printFormat->code,
                    $variable->printFormat->width,
                    $variable->printFormat->decimals,
                ],
                'writeFormat' => [
                    $variable->writeFormat->code,
                    $variable->writeFormat->width,
                    $variable->writeFormat->decimals,
                ],
                'missingValues' => [
                    $variable->missingValues->kind->value,
                    $variable->missingValues->discreteValues(),
                    $variable->missingValues->lower,
                    $variable->missingValues->upper,
                    $variable->missingValues->additionalValue,
                ],
                'valueLabels' => $this->valueLabelSnapshot($dataset, $variable->name),
            ];
        }

        return [
            'recordType' => $dataset->technicalMetadata->recordType,
            'compression' => $dataset->technicalMetadata->compression,
            'encoding' => $dataset->technicalMetadata->encoding,
            'rows' => $dataset->rows(),
            'variables' => $variables,
        ];
    }

    /** @return list<array{type: string, value: int|float|string, label: string}> */
    private function valueLabelSnapshot(Dataset $dataset, string $variableName): array
    {
        $variable = $dataset->variable($variableName);
        self::assertNotNull($variable);
        $labels = [];
        foreach ($variable->valueLabels->labels() as $label) {
            $labels[] = [
                'type' => get_debug_type($label->value),
                'value' => $label->value,
                'label' => $label->label,
            ];
        }

        return $labels;
    }
}
