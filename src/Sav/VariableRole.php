<?php

declare(strict_types=1);

namespace SPSS\Sav;

enum VariableRole: int
{
    case INPUT = 0;
    case TARGET = 1;
    case BOTH = 2;
    case NONE = 3;
    case PARTITION = 4;
    case SPLIT = 5;
}
