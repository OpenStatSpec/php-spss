<?php

declare(strict_types=1);

namespace SPSS\Sav;

use SPSS\Buffer;

interface RecordInterface
{
    /**
     * @var int Record type code
     */
    public const TYPE = 0;

    /**
     * @return void
     */
    public function read(Buffer $buffer);

    /**
     * @return void
     */
    public function write(Buffer $buffer);
}
