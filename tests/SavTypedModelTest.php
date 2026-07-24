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
use SPSS\Sav\MultipleResponseCategoryLabels;
use SPSS\Sav\MultipleResponseLabelSource;
use SPSS\Sav\MultipleResponseSet;
use SPSS\Sav\MultipleResponseSetType;
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

class SavTypedModelTest extends TestCase
{
    public function testVariableTypesAndEnumsExposeSavCodes(): void
    {
        $this->assertSame(VariableType::NUMERIC, VariableType::fromWidth(0));
        $this->assertSame(VariableType::STRING, VariableType::fromWidth(8));
    }

    public function testVariableTypeRejectsContinuationWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        VariableType::fromWidth(-1);
    }

    public function testVariableFormatKeepsComponentsSeparate(): void
    {
        $format = new VariableFormat(code: Variable::FORMAT_TYPE_F, width: 12, decimals: 2);

        $this->assertSame(Variable::FORMAT_TYPE_F, $format->code);
        $this->assertSame(12, $format->width);
        $this->assertSame(2, $format->decimals);
    }

    public function testVariableFormatRejectsValuesOutsidePackedByte(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new VariableFormat(code: 256, width: 8);
    }

    public function testValueLabelSetPreservesOrderValueTypesAndDuplicates(): void
    {
        $first = new ValueLabel(1.5, 'first');
        $second = new ValueLabel('1.5', 'second');
        $duplicate = new ValueLabel(1.5, 'replacement');
        $set = new ValueLabelSet([$first, $second, $duplicate], ['score', 'score_copy']);

        $this->assertSame([$first, $second, $duplicate], $set->labels());
        $this->assertSame(['score', 'score_copy'], $set->variableNames());
        $this->assertIsFloat($set->labels()[0]->value);
        $this->assertIsString($set->labels()[1]->value);
    }

    public function testMissingValuesRepresentAllSavShapes(): void
    {
        $none = MissingValues::none();
        $discrete = MissingValues::discrete(1, 3, 7);
        $range = MissingValues::range(-10.5, 0);
        $rangeAndValue = MissingValues::rangeAndValue(10, 20, 99);

        $this->assertSame(MissingValuesKind::NONE, $none->kind);
        $this->assertSame([1, 3, 7], $discrete->discreteValues());
        $this->assertSame(MissingValuesKind::RANGE, $range->kind);
        $this->assertSame(-10.5, $range->lower);
        $this->assertSame(0, $range->upper);
        $this->assertSame(MissingValuesKind::RANGE_AND_VALUE, $rangeAndValue->kind);
        $this->assertSame(99, $rangeAndValue->additionalValue);
    }

    public function testMissingValuesRejectMixedDiscreteTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot mix string and numeric');

        MissingValues::discrete(1, '2');
    }

    public function testMissingValuesRejectDescendingRange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('lower bound');

        MissingValues::range(10, 2);
    }

    public function testFileAndVariableMetadataPreserveOrderedValues(): void
    {
        $fileAttribute = new FileAttribute('source', ['survey', 'import']);
        $variableAttribute = new VariableAttribute('score', '$@Role', ['input', 'target']);
        $variableSet = new VariableSet('analysis', ['score', 'group']);
        $multipleResponseSet = new MultipleResponseSet(
            name: '$choices',
            type: MultipleResponseSetType::DICHOTOMY,
            variableNames: ['choice_1', 'choice_2'],
            label: 'Choices',
            countedValue: 1,
        );
        $metadata = new FileMetadata(
            label: 'Survey',
            weightVariableName: 'weight',
            documents: ['first', 'second'],
            attributes: [$fileAttribute],
            variableSets: [$variableSet],
            multipleResponseSets: [$multipleResponseSet],
        );

        $this->assertSame(['survey', 'import'], $fileAttribute->values());
        $this->assertSame(['input', 'target'], $variableAttribute->values());
        $this->assertSame(['score', 'group'], $variableSet->variableNames());
        $this->assertSame(['choice_1', 'choice_2'], $multipleResponseSet->variableNames());
        $this->assertSame(['first', 'second'], $metadata->documents());
        $this->assertSame([$fileAttribute], $metadata->attributes());
        $this->assertSame([$variableSet], $metadata->variableSets());
        $this->assertSame([$multipleResponseSet], $metadata->multipleResponseSets());
    }

    public function testCountedValueMultipleResponseSetSupportsVariableLabelSource(): void
    {
        $set = new MultipleResponseSet(
            name: '$choices',
            type: MultipleResponseSetType::DICHOTOMY,
            variableNames: ['choice_1', 'choice_2'],
            countedValue: 'Yes',
            categoryLabels: MultipleResponseCategoryLabels::COUNTED_VALUES,
            labelSource: MultipleResponseLabelSource::VARIABLE_LABEL,
        );

        $this->assertSame('Yes', $set->countedValue);
        $this->assertSame(MultipleResponseLabelSource::VARIABLE_LABEL, $set->labelSource);
    }

    public function testCategoryMultipleResponseSetRejectsCountedValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new MultipleResponseSet(
            name: '$categories',
            type: MultipleResponseSetType::CATEGORY,
            variableNames: ['category_1', 'category_2'],
            countedValue: 1,
        );
    }

    public function testDatasetKeepsTypedDictionaryAndRowsOrdered(): void
    {
        $score = $this->numericVariable('score', 'SCOR');
        $group = $this->stringVariable('group', 'GRP');
        $dictionary = new VariableDictionary([$score, $group]);
        $metadata = new FileMetadata(label: 'Survey');
        $technicalMetadata = new FileTechnicalMetadata(
            sourceFormat: 'sav',
            recordType: '$FL2',
            sourceVersion: 'IBM SPSS 29',
            provenance: 'examples/data.sav',
            encoding: 'UTF-8',
            caseCount: 2,
            compression: 1,
        );
        $dataset = new Dataset(
            dictionary: $dictionary,
            rows: [[1.5, 'A'], [null, 'B']],
            metadata: $metadata,
            technicalMetadata: $technicalMetadata,
        );

        $this->assertSame([$score, $group], $dataset->variables());
        $this->assertSame($score, $dataset->variable('SCORE'));
        $this->assertSame($group, $dataset->variable('GRP'));
        $this->assertNull($dataset->variable('missing'));
        $this->assertSame([null, 'B'], $dataset->row(1));
        $this->assertSame(2, $dataset->rowCount());
        $this->assertSame('$FL2', $dataset->technicalMetadata->recordType);
        $this->assertSame('examples/data.sav', $dataset->technicalMetadata->provenance);
    }

    public function testDictionaryRejectsDuplicateNamesCaseInsensitively(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate dictionary variable name');

        new VariableDictionary([
            $this->numericVariable('score'),
            $this->numericVariable('SCORE'),
        ]);
    }

    public function testDatasetRejectsRowsWithWrongWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('one value per variable');

        new Dataset(
            dictionary: new VariableDictionary([$this->numericVariable('score')]),
            rows: [[1, 2]],
        );
    }

    public function testVariableMetadataKeepsTypedDictionaryFields(): void
    {
        $valueLabels = new ValueLabelSet([new ValueLabel(1, 'Yes')], ['answer']);
        $missingValues = MissingValues::rangeAndValue(-99, -1, 999);
        $attribute = new VariableAttribute('answer', '$@Role', ['target']);
        $variable = new VariableMetadata(
            name: 'answer',
            type: VariableType::NUMERIC,
            width: 0,
            printFormat: new VariableFormat(Variable::FORMAT_TYPE_F, 8, 0),
            writeFormat: new VariableFormat(Variable::FORMAT_TYPE_E, 12, 2),
            shortName: 'ANSWER',
            label: 'Answer',
            valueLabels: $valueLabels,
            missingValues: $missingValues,
            measure: Measure::NOMINAL,
            alignment: Alignment::RIGHT,
            columns: 12,
            role: VariableRole::TARGET,
            attributes: [$attribute],
            dictionaryIndex: 1,
        );

        $this->assertSame(VariableType::NUMERIC, $variable->type);
        $this->assertSame(Variable::FORMAT_TYPE_F, $variable->printFormat->code);
        $this->assertSame(Variable::FORMAT_TYPE_E, $variable->writeFormat->code);
        $this->assertSame($valueLabels, $variable->valueLabels);
        $this->assertSame($missingValues, $variable->missingValues);
        $this->assertSame([$attribute], $variable->attributes());
    }

    public function testVariableMetadataValidatesStorageWidthAgainstType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('storage width 0');

        $format = new VariableFormat(Variable::FORMAT_TYPE_F, 8);
        new VariableMetadata(
            name: 'score',
            type: VariableType::NUMERIC,
            width: 8,
            printFormat: $format,
            writeFormat: $format,
        );
    }

    private function numericVariable(string $name, ?string $shortName = null): VariableMetadata
    {
        $format = new VariableFormat(Variable::FORMAT_TYPE_F, 8);

        return new VariableMetadata(
            name: $name,
            type: VariableType::NUMERIC,
            width: 0,
            printFormat: $format,
            writeFormat: $format,
            shortName: $shortName,
        );
    }

    private function stringVariable(string $name, ?string $shortName = null): VariableMetadata
    {
        $format = new VariableFormat(Variable::FORMAT_TYPE_A, 8);

        return new VariableMetadata(
            name: $name,
            type: VariableType::STRING,
            width: 8,
            printFormat: $format,
            writeFormat: $format,
            shortName: $shortName,
        );
    }
}
