<?php

declare(strict_types=1);

namespace SPSS\Tests;

use SPSS\Sav\MissingValuesKind;
use SPSS\Sav\MultipleResponseCategoryLabels;
use SPSS\Sav\MultipleResponseLabelSource;
use SPSS\Sav\MultipleResponseSetType;
use SPSS\Sav\Reader;
use SPSS\Sav\Record\Info\DataFileAttributes;
use SPSS\Sav\Record\Info\LongStringMissingValues;
use SPSS\Sav\Record\Info\LongStringValueLabels;
use SPSS\Sav\Record\Info\MultipleResponseSets;
use SPSS\Sav\Record\Info\VariableAttributes;
use SPSS\Sav\Record\Info\VariableSets;
use SPSS\Sav\VariableRole;
use SPSS\Sav\VariableType;

class DatasetAssemblerTest extends TestCase
{
    public function testReadsFixtureAsTypedDataset(): void
    {
        $dataset = Reader::fromFile(__DIR__ . '/../examples/data.sav')->readDataset();

        $this->assertCount(3, $dataset->variables());
        $this->assertSame(3, $dataset->rowCount());
        $this->assertSame([1.0, 'foo', 1.0], $dataset->row(0));

        $numeric = $dataset->variables()[0];
        $string = $dataset->variables()[1];
        $this->assertSame('aaa', $numeric->name);
        $this->assertSame('V00001', $numeric->shortName);
        $this->assertSame(VariableType::NUMERIC, $numeric->type);
        $this->assertSame(0, $numeric->width);
        $this->assertSame(1, $numeric->dictionaryIndex);
        $this->assertSame('bbbb_bbbbbb12', $string->name);
        $this->assertSame(VariableType::STRING, $string->type);
        $this->assertSame(28, $string->width);
        $this->assertSame(2, $string->dictionaryIndex);

        $this->assertSame('sav', $dataset->technicalMetadata->sourceFormat);
        $this->assertSame('$FL2', $dataset->technicalMetadata->recordType);
        $this->assertSame('1.0.0', $dataset->technicalMetadata->sourceVersion);
        $this->assertSame(__DIR__ . '/../examples/data.sav', $dataset->technicalMetadata->provenance);
        $this->assertSame('UTF-8', $dataset->technicalMetadata->encoding);
        $this->assertSame(3, $dataset->technicalMetadata->caseCount);
    }

    public function testMetadataOnlyConversionDoesNotReadOrExposeRows(): void
    {
        $reader = Reader::fromFile(__DIR__ . '/../examples/data.sav')->readMetaData();
        $dataset = $reader->toDataset(false);

        $this->assertCount(3, $dataset->variables());
        $this->assertSame([], $dataset->rows());

        $this->expectException(\LogicException::class);
        $reader->toDataset();
    }

    public function testAssemblesSemanticExtensionRecords(): void
    {
        $reader = Reader::fromFile(__DIR__ . '/../examples/data.sav')->readMetaData();
        $numericShortName = $reader->variables[0]->name;
        $stringShortName = $reader->variables[1]->name;
        $reader->variables[0]->missingValuesFormat = -3;
        $reader->variables[0]->missingValues = [-99.0, -1.0, 999.0];
        $this->assertNotNull($reader->header);
        $reader->header->weightIndex = 1;

        $fileAttributes = new DataFileAttributes([
            'data' => [
                'source' => ['fixture', 'integration'],
            ],
        ]);
        $variableAttributes = new VariableAttributes([
            'data' => [
                'aaa' => [
                    '$@Role' => ['4'],
                    'custom' => ['first', 'second'],
                ],
            ],
        ]);
        $variableSets = new VariableSets([
            'data' => [
                'analysis' => ['aaa', 'bbbb_bbbbbb12'],
            ],
        ]);
        $legacyResponseSets = new MultipleResponseSets([
            'subtype' => MultipleResponseSets::SUBTYPE,
            'data' => [
                '$category' => [
                    'type' => 'C',
                    'countedValue' => null,
                    'label' => 'Categories',
                    'labelSource' => null,
                    'variables' => [strtolower($numericShortName)],
                ],
            ],
        ]);
        $countedResponseSets = new MultipleResponseSets([
            'subtype' => MultipleResponseSets::COUNTED_VALUES_SUBTYPE,
            'data' => [
                '$counted' => [
                    'type' => 'E',
                    'countedValue' => '1',
                    'label' => '',
                    'labelSource' => 11,
                    'variables' => [strtolower($numericShortName)],
                ],
            ],
        ]);
        $longLabels = new LongStringValueLabels([
            'data' => [
                'bbbb_bbbbbb12' => [
                    'width' => 28,
                    'values' => ['foo' => 'Foo label'],
                ],
            ],
        ]);
        $longMissing = new LongStringMissingValues([
            'data' => [
                $stringShortName => ['NA'],
            ],
        ]);

        $reader->info[DataFileAttributes::SUBTYPE] = $fileAttributes;
        $reader->info[VariableAttributes::SUBTYPE] = $variableAttributes;
        $reader->mergedInfo[VariableAttributes::SUBTYPE] = $variableAttributes;
        $reader->info[VariableSets::SUBTYPE] = $variableSets;
        $reader->info[MultipleResponseSets::SUBTYPE] = $legacyResponseSets;
        $reader->info[MultipleResponseSets::COUNTED_VALUES_SUBTYPE] = $countedResponseSets;
        $reader->info[LongStringValueLabels::SUBTYPE] = $longLabels;
        $reader->info[LongStringMissingValues::SUBTYPE] = $longMissing;

        $dataset = $reader->toDataset(false);
        $numeric = $dataset->variable('aaa');
        $string = $dataset->variable('bbbb_bbbbbb12');

        $this->assertNotNull($numeric);
        $this->assertNotNull($string);
        $this->assertSame(VariableRole::PARTITION, $numeric->role);
        $this->assertSame(MissingValuesKind::RANGE_AND_VALUE, $numeric->missingValues->kind);
        $this->assertSame(-99.0, $numeric->missingValues->lower);
        $this->assertSame(999.0, $numeric->missingValues->additionalValue);
        $this->assertSame(['first', 'second'], $numeric->attributes()[1]->values());
        $this->assertSame('foo', $string->valueLabels->labels()[0]->value);
        $this->assertSame('Foo label', $string->valueLabels->labels()[0]->label);
        $this->assertSame(MissingValuesKind::DISCRETE, $string->missingValues->kind);
        $this->assertSame(['NA'], $string->missingValues->discreteValues());

        $this->assertSame('aaa', $dataset->metadata->weightVariableName);
        $this->assertSame(['fixture', 'integration'], $dataset->metadata->attributes()[0]->values());
        $this->assertSame(['aaa', 'bbbb_bbbbbb12'], $dataset->metadata->variableSets()[0]->variableNames());

        [$category, $counted] = $dataset->metadata->multipleResponseSets();
        $this->assertSame(MultipleResponseSetType::CATEGORY, $category->type);
        $this->assertSame(['aaa'], $category->variableNames());
        $this->assertSame(MultipleResponseCategoryLabels::COUNTED_VALUES, $counted->categoryLabels);
        $this->assertSame(MultipleResponseLabelSource::VARIABLE_LABEL, $counted->labelSource);
        $this->assertSame(1, $counted->countedValue);
    }
}
