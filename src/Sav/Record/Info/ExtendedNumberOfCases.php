<?php

declare(strict_types=1);

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class ExtendedNumberOfCases extends Info
{
    public const SUBTYPE = 16;

    /**
     * @var float
     */
    public $ncases = 0;

    /**
     * @var int
     */
    protected $dataSize = 8;

    /**
     * @var int
     */
    protected $dataCount = 2;

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $buffer->readDouble();
        $this->ncases = $buffer->readDouble();
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        parent::write($buffer);
        $buffer->writeDouble(1);
        $buffer->writeDouble($this->ncases);
    }
}
