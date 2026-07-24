<?php

declare(strict_types=1);

namespace SPSS\Sav;

enum MultipleResponseSetType: string
{
    case CATEGORY = 'category';
    case DICHOTOMY = 'dichotomy';
}
