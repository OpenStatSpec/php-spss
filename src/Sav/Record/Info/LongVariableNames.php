<?php

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Exception;
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
            $parts = explode('=', $item, 2);
            if (2 !== count($parts) || '' === $parts[0]) {
                throw new Exception('Invalid long variable names record: expected a name=value entry.');
            }

            $this->data[$parts[0]] = trim($parts[1]);
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
