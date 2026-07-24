<?php

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class CharacterEncoding extends Info
{
    public const SUBTYPE = 20;

    public string $value;

    /**
     * @param array<array-key, mixed>|string $value
     */
    public function __construct(array|string $value = [])
    {
        if (is_array($value)) {
            parent::__construct($value);
            $this->value ??= '';

            return;
        }

        parent::__construct();
        $this->value = $value;
    }

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $this->value = $buffer->readString($this->dataSize * $this->dataCount);
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        $this->dataCount = \strlen($this->value);
        parent::write($buffer);
        $buffer->writeString($this->value);
    }
}
