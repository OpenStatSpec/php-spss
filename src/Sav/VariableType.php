<?php

declare(strict_types=1);

namespace SPSS\Sav;

enum VariableType: string
{
    case NUMERIC = 'numeric';
    case STRING = 'string';

    public static function fromWidth(int $width): self
    {
        if ($width < 0) {
            throw new \InvalidArgumentException('Variable width cannot be negative.');
        }

        return 0 === $width ? self::NUMERIC : self::STRING;
    }
}
