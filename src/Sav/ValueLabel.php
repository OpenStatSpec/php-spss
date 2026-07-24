<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class ValueLabel
{
    public function __construct(
        public int|float|string $value,
        public string $label,
    ) {
        if (\is_float($this->value) && !is_finite($this->value)) {
            throw new \InvalidArgumentException('A value label value must be finite.');
        }
    }
}
