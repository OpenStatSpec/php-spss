<?php

declare(strict_types=1);

namespace SPSS\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testLegacyVariableFormatCatalogAndClassifiers(): void
    {
        $formats = [
            0 => '',
            Variable::FORMAT_TYPE_A => 'A',
            Variable::FORMAT_TYPE_AHEX => 'AHEX',
            Variable::FORMAT_TYPE_COMMA => 'COMMA',
            Variable::FORMAT_TYPE_DOLLAR => 'DOLLAR',
            Variable::FORMAT_TYPE_F => 'F',
            Variable::FORMAT_TYPE_IB => 'IB',
            Variable::FORMAT_TYPE_PIBHEX => 'PIBHEX',
            Variable::FORMAT_TYPE_P => 'P',
            Variable::FORMAT_TYPE_PIB => 'PIB',
            Variable::FORMAT_TYPE_PK => 'PK',
            Variable::FORMAT_TYPE_RB => 'RB',
            Variable::FORMAT_TYPE_RBHEX => 'RBHEX',
            Variable::FORMAT_TYPE_Z => 'Z',
            Variable::FORMAT_TYPE_N => 'N',
            Variable::FORMAT_TYPE_E => 'E',
            Variable::FORMAT_TYPE_DATE => 'DATE',
            Variable::FORMAT_TYPE_TIME => 'TIME',
            Variable::FORMAT_TYPE_DATETIME => 'DATETIME',
            Variable::FORMAT_TYPE_ADATE => 'ADATE',
            Variable::FORMAT_TYPE_JDATE => 'JDATE',
            Variable::FORMAT_TYPE_DTIME => 'DTIME',
            Variable::FORMAT_TYPE_WKDAY => 'WKDAY',
            Variable::FORMAT_TYPE_MONTH => 'MONTH',
            Variable::FORMAT_TYPE_MOYR => 'MOYR',
            Variable::FORMAT_TYPE_QYR => 'QYR',
            Variable::FORMAT_TYPE_WKYR => 'WKYR',
            Variable::FORMAT_TYPE_PCT => 'PCT',
            Variable::FORMAT_TYPE_DOT => 'DOT',
            Variable::FORMAT_TYPE_CCA => 'CCA',
            Variable::FORMAT_TYPE_CCB => 'CCB',
            Variable::FORMAT_TYPE_CCC => 'CCC',
            Variable::FORMAT_TYPE_CCD => 'CCD',
            Variable::FORMAT_TYPE_CCE => 'CCE',
            Variable::FORMAT_TYPE_EDATE => 'EDATE',
            Variable::FORMAT_TYPE_SDATE => 'SDATE',
        ];

        foreach ($formats as $code => $abbreviation) {
            [$actualAbbreviation, $meaning] = Variable::getFormatInfo($code);
            self::assertSame($abbreviation, $actualAbbreviation);
            self::assertNotNull($meaning);
        }

        self::assertSame([null, null], Variable::getFormatInfo(255));
        self::assertFalse(Variable::isNumberFormat(0));
        self::assertFalse(Variable::isNumberFormat(Variable::FORMAT_TYPE_A));
        self::assertFalse(Variable::isNumberFormat(Variable::FORMAT_TYPE_AHEX));
        self::assertTrue(Variable::isNumberFormat(Variable::FORMAT_TYPE_F));
        self::assertTrue(Variable::isStringFormat(Variable::FORMAT_TYPE_A));
        self::assertTrue(Variable::isStringFormat(Variable::FORMAT_TYPE_AHEX));
        self::assertFalse(Variable::isStringFormat(Variable::FORMAT_TYPE_F));
        self::assertSame('Left', Variable::alignmentToString(Variable::ALIGN_LEFT));
        self::assertSame('Right', Variable::alignmentToString(Variable::ALIGN_RIGHT));
        self::assertSame('Center', Variable::alignmentToString(Variable::ALIGN_CENTER));
        self::assertSame('Invalid', Variable::alignmentToString(99));
    }

    public function testLegacyVariableConstructorAndDerivedDisplayDefaults(): void
    {
        $printFormat = new VariableFormat(Variable::FORMAT_TYPE_F, 12, 2);
        $writeFormat = new VariableFormat(Variable::FORMAT_TYPE_E, 14, 4);
        $valueLabelSet = new ValueLabelSet([new ValueLabel(1, 'Yes')], ['score']);
        $missingValues = MissingValues::discrete(-99);
        $data = [
            'name' => 'score',
            'type' => VariableType::NUMERIC,
            'width' => 0,
            'decimals' => 2,
            'format' => Variable::FORMAT_TYPE_F,
            'printFormat' => $printFormat,
            'writeFormat' => $writeFormat,
            'columns' => 12,
            'alignment' => Variable::ALIGN_CENTER,
            'measure' => Variable::MEASURE_SCALE,
            'role' => Variable::ROLE_TARGET,
            'label' => 'Score',
            'values' => [1 => 'Yes'],
            'valueLabelSet' => $valueLabelSet,
            'missing' => [-99],
            'missingValues' => $missingValues,
            'attributes' => ['source' => ['survey']],
            'data' => [1, null],
        ];

        $variable = new Variable($data);

        self::assertSame($data['name'], $variable->name);
        self::assertSame($data['type'], $variable->type);
        self::assertSame($data['width'], $variable->width);
        self::assertSame($data['decimals'], $variable->decimals);
        self::assertSame($data['format'], $variable->format);
        self::assertSame($data['printFormat'], $variable->printFormat);
        self::assertSame($data['writeFormat'], $variable->writeFormat);
        self::assertSame($data['columns'], $variable->columns);
        self::assertSame($data['alignment'], $variable->alignment);
        self::assertSame($data['measure'], $variable->measure);
        self::assertSame($data['role'], $variable->role);
        self::assertSame($data['label'], $variable->label);
        self::assertSame($data['values'], $variable->values);
        self::assertSame($data['valueLabelSet'], $variable->valueLabelSet);
        self::assertSame($data['missing'], $variable->missing);
        self::assertSame($data['missingValues'], $variable->missingValues);
        self::assertSame($data['attributes'], $variable->attributes);
        self::assertSame($data['data'], $variable->data);
        self::assertSame(Variable::MEASURE_SCALE, $variable->getMeasure());
        self::assertSame(Variable::ALIGN_CENTER, $variable->getAlignment());
        self::assertSame(12, $variable->getColumns());

        $numericDefaults = new Variable(['width' => 0]);
        self::assertSame(Variable::MEASURE_UNKNOWN, $numericDefaults->getMeasure());
        self::assertSame(Variable::ALIGN_RIGHT, $numericDefaults->getAlignment());
        self::assertSame(8, $numericDefaults->getColumns());

        $stringDefaults = new Variable(['width' => 8]);
        self::assertSame(Variable::MEASURE_NOMINAL, $stringDefaults->getMeasure());
        self::assertSame(Variable::ALIGN_LEFT, $stringDefaults->getAlignment());
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

    public function testVariableFormatAcceptsPackedByteBoundariesAndDefaultDecimals(): void
    {
        $minimum = new VariableFormat(0, 0);
        $maximum = new VariableFormat(255, 255, 255);

        self::assertSame(0, $minimum->code);
        self::assertSame(0, $minimum->width);
        self::assertSame(0, $minimum->decimals);
        self::assertSame(255, $maximum->code);
        self::assertSame(255, $maximum->width);
        self::assertSame(255, $maximum->decimals);
    }

    public function testVariableMetadataAcceptsOpenStatSpecBoundaries(): void
    {
        $format = new VariableFormat(Variable::FORMAT_TYPE_A, 8);
        $minimum = new VariableMetadata(
            name: 'minimum',
            type: VariableType::STRING,
            width: 1,
            printFormat: $format,
            writeFormat: $format,
            columns: 0,
            dictionaryIndex: 1,
        );
        $maximum = new VariableMetadata(
            name: 'maximum',
            type: VariableType::STRING,
            width: 32_767,
            printFormat: $format,
            writeFormat: $format,
            dictionaryIndex: 2,
        );

        self::assertSame(1, $minimum->width);
        self::assertSame(0, $minimum->columns);
        self::assertSame(32_767, $maximum->width);
        self::assertSame(8, $maximum->columns);
    }

    /** @return iterable<string, array{string, VariableType, int, string|null, int, int|null, string}> */
    public static function invalidVariableMetadataProvider(): iterable
    {
        yield 'whitespace name' => [' ', VariableType::NUMERIC, 0, null, 8, null, 'name cannot be empty'];
        yield 'whitespace short name' => ['name', VariableType::NUMERIC, 0, ' ', 8, null, 'short variable name'];
        yield 'numeric storage width' => ['name', VariableType::NUMERIC, 1, null, 8, null, 'storage width 0'];
        yield 'zero string width' => ['name', VariableType::STRING, 0, null, 8, null, 'between 1 and 32767'];
        yield 'string width above maximum' => ['name', VariableType::STRING, 32_768, null, 8, null, 'between 1 and 32767'];
        yield 'negative display columns' => ['name', VariableType::NUMERIC, 0, null, -1, null, 'cannot be negative'];
        yield 'zero dictionary index' => ['name', VariableType::NUMERIC, 0, null, 8, 0, 'must be 1-based'];
    }

    #[DataProvider('invalidVariableMetadataProvider')]
    public function testVariableMetadataRejectsInvalidOpenStatSpecValues(
        string $name,
        VariableType $type,
        int $width,
        ?string $shortName,
        int $columns,
        ?int $dictionaryIndex,
        string $message,
    ): void {
        $format = new VariableFormat(
            VariableType::STRING === $type ? Variable::FORMAT_TYPE_A : Variable::FORMAT_TYPE_F,
            8,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new VariableMetadata(
            name: $name,
            type: $type,
            width: $width,
            printFormat: $format,
            writeFormat: $format,
            shortName: $shortName,
            columns: $columns,
            dictionaryIndex: $dictionaryIndex,
        );
    }

    public function testTechnicalMetadataAcceptsZeroCountsAndWireCodeBoundaries(): void
    {
        $uncompressed = new FileTechnicalMetadata(
            caseCount: 0,
            nominalCaseSize: 0,
            compression: 0,
            endianness: 1,
        );
        $compressed = new FileTechnicalMetadata(compression: 1, endianness: 2);
        $zsav = new FileTechnicalMetadata(compression: 2);

        self::assertSame(0, $uncompressed->caseCount);
        self::assertSame(0, $uncompressed->nominalCaseSize);
        self::assertSame(0, $uncompressed->compression);
        self::assertSame(1, $uncompressed->endianness);
        self::assertSame(1, $compressed->compression);
        self::assertSame(2, $compressed->endianness);
        self::assertSame(2, $zsav->compression);
    }

    /** @return iterable<string, array{string, int|string, string}> */
    public static function invalidTechnicalMetadataProvider(): iterable
    {
        yield 'source format whitespace' => ['sourceFormat', ' ', 'Source format'];
        yield 'record type whitespace' => ['recordType', ' ', 'Record type'];
        yield 'source version whitespace' => ['sourceVersion', ' ', 'Source version'];
        yield 'provenance whitespace' => ['provenance', ' ', 'Provenance'];
        yield 'encoding whitespace' => ['encoding', ' ', 'encoding'];
        yield 'negative case count' => ['caseCount', -1, 'Case count'];
        yield 'negative nominal size' => ['nominalCaseSize', -1, 'Nominal case size'];
        yield 'compression below range' => ['compression', -1, 'Compression'];
        yield 'compression above range' => ['compression', 3, 'Compression'];
        yield 'endianness below range' => ['endianness', 0, 'Endianness'];
        yield 'endianness above range' => ['endianness', 3, 'Endianness'];
    }

    #[DataProvider('invalidTechnicalMetadataProvider')]
    public function testTechnicalMetadataRejectsInvalidWireValues(
        string $field,
        int|string $value,
        string $message,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        match ($field) {
            'sourceFormat' => new FileTechnicalMetadata(sourceFormat: (string) $value),
            'recordType' => new FileTechnicalMetadata(recordType: (string) $value),
            'sourceVersion' => new FileTechnicalMetadata(sourceVersion: (string) $value),
            'provenance' => new FileTechnicalMetadata(provenance: (string) $value),
            'encoding' => new FileTechnicalMetadata(encoding: (string) $value),
            'caseCount' => new FileTechnicalMetadata(caseCount: (int) $value),
            'nominalCaseSize' => new FileTechnicalMetadata(nominalCaseSize: (int) $value),
            'compression' => new FileTechnicalMetadata(compression: (int) $value),
            'endianness' => new FileTechnicalMetadata(endianness: (int) $value),
            default => throw new \LogicException('Unknown fixture field.'),
        };
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidFileMetadataProvider(): iterable
    {
        yield 'whitespace weight variable' => ['weight', 'weight variable'];
        yield 'documents must be a list' => ['documents-list', 'documents must be a list'];
        yield 'documents validate members' => ['documents-type', 'documents contain an invalid value'];
        yield 'attributes validate members' => ['attributes-type', 'attributes contain an invalid value'];
        yield 'variable sets validate members' => ['sets-type', 'variable sets contain an invalid value'];
        yield 'response sets validate members' => ['responses-type', 'multiple-response sets contain an invalid value'];
    }

    #[DataProvider('invalidFileMetadataProvider')]
    public function testFileMetadataRejectsInvalidCollections(string $fixture, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        match ($fixture) {
            'weight' => new FileMetadata(weightVariableName: ' '),
            // @phpstan-ignore argument.type
            'documents-list' => new FileMetadata(documents: ['key' => 'value']),
            // @phpstan-ignore argument.type
            'documents-type' => new FileMetadata(documents: [1]),
            // @phpstan-ignore argument.type
            'attributes-type' => new FileMetadata(attributes: ['invalid']),
            // @phpstan-ignore argument.type
            'sets-type' => new FileMetadata(variableSets: ['invalid']),
            // @phpstan-ignore argument.type
            'responses-type' => new FileMetadata(multipleResponseSets: ['invalid']),
            default => throw new \LogicException('Unknown file metadata fixture.'),
        };
    }

    public function testMissingValuesAcceptCardinalityAndClosedRangeBoundaries(): void
    {
        self::assertSame([1], MissingValues::discrete(1)->discreteValues());
        self::assertSame([1, 2, 3], MissingValues::discrete(1, 2, 3)->discreteValues());

        $closedRange = MissingValues::range(5, 5);
        self::assertSame(5, $closedRange->lower);
        self::assertSame(5, $closedRange->upper);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMissingValuesProvider(): iterable
    {
        yield 'empty discrete set' => ['empty', 'between one and three'];
        yield 'four discrete values' => ['four', 'between one and three'];
        yield 'NaN discrete value' => ['discrete-nan', 'must be finite'];
        yield 'infinite lower range' => ['range-lower', 'must be finite'];
        yield 'infinite upper range' => ['range-upper', 'must be finite'];
        yield 'infinite additional value' => ['additional', 'must be finite'];
    }

    #[DataProvider('invalidMissingValuesProvider')]
    public function testMissingValuesRejectInvalidCardinalityAndNonFiniteValues(
        string $fixture,
        string $message,
    ): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        match ($fixture) {
            'empty' => MissingValues::discrete(),
            'four' => MissingValues::discrete(1, 2, 3, 4),
            'discrete-nan' => MissingValues::discrete(NAN),
            'range-lower' => MissingValues::range(-INF, 1),
            'range-upper' => MissingValues::range(1, INF),
            'additional' => MissingValues::rangeAndValue(1, 2, NAN),
            default => throw new \LogicException('Unknown missing-values fixture.'),
        };
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidMultipleResponseSetProvider(): iterable
    {
        yield 'bare dollar name' => ['name-dollar', 'must begin with'];
        yield 'missing dollar prefix' => ['name-prefix', 'must begin with'];
        yield 'whitespace member' => ['member', 'member name'];
        yield 'null dichotomy value' => ['null-counted', 'requires a counted value'];
        yield 'empty dichotomy value' => ['empty-counted', 'requires a counted value'];
        yield 'category counted labels' => ['category-labels', 'cannot use counted-value'];
        yield 'variable label source mismatch' => ['label-source', 'only valid with counted-value'];
    }

    #[DataProvider('invalidMultipleResponseSetProvider')]
    public function testMultipleResponseSetRejectsInvalidApiCombinations(string $fixture, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        match ($fixture) {
            'name-dollar' => new MultipleResponseSet('$', MultipleResponseSetType::CATEGORY, []),
            'name-prefix' => new MultipleResponseSet('set', MultipleResponseSetType::CATEGORY, []),
            'member' => new MultipleResponseSet('$set', MultipleResponseSetType::CATEGORY, [' ']),
            'null-counted' => new MultipleResponseSet('$set', MultipleResponseSetType::DICHOTOMY, ['v']),
            'empty-counted' => new MultipleResponseSet('$set', MultipleResponseSetType::DICHOTOMY, ['v'], countedValue: ''),
            'category-labels' => new MultipleResponseSet(
                '$set',
                MultipleResponseSetType::CATEGORY,
                ['v'],
                categoryLabels: MultipleResponseCategoryLabels::COUNTED_VALUES,
            ),
            'label-source' => new MultipleResponseSet(
                '$set',
                MultipleResponseSetType::DICHOTOMY,
                ['v'],
                countedValue: 1,
                labelSource: MultipleResponseLabelSource::VARIABLE_LABEL,
            ),
            default => throw new \LogicException('Unknown multiple-response fixture.'),
        };
    }

    /** @return iterable<string, array{string, string}> */
    public static function whitespaceNameProvider(): iterable
    {
        yield 'file attribute' => ['file-attribute', 'file attribute name'];
        yield 'variable attribute owner' => ['attribute-owner', 'variable name'];
        yield 'variable attribute name' => ['attribute-name', 'attribute name'];
        yield 'variable set name' => ['set-name', 'set name'];
        yield 'variable set member' => ['set-member', 'member name'];
        yield 'value label variable' => ['label-variable', 'variable name'];
    }

    #[DataProvider('whitespaceNameProvider')]
    public function testTypedCollectionsRejectWhitespaceOnlyNames(string $fixture, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        match ($fixture) {
            'file-attribute' => new FileAttribute(' ', ['value']),
            'attribute-owner' => new VariableAttribute(' ', 'attribute', ['value']),
            'attribute-name' => new VariableAttribute('variable', ' ', ['value']),
            'set-name' => new VariableSet(' '),
            'set-member' => new VariableSet('set', [' ']),
            'label-variable' => new ValueLabelSet([], [' ']),
            default => throw new \LogicException('Unknown whitespace fixture.'),
        };
    }

    public function testDictionaryRejectsUnicodeCaseFoldedDuplicates(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Duplicate dictionary variable name');

        new VariableDictionary([
            $this->numericVariable("\u{00D5}IGE"),
            $this->numericVariable("\u{00F5}ige"),
        ]);
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
