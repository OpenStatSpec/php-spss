<?php

declare(strict_types=1);

namespace SPSS\Sav;

use SPSS\Sav\Record\Header;
use SPSS\Sav\Record\Info;
use SPSS\Sav\Record\Info\CharacterEncoding;
use SPSS\Sav\Record\Info\DataFileAttributes;
use SPSS\Sav\Record\Info\ExtendedNumberOfCases;
use SPSS\Sav\Record\Info\LongStringMissingValues;
use SPSS\Sav\Record\Info\LongStringValueLabels;
use SPSS\Sav\Record\Info\LongVariableNames;
use SPSS\Sav\Record\Info\MachineFloatingPoint;
use SPSS\Sav\Record\Info\MachineInteger;
use SPSS\Sav\Record\Info\MultipleResponseSets;
use SPSS\Sav\Record\Info\VariableAttributes;
use SPSS\Sav\Record\Info\VariableDisplayParam;
use SPSS\Sav\Record\Info\VariableSets;
use SPSS\Sav\Record\Info\VeryLongString;
use SPSS\Sav\Record\Variable as VariableRecord;
use SPSS\Utils;

/**
 * @phpstan-type VariableDescriptor array{
 *     record: VariableRecord,
 *     shortName: string,
 *     name: string,
 *     width: int,
 *     type: VariableType,
 *     physicalIndex: int,
 *     displayIndex: int
 * }
 */
final class DatasetAssembler
{
    public function assemble(Reader $reader, bool $includeData = true): Dataset
    {
        $header = $reader->header;
        if (!$header instanceof Header) {
            throw new \LogicException('Reader metadata must be loaded before assembling a dataset.');
        }

        $longNames = $this->stringMap($reader->info[LongVariableNames::SUBTYPE] ?? null);
        $veryLongStrings = $this->intMap($reader->info[VeryLongString::SUBTYPE] ?? null);
        $displayParameters = $this->displayParameters($reader->info[VariableDisplayParam::SUBTYPE] ?? null);

        /** @var list<VariableDescriptor> $descriptors */
        $descriptors = [];
        /** @var array<int, string> $logicalNamesByPhysicalIndex */
        $logicalNamesByPhysicalIndex = [];
        $displayIndex = 0;

        foreach ($reader->variables as $record) {
            $shortName = $record->name;
            $name = $longNames[$shortName] ?? $shortName;
            $width = $veryLongStrings[$shortName] ?? $record->width;
            $physicalIndex = $record->realPosition ?? 0;
            $type = VariableType::fromWidth($width);
            $descriptors[] = [
                'record' => $record,
                'shortName' => $shortName,
                'name' => $name,
                'width' => $width,
                'type' => $type,
                'physicalIndex' => $physicalIndex,
                'displayIndex' => $displayIndex,
            ];
            $logicalNamesByPhysicalIndex[$physicalIndex] = $name;
            $displayIndex += Utils::widthToSegments($width);
        }

        $shortValueLabels = $this->shortValueLabels($reader, $logicalNamesByPhysicalIndex);
        $longValueLabels = $this->longValueLabels($reader->info[LongStringValueLabels::SUBTYPE] ?? null);
        $longMissingValues = $this->longMissingValues($reader->info[LongStringMissingValues::SUBTYPE] ?? null);
        $variableAttributes = $this->variableAttributes(
            $reader->mergedInfo[VariableAttributes::SUBTYPE] ?? $reader->info[VariableAttributes::SUBTYPE] ?? null,
        );

        $variables = [];
        foreach ($descriptors as $descriptor) {
            $record = $descriptor['record'];
            $display = $displayParameters[$descriptor['displayIndex']] ?? null;
            $measure = isset($display[0]) ? Measure::tryFrom($display[0]) : null;
            $alignment = isset($display[2]) ? Alignment::tryFrom($display[2]) : null;
            $columns = isset($display[1]) ? max(0, $display[1]) : 8;
            $attributes = $this->typedVariableAttributes(
                $descriptor['name'],
                $descriptor['shortName'],
                $variableAttributes,
            );

            $valueLabels = $shortValueLabels[$descriptor['physicalIndex']] ?? null;
            $longLabels = $longValueLabels[$descriptor['name']]
                ?? $longValueLabels[$descriptor['shortName']]
                ?? null;
            if (null !== $longLabels) {
                $valueLabels = $this->typedLongValueLabels($descriptor['name'], $longLabels);
            }

            $variables[] = new VariableMetadata(
                name: $descriptor['name'],
                type: $descriptor['type'],
                width: $descriptor['width'],
                printFormat: new VariableFormat($record->print[1], $record->print[2], $record->print[3]),
                writeFormat: new VariableFormat($record->write[1], $record->write[2], $record->write[3]),
                shortName: $descriptor['shortName'],
                label: $record->label,
                valueLabels: $valueLabels,
                missingValues: $this->missingValues(
                    $record,
                    $descriptor['name'],
                    $descriptor['shortName'],
                    $longMissingValues,
                ),
                measure: $measure ?? Measure::UNKNOWN,
                alignment: $alignment ?? (
                    VariableType::NUMERIC === $descriptor['type'] ? Alignment::RIGHT : Alignment::LEFT
                ),
                columns: $columns,
                role: $this->role($attributes),
                attributes: $attributes,
                dictionaryIndex: $descriptor['physicalIndex'] + 1,
            );
        }

        $dictionary = new VariableDictionary($variables);
        $rows = $includeData ? $this->rows($reader, $dictionary) : [];

        return new Dataset(
            dictionary: $dictionary,
            rows: $rows,
            metadata: new FileMetadata(
                label: $header->fileLabel,
                weightVariableName: $this->weightVariableName($header, $dictionary, $logicalNamesByPhysicalIndex),
                createdAt: $this->createdAt($header),
                documents: $reader->documents,
                attributes: $this->fileAttributes($reader->info[DataFileAttributes::SUBTYPE] ?? null),
                variableSets: $this->variableSets($reader->info[VariableSets::SUBTYPE] ?? null, $dictionary),
                multipleResponseSets: $this->multipleResponseSets($reader, $dictionary),
            ),
            technicalMetadata: $this->technicalMetadata($reader, $header),
        );
    }

