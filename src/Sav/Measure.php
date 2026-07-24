<?php

declare(strict_types=1);

namespace SPSS\Sav;

enum Measure: int
{
    case UNKNOWN = 0;
    case NOMINAL = 1;
    case ORDINAL = 2;
    case SCALE = 3;
}
