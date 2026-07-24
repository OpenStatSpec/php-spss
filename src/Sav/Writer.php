<?php

namespace SPSS\Sav;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Utils;

/**
 * @phpstan-import-type VariableData from Variable
 *
 * @phpstan-type WriterData array{
 *     header: array<string, mixed>,
 *     variables: list<Variable|VariableData>,
 *     info?: array{
 *         characterEncoding?: string,
 *         machineInteger?: array<string, mixed>,
 *         machineFloatingPoint?: array<string, mixed>,
 *         extendedNumberOfCases?: array<string, mixed>
 *     },
 *     documents?: list<string>,
 *     fileAttributes?: array<string, non-empty-list<string>>,
 *     variableSets?: list<VariableSet>,
 *     multipleResponseSets?: list<MultipleResponseSet>
 * }
 */
class Writer
{
    /**
     * @var Record\Header
     */
    public $header;

    /**
     * @var Record\Variable[]
     */
    public $variables = [];

    /**
     * @var Record\ValueLabel[]
     */
    public $valueLabels = [];

    /**
     * @var Record\Document
     */
    public $document;

    /**
     * @var Record\Info[]
     */
    public $info = [];

    /**
     * @var Record\Data
     */
    public $data;

    /**
     * @var Buffer
     */
    protected $buffer;

    /**
     * Writer constructor.
     *
     * @param WriterData|Dataset|array{} $data
     * @param Buffer|null                $buffer
     *
     */
    public function __construct($data = [], $buffer = null)
    {
        $this->buffer          = $buffer ?? Buffer::factory();
        $this->buffer->context = $this;

        if ($data !== []) {
            $this->write($data);
        }
    }

    /**
     * @param string $file
     * @param WriterData|Dataset|array{} $data
     */
    public static function createInFile($file, $data = []): self
    {
        return new self($data, Buffer::factory(fopen($file, 'wb+')));
    }