    /**
     * @param array<int, string> $logicalNamesByPhysicalIndex
     * @return array<int, ValueLabelSet>
     */
    private function shortValueLabels(Reader $reader, array $logicalNamesByPhysicalIndex): array
    {
        $result = [];
        foreach ($reader->valueLabels as $record) {
            $names = [];
            foreach ($record->indexes as $index) {
                if (isset($logicalNamesByPhysicalIndex[$index])) {
                    $names[] = $logicalNamesByPhysicalIndex[$index];
                }
            }

            $labels = [];
            foreach ($record->labels as $label) {
                $labels[] = new ValueLabel($label['value'], $label['label']);
            }

            $set = new ValueLabelSet($labels, $names);
            foreach ($record->indexes as $index) {
                if (isset($logicalNamesByPhysicalIndex[$index])) {
                    $result[$index] = $set;
                }
            }
        }

        return $result;
    }

    /**
     * @return array<string, array{width: int, labels: list<array{value: string, label: string}>}>
     */
    private function longValueLabels(?Info $info): array
    {
        if (!$info instanceof LongStringValueLabels) {
            return [];
        }

        $result = [];
        foreach ($info->toArray() as $name => $data) {
            if (!\is_string($name) || !\is_array($data)) {
                continue;
            }

            $width = $data['width'] ?? null;
            if (!\is_int($width)) {
                continue;
            }

            $labels = [];
            $rawLabels = $data['labels'] ?? null;
            if (\is_array($rawLabels)) {
                foreach ($rawLabels as $rawLabel) {
                    if (
                        \is_array($rawLabel)
                        && isset($rawLabel['value'], $rawLabel['label'])
                        && (\is_string($rawLabel['value']) || \is_int($rawLabel['value']))
                        && \is_string($rawLabel['label'])
                    ) {
                        $labels[] = ['value' => (string) $rawLabel['value'], 'label' => $rawLabel['label']];
                    }
                }
            } else {
                $values = $data['values'] ?? null;
                if (!\is_array($values)) {
                    continue;
                }

                foreach ($values as $value => $label) {
                    if (\is_string($label)) {
                        $labels[] = ['value' => (string) $value, 'label' => $label];
                    }
                }
            }

            $result[$name] = ['width' => $width, 'labels' => $labels];
        }

        return $result;
    }

    /** @param array{width: int, labels: list<array{value: string, label: string}>} $data */
    private function typedLongValueLabels(string $variableName, array $data): ValueLabelSet
    {
        $labels = [];
        foreach ($data['labels'] as $label) {
            $labels[] = new ValueLabel($label['value'], $label['label']);
        }

        return new ValueLabelSet($labels, [$variableName]);
    }

