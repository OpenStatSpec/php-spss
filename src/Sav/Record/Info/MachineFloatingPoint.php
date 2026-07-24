<?php

declare(strict_types=1);

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

// Available as of PHP 7.2.0.
if (!\defined('PHP_FLOAT_MAX')) {
    \define('PHP_FLOAT_MAX', 1.7976931348623e+308);
}

/**
 * @see \SPSS\Tests\MachineFloatingPointTest
 */
class MachineFloatingPoint extends Info
{
    public const SUBTYPE = 4;

    /**
     * @var float
     */
    public $sysmis;

    /**
     * @var float
     */
    public $highest;

    /**
     * @var float
     */
    public $lowest;

    /**
     * @var int always set to 8
     */
    protected $dataSize = 8;

    /**
     * @var int always set to 3
     */
    protected $dataCount = 3;

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $this->sysmis  = $buffer->readDouble();
        $this->highest = $buffer->readDouble();
        $this->lowest  = $buffer->readDouble();
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        if ($this->sysmis === null) {
            $this->sysmis = -PHP_FLOAT_MAX;
        }

        if ($this->highest === null) {
            $this->highest = PHP_FLOAT_MAX;
        }

        if ($this->lowest === null) {
            $this->lowest = -PHP_FLOAT_MAX;
        }

        parent::write($buffer);
        $buffer->writeDouble($this->sysmis);
        $buffer->writeDouble($this->highest);
        $buffer->writeDouble($this->lowest);
    }
}