    /**
     * @param WriterData|Dataset $data
     */
    public function write(array|Dataset $data): void
    {
        if ($data instanceof Dataset) {
            $data = $this->datasetToWriterData($data);
        }

        $this->header                  = new Record\Header($data['header']);
        $this->header->nominalCaseSize = 0;
        $this->header->casesCount      = 0;

        $this->info[Record\Info\MachineInteger::SUBTYPE] = $this->prepareInfoRecord(
            Record\Info\MachineInteger::class,
            $data,
        );

        $this->info[Record\Info\MachineFloatingPoint::SUBTYPE] = $this->prepareInfoRecord(
            Record\Info\MachineFloatingPoint::class,
            $data,
        );

        $this->info[Record\Info\VariableDisplayParam::SUBTYPE]  = new Record\Info\VariableDisplayParam();
        $this->info[Record\Info\LongVariableNames::SUBTYPE]     = new Record\Info\LongVariableNames();
        $this->info[Record\Info\VeryLongString::SUBTYPE]        = new Record\Info\VeryLongString();
        $this->info[Record\Info\ExtendedNumberOfCases::SUBTYPE] = $this->prepareInfoRecord(
            Record\Info\ExtendedNumberOfCases::class,
            $data,
        );
        $this->info[Record\Info\LongStringValueLabels::SUBTYPE]   = new Record\Info\LongStringValueLabels();
        $this->info[Record\Info\LongStringMissingValues::SUBTYPE] = new Record\Info\LongStringMissingValues();

        $encode = (isset($data['info']) && isset($data['info']['characterEncoding'])) ? $data['info']['characterEncoding'] : 'UTF-8';
        $this->info[Record\Info\CharacterEncoding::SUBTYPE]       = new Record\Info\CharacterEncoding($encode);
        $this->buffer->charset = $encode;

        // FIXME: This means we can not set any other encode here?
        // https://www.gnu.org/software/pspp/pspp-dev/html_node/Machine-Integer-Info-Record.html#character_002dcode
        $charactersCode = [
            "utf-8" => 65001,
            "iso 8859-1" => 28591,
            "windows-1252" => 1252,
            "windows-1250" => 1250,
            "dec kanji" => 4,
            "8-bit ascii" => 3,
            "7-bit ascii" => 2,
            "ebcdic" => 1,
        ];

        $chCode = $charactersCode[strtolower($encode)] ?? 65001;
        $this->info[Record\Info\MachineInteger::SUBTYPE]->characterCode = $chCode;
        $this->data = new Record\Data();
        $nominalIdx = 0;
        $shortVarsPrefix = [];
        $shortNameByLongName = [];

        // for ($idx = 0; $idx <= $variablesCount; $idx++) {
        foreach ($data['variables'] as $idx => $var) {
            if (\is_array($var)) {
                $var = new Variable($var);
            }

            $isString = null !== $var->type
                ? VariableType::STRING === $var->type
                : Variable::isStringFormat($var->format);

            // UTF-8 and '.' characters could pass here
            if (!preg_match('/^(?!#|\$|\.)[\w0-9_.#@$\x{4e00}-\x{9fa5}]+(?<!\.|_)$/u', (string) $var->name)) {
                throw new \InvalidArgumentException(sprintf('Variable name `%s` contains an illegal character.', $var->name));
            }

            if (in_array($var->name, ['ALL', 'AND', 'BY', 'EQ', 'GE', 'GT', 'LE', 'LT', 'NE', 'NOT', 'OR', 'TO', 'WITH'], true)) {
                $var->name = \uniqid($var->name);
            }

            if ($var->width === 0) {
                throw new \InvalidArgumentException('Invalid field width. Should be an integer number greater than zero.');
            }

            $variable = new Record\Variable();

            // TODO: refactory - keep 7 positions so we can add after that for 100 very long string segments
            $prefix = mb_strtoupper(mb_substr((string) $var->name, 0, min(mb_strlen((string) $var->name), 5)));
            $variable->name  = ($isString && (Record\Variable::isVeryLong($var->width)) && (!in_array($prefix, $shortVarsPrefix, true))) ?
                               $prefix : 'V' . str_pad((string) ($idx + 1), 5, '0', STR_PAD_LEFT);
            $shortVarsPrefix[] = $prefix;
            $variable->width = $isString ? $var->width : 0;

            $variable->label = $var->label;
            $printFormat = $var->printFormat ?? new VariableFormat($var->format, min($var->width, 255), $var->decimals);
            $writeFormat = $var->writeFormat ?? $printFormat;
            $variable->print = [
                0,
                $printFormat->code,
                $printFormat->width,
                $printFormat->decimals,
            ];
            $variable->write = [
                0,
                $writeFormat->code,
                $writeFormat->width,
                $writeFormat->decimals,
            ];

            // TODO: refactory
            $shortName = $variable->name;
            $longName  = $var->name;
            $shortNameByLongName[$longName] = strtolower($shortName);

            if ($var->attributes !== []) {
                $attributes = [];
                foreach ($var->attributes as $name => $values) {
                    $values = \is_array($values) ? $values : [$values];
                    $attributes[$name] = array_map(static fn(int|float|string $value): string => (string) $value, $values);
                }

                $this->info[Record\Info\VariableAttributes::SUBTYPE] ??= new Record\Info\VariableAttributes();
                $this->info[Record\Info\VariableAttributes::SUBTYPE][$longName] = $attributes;
            }

            $missingValues = $var->missingValues;
            if (null === $missingValues && [] !== $var->missing) {
                $missingValues = MissingValues::discrete(...$var->missing);
            }
            $this->configureMissingValues($missingValues ?? MissingValues::none(), $variable, $longName, $isString);

            $this->variables[$idx] = $variable;

            $labels = [];
            if (null !== $var->valueLabelSet) {
                foreach ($var->valueLabelSet->labels() as $label) {
                    $labels[] = ['value' => $label->value, 'label' => $label->label];
                }
            } else {
                foreach ($var->values as $value => $label) {
                    $labels[] = ['value' => $value, 'label' => $label];
                }
            }

            if ([] !== $labels) {
                if ($variable->width > 8) {
                    $this->info[Record\Info\LongStringValueLabels::SUBTYPE][$longName] = [
                        'width'  => $var->width,
                        'labels' => $labels,
                    ];
                } else {
                    $valueLabel = new Record\ValueLabel([
                        'variables' => $this->variables,
                        'stringValues' => $variable->width > 0,
                        'labels' => $labels,
                        'indexes' => [$nominalIdx],
                    ]);
                    $this->valueLabels[] = $valueLabel;
                }
            }

            $this->info[Record\Info\LongVariableNames::SUBTYPE][$shortName] = $var->name;

            if (Record\Variable::isVeryLong($var->width)) {
                $this->info[Record\Info\VeryLongString::SUBTYPE][$shortName] = $var->width;
            }

            $this->info[Record\Info\VariableDisplayParam::SUBTYPE][] = [
                $var->getMeasure(),
                $var->getColumns(),
                $var->getAlignment(),
            ];

            // TODO: refactory
            $dataCount = \count($var->data);

            if ($dataCount > $this->header->casesCount) {
                $this->header->casesCount = $dataCount;
            }

            foreach ($var->data as $case => $value) {
                $this->data->matrix[$case][$idx] = $value;
            }

            if (!$isString) {
                $nominalIdx += 1;
            } else {
                $nominalIdx += Utils::widthToOcts($var->width);
            }
        }

        $this->header->nominalCaseSize = $nominalIdx;

        $this->configureMetadataInfoRecords($data, $shortNameByLongName);

        // write header
        $this->header->write($this->buffer);

        // write variables
        foreach ($this->variables as $variable) {
            $variable->write($this->buffer);
        }

        // write valueLabels
        foreach ($this->valueLabels as $valueLabel) {
            $valueLabel->write($this->buffer);
        }

        // write documents
        if (isset($data['documents']) && $data['documents'] !== []) {
            $this->document = new Record\Document(
                [
                    'lines' => $data['documents'],
                ],
            );
            $this->document->write($this->buffer);
        }

        ksort($this->info);
        foreach ($this->info as $info) {
            $info->write($this->buffer);
        }

        $this->data->write($this->buffer);
    }

