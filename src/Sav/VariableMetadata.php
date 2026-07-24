<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class VariableMetadata
{
    public ValueLabelSet $valueLabels;

    public MissingValues $missingValues;

    /**
     * Width is the storage width: 0 for numeric variables and 1..32767 for strings.
     *
     * @param list<VariableAttribute> $attributes
     */
    public function __construct(
        public string $name,
        public VariableType $type,
        public int $width,
        public VariableFormat $printFormat,
        public VariableFormat $writeFormat,
        public ?string $shortName = null,
        public ?string $label = null,
        ?ValueLabelSet $valueLabels = null,
        ?MissingValues $missingValues = null,
        public Measure $measure = Measure::UNKNOWN,
        public Alignment $alignment = Alignment::LEFT,
        public int $columns = 8,
        public VariableRole $role = VariableRole::INPUT,
        private array $attributes = [],
        public ?int $dictionaryIndex = null,
    ) {
        if ('' === trim($this->name)) {
            throw new \InvalidArgumentException('A variable name cannot be empty.');
        }

        if (null !== $this->shortName && '' === trim($this->shortName)) {
            throw new \InvalidArgumentException('A short variable name cannot be empty.');
        }

        if (VariableType::NUMERIC === $this->type && 0 !== $this->width) {
            throw new \InvalidArgumentException('A numeric variable must have storage width 0.');
        }

        if (VariableType::STRING === $this->type && ($this->width < 1 || $this->width > 32767)) {
            throw new \InvalidArgumentException('A string variable width must be between 1 and 32767 bytes.');
        }

        if ($this->columns < 0) {
            throw new \InvalidArgumentException('A variable display column width cannot be negative.');
        }

        if (null !== $this->dictionaryIndex && $this->dictionaryIndex < 1) {
            throw new \InvalidArgumentException('A dictionary index must be 1-based.');
        }

        $this->valueLabels = $valueLabels ?? new ValueLabelSet([]);
        $this->missingValues = $missingValues ?? MissingValues::none();
    }

    /** @return list<VariableAttribute> */
    public function attributes(): array
    {
        return $this->attributes;
    }
}
