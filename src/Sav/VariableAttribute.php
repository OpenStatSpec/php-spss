<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class VariableAttribute
{
    /** @var non-empty-list<string> */
    private array $values;

    /** @param list<string> $values */
    public function __construct(
        public string $variableName,
        public string $name,
        array $values,
    ) {
        if ('' === trim($this->variableName)) {
            throw new \InvalidArgumentException('A variable attribute variable name cannot be empty.');
        }

        if ('' === trim($this->name)) {
            throw new \InvalidArgumentException('A variable attribute name cannot be empty.');
        }

        if ([] === $values) {
            throw new \InvalidArgumentException('Variable attribute values must be a non-empty list.');
        }

        $this->values = $values;
    }

    /** @return non-empty-list<string> */
    public function values(): array
    {
        return $this->values;
    }
}
