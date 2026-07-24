<?php

declare(strict_types=1);

namespace SPSS\Sav;

enum MultipleResponseLabelSource: string
{
    case SET_LABEL = 'set_label';
    case VARIABLE_LABEL = 'variable_label';
}
