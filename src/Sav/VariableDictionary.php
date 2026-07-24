<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class VariableDictionary implements \Countable
{
    /** @var list<VariableMetadata> */
    private array $variables;

    /** @param list<VariableMetadata> $variables */
    public function __construct(array $variables = [])
    {

        /** @var array<string, int> $names */
        $names = [];
        foreach ($variables as $index => $variable) {

            foreach ([$variable->name, $variable->shortName] as $name) {
                if (null === $name) {
                    continue;
                }

                $normalized = mb_strtolower($name);
                if (isset($names[$normalized]) && $names[$normalized] !== $index) {
                    throw new \InvalidArgumentException(sprintf('Duplicate dictionary variable name "%s".', $name));
                }

                $names[$normalized] = $index;
            }
        }

        $this->variables = $variables;
    }

    /** @return list<VariableMetadata> */
    public function variables(): array
    {
        return $this->variables;
    }

    public function variable(string $name): ?VariableMetadata
    {
        foreach ($this->variables as $variable) {
            if (
                0 === strcasecmp($variable->name, $name)
                || (null !== $variable->shortName && 0 === strcasecmp($variable->shortName, $name))
            ) {
                return $variable;
            }
        }

        return null;
    }

    public function count(): int
    {
        return \count($this->variables);
    }
}
