<?php

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class LongVariableNames extends Info
{
    public const SUBTYPE   = 13;

    public const DELIMITER = "\t";

    /**
     * @var array<array-key, mixed>
     */
    public $data = [];

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $data = rtrim($buffer->readString($this->dataSize * $this->dataCount));

        foreach (explode(self::DELIMITER, $data) as $item) {
            [$key, $value] = explode('=', $item);
            $this->data[$key] = trim($value);
        }
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        $data = '';
        foreach ($this->data as $key => $value) {
            $data .= sprintf('%s=%s', $key, $value) . self::DELIMITER;
        }

        $this->dataCount = \strlen($data);
        parent::write($buffer);
        $buffer->writeString($data);
    }
}
