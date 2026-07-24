<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class MultipleResponseSet
{
    /** @var list<string> */
    private array $variableNames;

    /**
     * @param list<string> $variableNames
     */
    public function __construct(
        public string $name,
        public MultipleResponseSetType $type,
        array $variableNames,
        public ?string $label = null,
        public int|string|null $countedValue = null,
        public MultipleResponseCategoryLabels $categoryLabels = MultipleResponseCategoryLabels::VARIABLE_LABELS,
        public MultipleResponseLabelSource $labelSource = MultipleResponseLabelSource::SET_LABEL,
    ) {
        if (!str_starts_with($this->name, '$') || 1 === \strlen($this->name)) {
            throw new \InvalidArgumentException('A multiple-response set name must begin with "$".');
        }


        foreach ($variableNames as $variableName) {
            if ('' === trim($variableName)) {
                throw new \InvalidArgumentException('A multiple-response set member name cannot be empty.');
            }
        }

        if (MultipleResponseSetType::CATEGORY === $this->type) {
            if (null !== $this->countedValue) {
                throw new \InvalidArgumentException('A category set cannot have a counted value.');
            }

            if (MultipleResponseCategoryLabels::VARIABLE_LABELS !== $this->categoryLabels) {
                throw new \InvalidArgumentException('A category set cannot use counted-value category labels.');
            }
        } elseif (null === $this->countedValue || (\is_string($this->countedValue) && '' === $this->countedValue)) {
            throw new \InvalidArgumentException('A dichotomy set requires a counted value.');
        }

        if (
            MultipleResponseLabelSource::VARIABLE_LABEL === $this->labelSource
            && MultipleResponseCategoryLabels::COUNTED_VALUES !== $this->categoryLabels
        ) {
            throw new \InvalidArgumentException('Variable-label label source is only valid with counted-value category labels.');
        }

        $this->variableNames = $variableNames;
    }

    /** @return list<string> */
    public function variableNames(): array
    {
        return $this->variableNames;
    }
}
