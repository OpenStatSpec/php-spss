<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class ValueLabelSet
{
    /** @var list<ValueLabel> */
    private array $labels;

    /** @var list<string> */
    private array $variableNames;

    /**
     * @param list<ValueLabel> $labels
     * @param list<string>     $variableNames
     */
    public function __construct(array $labels, array $variableNames = [])
    {
        foreach ($variableNames as $variableName) {
            if ('' === trim($variableName)) {
                throw new \InvalidArgumentException('A value label variable name cannot be empty.');
            }
        }

        $this->labels = $labels;
        $this->variableNames = $variableNames;
    }

    /** @return list<ValueLabel> */
    public function labels(): array
    {
        return $this->labels;
    }

    /** @return list<string> */
    public function variableNames(): array
    {
        return $this->variableNames;
    }
}
