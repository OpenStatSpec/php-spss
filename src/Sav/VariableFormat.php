<?php

declare(strict_types=1);

namespace SPSS\Sav;

final readonly class VariableFormat
{
    public function __construct(
        public int $code,
        public int $width,
        public int $decimals = 0,
    ) {
        if ($this->code < 0 || $this->code > 255) {
            throw new \InvalidArgumentException('Format code must fit in one unsigned byte.');
        }

        if ($this->width < 0 || $this->width > 255) {
            throw new \InvalidArgumentException('Format width must fit in one unsigned byte.');
        }

        if ($this->decimals < 0 || $this->decimals > 255) {
            throw new \InvalidArgumentException('Format decimals must fit in one unsigned byte.');
        }
    }
}
