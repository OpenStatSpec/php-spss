<?php

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class Unknown extends Info
{
    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $this->data['raw'] = $buffer->readString($this->dataSize * $this->dataCount);
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        if (!isset($this->data['raw'])) {
            $this->data['raw'] = '';
        }

        $this->dataCount = \strlen($this->data['raw']);
        parent::write($buffer);
        $buffer->writeString($this->data['raw']);
    }
}
