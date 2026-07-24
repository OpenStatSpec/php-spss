<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class MissingValues
{
    /** @var list<int|float|string> */
    private array $discreteValues;

    /** @param list<int|float|string> $discreteValues */
    private function __construct(
        public MissingValuesKind $kind,
        array $discreteValues = [],
        public int|float|null $lower = null,
        public int|float|null $upper = null,
        public int|float|null $additionalValue = null,
    ) {
        $this->discreteValues = $discreteValues;
    }

    public static function none(): self
    {
        return new self(MissingValuesKind::NONE);
    }

    public static function discrete(int|float|string ...$values): self
    {
        if ([] === $values || \count($values) > 3) {
            throw new \InvalidArgumentException('Discrete missing values require between one and three values.');
        }

        self::assertCompatibleValues($values);

        return new self(MissingValuesKind::DISCRETE, $values);
    }

    public static function range(int|float $lower, int|float $upper): self
    {
        self::assertValidRange($lower, $upper);

        return new self(MissingValuesKind::RANGE, lower: $lower, upper: $upper);
    }

    public static function rangeAndValue(
        int|float $lower,
        int|float $upper,
        int|float $value,
    ): self {
        self::assertValidRange($lower, $upper);
        self::assertFinite($value);

        return new self(
            MissingValuesKind::RANGE_AND_VALUE,
            lower: $lower,
            upper: $upper,
            additionalValue: $value,
        );
    }

    /** @return list<int|float|string> */
    public function discreteValues(): array
    {
        return $this->discreteValues;
    }

    /** @param list<int|float|string> $values */
    private static function assertCompatibleValues(array $values): void
    {
        $usesStrings = \is_string($values[0]);

        foreach ($values as $value) {
            if (\is_float($value) && !is_finite($value)) {
                throw new \InvalidArgumentException('A user-missing value must be finite.');
            }

            if ($usesStrings !== \is_string($value)) {
                throw new \InvalidArgumentException('Missing values cannot mix string and numeric values.');
            }
        }
    }

    private static function assertValidRange(
        int|float $lower,
        int|float $upper,
    ): void {
        self::assertFinite($lower);
        self::assertFinite($upper);

        if ($lower > $upper) {
            throw new \InvalidArgumentException('A missing-value range lower bound cannot exceed its upper bound.');
        }
    }

    private static function assertFinite(int|float $value): void
    {
        if (\is_float($value) && !is_finite($value)) {
            throw new \InvalidArgumentException('A user-missing value must be finite.');
        }
    }
}