    /** @return array<string, list<string>> */
    private function longMissingValues(?Info $info): array
    {
        if (!$info instanceof LongStringMissingValues) {
            return [];
        }

        $result = [];
        foreach ($info->toArray() as $name => $values) {
            if (!\is_string($name) || !\is_array($values) || !array_is_list($values)) {
                continue;
            }

            $typedValues = [];
            foreach ($values as $value) {
                if (\is_string($value)) {
                    $typedValues[] = $value;
                }
            }

            $result[$name] = $typedValues;
        }

        return $result;
    }

    /**
     * @param array<string, list<string>> $longMissingValues
     */
    private function missingValues(
        VariableRecord $record,
        string $name,
        string $shortName,
        array $longMissingValues,
    ): MissingValues {
        $longValues = $longMissingValues[$name] ?? $longMissingValues[$shortName] ?? null;
        if (null !== $longValues && [] !== $longValues) {
            return MissingValues::discrete(...$longValues);
        }

        if (0 === $record->missingValuesFormat) {
            return MissingValues::none();
        }

        if ($record->missingValuesFormat > 0) {
            return MissingValues::discrete(...$record->missingValues);
        }

        $lower = $this->numericMissingValue($record->missingValues[0] ?? null);
        $upper = $this->numericMissingValue($record->missingValues[1] ?? null);
        if (-2 === $record->missingValuesFormat) {
            return MissingValues::range($lower, $upper);
        }

        return MissingValues::rangeAndValue(
            $lower,
            $upper,
            $this->numericMissingValue($record->missingValues[2] ?? null),
        );
    }

    private function numericMissingValue(mixed $value): int|float
    {
        if (!\is_int($value) && !\is_float($value)) {
            throw new \UnexpectedValueException('A numeric missing-value range contains a non-numeric value.');
        }

        return $value;
    }

    /** @return list<array{int, int, int}> */
    private function displayParameters(?Info $info): array
    {
        if (!$info instanceof VariableDisplayParam) {
            return [];
        }

        $result = [];
        foreach ($info->toArray() as $display) {
            if (!\is_array($display) || !isset($display[0], $display[1], $display[2])) {
                continue;
            }

            if (!\is_int($display[0]) || !\is_int($display[1]) || !\is_int($display[2])) {
                continue;
            }

            $result[] = [$display[0], $display[1], $display[2]];
        }

        return $result;
    }

    /** @return array<string, array<string, list<string>>> */
    private function variableAttributes(?Info $info): array
    {
        if (!$info instanceof VariableAttributes) {
            return [];
        }

        $result = [];
        foreach ($info->toArray() as $variableName => $attributes) {
            if (!\is_string($variableName) || !\is_array($attributes)) {
                continue;
            }

            foreach ($attributes as $attributeName => $values) {
                if (!\is_string($attributeName)) {
                    continue;
                }

                $normalized = $this->attributeValues($values);
                if ([] !== $normalized) {
                    $result[$variableName][$attributeName] = $normalized;
                }
            }
        }

        return $result;
    }

    /**
     * @param array<string, array<string, list<string>>> $attributes
     * @return list<VariableAttribute>
     */
    private function typedVariableAttributes(string $name, string $shortName, array $attributes): array
    {
        $source = $attributes[$name] ?? $attributes[$shortName] ?? [];
        $result = [];
        foreach ($source as $attributeName => $values) {
            $result[] = new VariableAttribute($name, $attributeName, $values);
        }

        return $result;
    }

    /** @param list<VariableAttribute> $attributes */
    private function role(array $attributes): VariableRole
    {
        foreach ($attributes as $attribute) {
            if ('$@Role' !== $attribute->name) {
                continue;
            }

            $value = $attribute->values()[0];
            if (is_numeric($value)) {
                return VariableRole::tryFrom((int) $value) ?? VariableRole::INPUT;
            }

            return match (strtolower($value)) {
                'target' => VariableRole::TARGET,
                'both' => VariableRole::BOTH,
                'none' => VariableRole::NONE,
                'partition' => VariableRole::PARTITION,
                'split' => VariableRole::SPLIT,
                default => VariableRole::INPUT,
            };
        }

        return VariableRole::INPUT;
    }

    /** @return list<FileAttribute> */
    private function fileAttributes(?Info $info): array
    {
        if (!$info instanceof DataFileAttributes) {
            return [];
        }

        $result = [];
        foreach ($info->toArray() as $name => $values) {
            if (!\is_string($name) || 'raw' === $name) {
                continue;
            }

            $normalized = $this->attributeValues($values);
            if ([] !== $normalized) {
                $result[] = new FileAttribute($name, $normalized);
            }
        }

        return $result;
    }

