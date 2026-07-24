<?php

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class VeryLongString extends Info
{
    public const SUBTYPE   = 14;

    public const DELIMITER = "\t";

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $data = rtrim($buffer->readString($this->dataSize * $this->dataCount));
        foreach (explode(self::DELIMITER, $data) as $item) {
            [$key, $value] = explode('=', $item);
            $this->data[$key] = (int) $value;
        }
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        if ([] !== $this->data) {
            $data = [];
            foreach ($this->data as $key => $value) {
                $data[] = sprintf('%s=%05d%c', $key, $value, 0);
            }

            $data = implode(self::DELIMITER, $data);
            $this->dataCount = \strlen($data);
            parent::write($buffer);
            $buffer->writeString($data);
        }
    }
}