    /**
     * @param array<int, int|float|string|null> $row
     */
    public function writeCase(array $row): void
    {
        if ($this->data === null) {
            $this->data = new Record\Data();
        }

        // update the header info about number of cases
        $this->header->increaseCasesCount($this->buffer);

        // write data
        $this->data->writeCase($this->buffer, $row);
    }

    public function save(string $file): int|false
    {
        return $this->buffer->saveToFile($file);
    }

    public function close(): bool
    {
        if ($this->data !== null) {
            $this->data->close();
        }

        return $this->buffer->close();
    }

    /**
     * @return Buffer
     */
    public function getBuffer()
    {
        return $this->buffer;
    }

    /**
     * @return int
     */
    public function getNumberOfCases()
    {
        return $this->header->casesCount;
    }

    public function writeDataset(Dataset $dataset): void
    {
        $this->write($dataset);
    }

    private function configureMissingValues(
        MissingValues $missingValues,
        Record\Variable $variable,
        string $longName,
        bool $isString,
    ): void {
        if (MissingValuesKind::NONE === $missingValues->kind) {
            return;
        }

        if ($isString && MissingValuesKind::DISCRETE !== $missingValues->kind) {
            throw new \InvalidArgumentException('String variables support only discrete user-missing values.');
        }

        $values = match ($missingValues->kind) {
            MissingValuesKind::DISCRETE => $missingValues->discreteValues(),
            MissingValuesKind::RANGE => [$missingValues->lower, $missingValues->upper],
            default => [
                $missingValues->lower,
                $missingValues->upper,
                $missingValues->additionalValue,
            ],
        };

        foreach ($values as $value) {
            if ($isString !== \is_string($value)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s missing values must match the variable type.',
                    $isString ? 'String' : 'Numeric',
                ));
            }
        }

        if ($isString && $variable->width > 8) {
            $this->info[Record\Info\LongStringMissingValues::SUBTYPE][$longName] = $values;

            return;
        }

        $variable->missingValuesFormat = match ($missingValues->kind) {
            MissingValuesKind::DISCRETE => \count($values),
            MissingValuesKind::RANGE => -2,
            default => -3,
        };
        $variable->missingValues = $values;
    }

    /** @return WriterData */
    private function datasetToWriterData(Dataset $dataset): array
    {
        $variables = [];
        $rows = $dataset->rows();
        foreach ($dataset->variables() as $column => $metadata) {
            $columnData = [];
            foreach ($rows as $rowIndex => $row) {
                $value = $row[$column];
                if (VariableType::STRING === $metadata->type) {
                    if (!\is_string($value)) {
                        throw new \InvalidArgumentException(sprintf(
                            'String variable "%s" requires a string value at row %d; null is not system-missing for strings.',
                            $metadata->name,
                            $rowIndex,
                        ));
                    }
                } elseif (null !== $value && !\is_int($value) && !\is_float($value)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Numeric variable "%s" requires an int, float, or null value at row %d.',
                        $metadata->name,
                        $rowIndex,
                    ));
                }

                $columnData[] = $value;
            }

            $attributes = [];
            foreach ($metadata->attributes() as $attribute) {
                if ($attribute->variableName !== $metadata->name) {
                    throw new \InvalidArgumentException(sprintf(
                        'Attribute "%s" belongs to variable "%s", not "%s".',
                        $attribute->name,
                        $attribute->variableName,
                        $metadata->name,
                    ));
                }

                $attributes[$attribute->name] = $attribute->values();
            }
            $attributes['$@Role'] = [(string) $metadata->role->value];

            $variables[] = new Variable([
                'name' => $metadata->name,
                'type' => $metadata->type,
                'width' => VariableType::STRING === $metadata->type
                    ? $metadata->width
                    : max($metadata->printFormat->width, 1),
                'decimals' => $metadata->printFormat->decimals,
                'format' => $metadata->printFormat->code,
                'printFormat' => $metadata->printFormat,
                'writeFormat' => $metadata->writeFormat,
                'columns' => $metadata->columns,
                'alignment' => $metadata->alignment->value,
                'measure' => $metadata->measure->value,
                'role' => $metadata->role->value,
                'label' => $metadata->label,
                'valueLabelSet' => $metadata->valueLabels,
                'missingValues' => $metadata->missingValues,
                'attributes' => $attributes,
                'data' => $columnData,
            ]);
        }

        $technical = $dataset->technicalMetadata;
        $compression = $technical->compression ?? 1;
        $recordType = $technical->recordType
            ?? (2 === $compression ? Record\Header::ZLIB_REC_TYPE : Record\Header::NORMAL_REC_TYPE);
        $createdAt = $dataset->metadata->createdAt;

        $weightIndex = 0;
        if (null !== $dataset->metadata->weightVariableName) {
            foreach ($dataset->variables() as $index => $variable) {
                if ($variable->name === $dataset->metadata->weightVariableName) {
                    $weightIndex = $variable->dictionaryIndex ?? ($index + 1);
                    break;
                }
            }
            if (0 === $weightIndex) {
                throw new \InvalidArgumentException(sprintf(
                    'Weight variable "%s" is not present in the dictionary.',
                    $dataset->metadata->weightVariableName,
                ));
            }
        }

        $fileAttributes = [];
        foreach ($dataset->metadata->attributes() as $attribute) {
            $fileAttributes[$attribute->name] = $attribute->values();
        }

        $machineInteger = [];
        if (
            null !== $technical->sourceVersion
            && preg_match('/(\d+)\.(\d+)\.(\d+)/', $technical->sourceVersion, $version)
        ) {
            $machineInteger['version'] = [(int) $version[1], (int) $version[2], (int) $version[3]];
        }
        if (null !== $technical->machineCode) {
            $machineInteger['machineCode'] = $technical->machineCode;
        }
        if (null !== $technical->floatingPointRepresentation) {
            $machineInteger['floatingPointRep'] = $technical->floatingPointRepresentation;
        }
        if (null !== $technical->endianness) {
            $machineInteger['endianness'] = $technical->endianness;
        }

        return [
            'header' => [
                'recType' => $recordType,
                'prodName' => $technical->productName ?? $technical->provenance ?? '@(#) SPSS DATA FILE',
                'layoutCode' => $technical->layoutCode ?? 2,
                'compression' => $compression,
                'weightIndex' => $weightIndex,
                'bias' => $technical->compressionBias ?? 100.0,
                'creationDate' => $technical->rawCreationDate ?? $createdAt?->format('d M y') ?? '01 Jan 70',
                'creationTime' => $technical->rawCreationTime ?? $createdAt?->format('H:i:s') ?? '00:00:00',
                'fileLabel' => $dataset->metadata->label,
            ],
            'variables' => $variables,
            'info' => [
                'characterEncoding' => $technical->encoding,
                'machineInteger' => $machineInteger,
            ],
            'documents' => $dataset->metadata->documents(),
            'fileAttributes' => $fileAttributes,
            'variableSets' => $dataset->metadata->variableSets(),
            'multipleResponseSets' => $dataset->metadata->multipleResponseSets(),
        ];
    }

    /**
     * @param WriterData            $data
     * @param array<string, string> $shortNameByLongName
     */
    private function configureMetadataInfoRecords(array $data, array $shortNameByLongName): void
    {
        if ([] !== ($data['fileAttributes'] ?? [])) {
            $this->info[Record\Info\DataFileAttributes::SUBTYPE] = new Record\Info\DataFileAttributes(
                ['data' => $data['fileAttributes']],
            );
        }

        if ([] !== ($data['variableSets'] ?? [])) {
            $record = new Record\Info\VariableSets();
            foreach ($data['variableSets'] as $set) {
                $record[$set->name] = $set->variableNames();
            }
            $this->info[Record\Info\VariableSets::SUBTYPE] = $record;
        }

        $classic = new Record\Info\MultipleResponseSets();
        $counted = new Record\Info\MultipleResponseSets();
        $counted->subtype = Record\Info\MultipleResponseSets::COUNTED_VALUES_SUBTYPE;
        foreach ($data['multipleResponseSets'] ?? [] as $set) {
            $variables = [];
            foreach ($set->variableNames() as $variableName) {
                if (!isset($shortNameByLongName[$variableName])) {
                    throw new \InvalidArgumentException(sprintf(
                        'Multiple-response set "%s" references unknown variable "%s".',
                        $set->name,
                        $variableName,
                    ));
                }
                $variables[] = $shortNameByLongName[$variableName];
            }

            $type = MultipleResponseSetType::CATEGORY === $set->type ? 'C' : 'D';
            $record = $classic;
            $labelSource = null;
            if (MultipleResponseCategoryLabels::COUNTED_VALUES === $set->categoryLabels) {
                $type = 'E';
                $record = $counted;
                $labelSource = MultipleResponseLabelSource::VARIABLE_LABEL === $set->labelSource ? 11 : 1;
            }

            $record[$set->name] = [
                'type' => $type,
                'countedValue' => null === $set->countedValue ? null : (string) $set->countedValue,
                'label' => $set->label ?? '',
                'labelSource' => $labelSource,
                'variables' => $variables,
            ];
        }
        if ([] !== $classic->data) {
            $this->info[Record\Info\MultipleResponseSets::SUBTYPE] = $classic;
        }
        if ([] !== $counted->data) {
            $this->info[Record\Info\MultipleResponseSets::COUNTED_VALUES_SUBTYPE] = $counted;
        }
    }

    /**
     * @template T of Record\Info
     *
     * @param class-string<T> $className
     * @param WriterData      $data
     *
     * @throws Exception
     *
     * @return T
     */
    private function prepareInfoRecord(string $className, array $data): Record\Info
    {
        if (!class_exists($className)) {
            throw new Exception('Unknown class');
        }

        $key = lcfirst(substr($className, strrpos($className, '\\') + 1));

        $info = $data['info'] ?? [];

        return new $className(
            $info[$key] ?? [],
        );
    }
}
