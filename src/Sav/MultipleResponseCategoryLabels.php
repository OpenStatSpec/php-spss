<?php

declare(strict_types=1);

namespace SPSS\Sav;

enum MultipleResponseCategoryLabels: string
{
    case VARIABLE_LABELS = 'variable_labels';
    case COUNTED_VALUES = 'counted_values';
}
