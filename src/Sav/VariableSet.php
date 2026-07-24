<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class VariableSet
{
    /** @var list<string> */
    private array $variableNames;

    /** @param list<string> $variableNames */
    public function __construct(
        public string $name,
        array $variableNames = [],
    ) {
        if ('' === trim($this->name)) {
            throw new \InvalidArgumentException('A variable set name cannot be empty.');
        }


        foreach ($variableNames as $variableName) {
            if ('' === trim($variableName)) {
                throw new \InvalidArgumentException('A variable set member name cannot be empty.');
            }
        }

        $this->variableNames = $variableNames;
    }

    /** @return list<string> */
    public function variableNames(): array
    {
        return $this->variableNames;
    }
}
