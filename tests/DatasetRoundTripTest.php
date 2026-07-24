<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Sav\Alignment;
use SPSS\Sav\Dataset;
use SPSS\Sav\FileAttribute;
use SPSS\Sav\FileMetadata;
use SPSS\Sav\FileTechnicalMetadata;
use SPSS\Sav\Measure;
use SPSS\Sav\MissingValues;
use SPSS\Sav\MissingValuesKind;
use SPSS\Sav\MultipleResponseSet;
use SPSS\Sav\MultipleResponseSetType;
use SPSS\Sav\Reader;
use SPSS\Sav\ValueLabel;
use SPSS\Sav\ValueLabelSet;
use SPSS\Sav\Variable;
use SPSS\Sav\VariableAttribute;
use SPSS\Sav\VariableDictionary;
use SPSS\Sav\VariableFormat;
use SPSS\Sav\VariableMetadata;
use SPSS\Sav\VariableRole;
use SPSS\Sav\VariableSet;
use SPSS\Sav\VariableType;
use SPSS\Sav\Writer;

class DatasetRoundTripTest extends TestCase
{
    public function testTypedDatasetWriterReaderRoundTrip(): void
    {
        $numericFormat = new VariableFormat(Variable::FORMAT_TYPE_F, 8, 0);
        $stringFormat = new VariableFormat(Variable::FORMAT_TYPE_A, 12, 0);
        $weight = new VariableMetadata(
            name: 'case_weight',
            type: VariableType::NUMERIC,
            width: 0,
            printFormat: $numericFormat,
            writeFormat: $numericFormat,
            shortName: 'WEIGHT',
            label: 'Case weight',
            valueLabels: new ValueLabelSet([
                new ValueLabel(1, 'One'),
                new ValueLabel(2, 'Two'),
            ], ['case_weight']),
            missingValues: MissingValues::rangeAndValue(-99, -1, 999),
            measure: Measure::SCALE,
            alignment: Alignment::RIGHT,
            columns: 10,
            role: VariableRole::INPUT,
            attributes: [
                new VariableAttribute('case_weight', 'origin', ['typed']),
            ],
            dictionaryIndex: 1,
        );
        $comment = new VariableMetadata(
            name: 'survey_comment',
            type: VariableType::STRING,
            width: 12,
            printFormat: $stringFormat,
            writeFormat: $stringFormat,
            shortName: 'COMMENT',
            label: 'Comment',
            missingValues: MissingValues::discrete('NA'),
            measure: Measure::NOMINAL,
            alignment: Alignment::LEFT,
            columns: 12,
            role: VariableRole::TARGET,
            dictionaryIndex: 2,
        );
        $source = new Dataset(
            dictionary: new VariableDictionary([$weight, $comment]),
            rows: [
                [1.0, 'alpha'],
                [null, 'NA'],
                [2.0, 'beta'],
            ],
            metadata: new FileMetadata(
                label: 'Typed round-trip',
                weightVariableName: 'case_weight',
                documents: ['integration document'],
                attributes: [new FileAttribute('source', ['typed', 'round-trip'])],
                variableSets: [new VariableSet('analysis', ['case_weight', 'survey_comment'])],
                multipleResponseSets: [
                    new MultipleResponseSet(
                        name: '$responses',
                        type: MultipleResponseSetType::CATEGORY,
                        variableNames: ['case_weight'],
                        label: 'Responses',
                    ),
                ],
            ),
            technicalMetadata: new FileTechnicalMetadata(
                sourceFormat: 'sav',
                recordType: '$FL2',
                sourceVersion: '3.0.0',
                provenance: 'typed integration test',
                encoding: 'UTF-8',
                productName: '@(#) SPSS DATA FILE PHP-SPSS 3.0',
                compression: 1,
            ),
        );

        $writer = new Writer($source);
        $buffer = $writer->getBuffer();
        $buffer->rewind();

        $actual = Reader::fromString($buffer->getStream())->readDataset();

        $this->assertSame('Typed round-trip', $actual->metadata->label);
        $this->assertSame('case_weight', $actual->metadata->weightVariableName);
        $this->assertSame(['integration document'], $actual->metadata->documents());
        $this->assertSame(['typed', 'round-trip'], $actual->metadata->attributes()[0]->values());
        $this->assertSame(['case_weight', 'survey_comment'], $actual->metadata->variableSets()[0]->variableNames());
        $this->assertSame(['case_weight'], $actual->metadata->multipleResponseSets()[0]->variableNames());
        $this->assertSame([
            [1.0, 'alpha'],
            [null, 'NA'],
            [2.0, 'beta'],
        ], $actual->rows());

        $actualWeight = $actual->variable('case_weight');
        $actualComment = $actual->variable('survey_comment');
        $this->assertNotNull($actualWeight);
        $this->assertNotNull($actualComment);
        $this->assertSame(VariableRole::INPUT, $actualWeight->role);
        $this->assertSame('typed', $actualWeight->attributes()[0]->values()[0]);
        $this->assertSame('One', $actualWeight->valueLabels->labels()[0]->label);
        $this->assertSame(MissingValuesKind::RANGE_AND_VALUE, $actualWeight->missingValues->kind);
        $this->assertSame(999.0, $actualWeight->missingValues->additionalValue);
        $this->assertSame(VariableRole::TARGET, $actualComment->role);
        $this->assertSame(['NA'], $actualComment->missingValues->discreteValues());
    }
}