    /** @return list<string> */
    private function attributeValues(mixed $values): array
    {
        if (\is_string($values) || \is_int($values) || \is_float($values)) {
            return [(string) $values];
        }

        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            if (\is_string($value) || \is_int($value) || \is_float($value)) {
                $result[] = (string) $value;
            }
        }

        return $result;
    }

    /** @return list<VariableSet> */
    private function variableSets(?Info $info, VariableDictionary $dictionary): array
    {
        if (!$info instanceof VariableSets) {
            return [];
        }

        $result = [];
        foreach ($info->toArray() as $name => $variableNames) {
            if (!\is_string($name) || !\is_array($variableNames)) {
                continue;
            }

            $members = [];
            foreach ($variableNames as $variableName) {
                if (!\is_string($variableName)) {
                    continue;
                }

                $resolved = $dictionary->variable($variableName);
                $members[] = null !== $resolved ? $resolved->name : $variableName;
            }

            $result[] = new VariableSet($name, $members);
        }

        return $result;
    }

    /** @return list<MultipleResponseSet> */
    private function multipleResponseSets(Reader $reader, VariableDictionary $dictionary): array
    {
        $result = [];
        foreach ([MultipleResponseSets::SUBTYPE, MultipleResponseSets::COUNTED_VALUES_SUBTYPE] as $subtype) {
            $info = $reader->info[$subtype] ?? null;
            if (!$info instanceof MultipleResponseSets) {
                continue;
            }

            foreach ($info->toArray() as $name => $set) {
                if (!\is_string($name) || !\is_array($set)) {
                    continue;
                }

                $typeCode = $set['type'] ?? null;
                $rawVariables = $set['variables'] ?? null;
                if (!\is_string($typeCode) || !\is_array($rawVariables)) {
                    continue;
                }

                $variableNames = [];
                foreach ($rawVariables as $variableName) {
                    if (\is_string($variableName)) {
                        $resolved = $dictionary->variable($variableName);
                        $variableNames[] = null !== $resolved ? $resolved->name : $variableName;
                    }
                }

                $countedValue = $set['countedValue'] ?? null;
                if (!\is_string($countedValue)) {
                    $countedValue = null;
                } elseif ($this->allNumeric($variableNames, $dictionary) && preg_match('/^-?\d+$/D', $countedValue)) {
                    $countedValue = (int) $countedValue;
                }

                $label = $set['label'] ?? null;
                $label = \is_string($label) && '' !== $label ? $label : null;
                $countedLabels = 'E' === $typeCode;

                $result[] = new MultipleResponseSet(
                    name: $name,
                    type: 'C' === $typeCode
                        ? MultipleResponseSetType::CATEGORY
                        : MultipleResponseSetType::DICHOTOMY,
                    variableNames: $variableNames,
                    label: $label,
                    countedValue: 'C' === $typeCode ? null : $countedValue,
                    categoryLabels: $countedLabels
                        ? MultipleResponseCategoryLabels::COUNTED_VALUES
                        : MultipleResponseCategoryLabels::VARIABLE_LABELS,
                    labelSource: 11 === ($set['labelSource'] ?? null)
                        ? MultipleResponseLabelSource::VARIABLE_LABEL
                        : MultipleResponseLabelSource::SET_LABEL,
                );
            }
        }

        return $result;
    }

    /** @param list<string> $variableNames */
    private function allNumeric(array $variableNames, VariableDictionary $dictionary): bool
    {
        if ([] === $variableNames) {
            return false;
        }

        foreach ($variableNames as $variableName) {
            if (VariableType::NUMERIC !== $dictionary->variable($variableName)?->type) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> */
    private function stringMap(?Info $info): array
    {
        if (!$info instanceof LongVariableNames) {
            return [];
        }

        $result = [];
        foreach ($info->toArray() as $key => $value) {
            if (\is_string($key) && \is_string($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @return array<string, int> */
    private function intMap(?Info $info): array
    {
        if (!$info instanceof VeryLongString) {
            return [];
        }

        $result = [];
        foreach ($info->toArray() as $key => $value) {
            if (\is_string($key) && \is_int($value)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param array<int, string> $logicalNamesByPhysicalIndex
     */
    private function weightVariableName(
        Header $header,
        VariableDictionary $dictionary,
        array $logicalNamesByPhysicalIndex,
    ): ?string {
        if ($header->weightIndex <= 0) {
            return null;
        }

        $name = $logicalNamesByPhysicalIndex[$header->weightIndex - 1] ?? null;

        return null !== $name ? $dictionary->variable($name)?->name : null;
    }

    private function createdAt(Header $header): ?\DateTimeImmutable
    {
        $value = \DateTimeImmutable::createFromFormat(
            '!d M y H:i:s',
            trim($header->creationDate) . ' ' . trim($header->creationTime),
        );

        return false === $value ? null : $value;
    }

    private function technicalMetadata(Reader $reader, Header $header): FileTechnicalMetadata
    {
        $machineInteger = $reader->info[MachineInteger::SUBTYPE] ?? null;
        $encodingInfo = $reader->info[CharacterEncoding::SUBTYPE] ?? null;
        $extendedCases = $reader->info[ExtendedNumberOfCases::SUBTYPE] ?? null;

        $encoding = $encodingInfo instanceof CharacterEncoding && '' !== $encodingInfo->value
            ? $encodingInfo->value
            : $this->encodingFromMachineInteger($machineInteger);

        $caseCount = $header->casesCount >= 0 ? $header->casesCount : null;
        if (null === $caseCount && $extendedCases instanceof ExtendedNumberOfCases && $extendedCases->ncases >= 0) {
            $caseCount = (int) $extendedCases->ncases;
        }

        $sourceVersion = null;
        if ($machineInteger instanceof MachineInteger) {
            $sourceVersion = sprintf(
                '%d.%d.%d',
                $machineInteger->version[0],
                $machineInteger->version[1],
                $machineInteger->version[2],
            );
        }

        return new FileTechnicalMetadata(
            sourceFormat: 'sav',
            recordType: $header->recType,
            sourceVersion: $sourceVersion,
            provenance: $reader->source(),
            encoding: $encoding,
            productName: $header->prodName,
            rawCreationDate: $header->creationDate,
            rawCreationTime: $header->creationTime,
            caseCount: $caseCount,
            nominalCaseSize: $header->nominalCaseSize >= 0 ? $header->nominalCaseSize : null,
            layoutCode: $header->layoutCode,
            compression: $header->compression,
            compressionBias: $header->bias,
            machineCode: $machineInteger instanceof MachineInteger ? $machineInteger->machineCode : null,
            floatingPointRepresentation: $machineInteger instanceof MachineInteger
                ? $machineInteger->floatingPointRep
                : null,
            endianness: $machineInteger instanceof MachineInteger ? $machineInteger->endianness : null,
            characterCode: $machineInteger instanceof MachineInteger ? $machineInteger->characterCode : null,
        );
    }

    private function encodingFromMachineInteger(?Info $info): string
    {
        if (!$info instanceof MachineInteger) {
            return 'UTF-8';
        }

        return match ($info->characterCode) {
            1250 => 'WINDOWS-1250',
            1252 => 'WINDOWS-1252',
            28591 => 'ISO-8859-1',
            65001 => 'UTF-8',
            default => 'UTF-8',
        };
    }

    /** @return list<list<int|float|string|null>> */
    private function rows(Reader $reader, VariableDictionary $dictionary): array
    {
        $floatingPoint = $reader->info[MachineFloatingPoint::SUBTYPE] ?? null;
        $systemMissing = $floatingPoint instanceof MachineFloatingPoint ? $floatingPoint->sysmis : NAN;
        $variables = $dictionary->variables();
        $rows = [];

        foreach ($reader->data as $rowIndex => $row) {
            if (\count($row) !== \count($variables)) {
                throw new \UnexpectedValueException(sprintf(
                    'Data row %d contains %d values for a %d-variable dictionary.',
                    $rowIndex,
                    \count($row),
                    \count($variables),
                ));
            }

            $typedRow = [];
            foreach ($variables as $index => $variable) {
                $value = $row[$index];
                if (
                    VariableType::NUMERIC === $variable->type
                    && \is_float($value)
                    && ((is_nan($systemMissing) && is_nan($value)) || $value === $systemMissing)
                ) {
                    $typedRow[] = null;
                    continue;
                }

                if (null === $value && VariableType::STRING === $variable->type) {
                    throw new \UnexpectedValueException(sprintf(
                        'String variable "%s" cannot contain a null value.',
                        $variable->name,
                    ));
                }

                $typedRow[] = $value;
            }

            $rows[] = $typedRow;
        }

        return $rows;
    }
}
