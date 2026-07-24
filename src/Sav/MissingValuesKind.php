<?php

declare(strict_types=1);

namespace SPSS\Sav;

enum MissingValuesKind: string
{
    case NONE = 'none';
    case DISCRETE = 'discrete';
    case RANGE = 'range';
    case RANGE_AND_VALUE = 'range_and_value';
}
