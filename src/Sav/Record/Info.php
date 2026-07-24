<?php

declare(strict_types=1);

namespace SPSS\Sav\Record;

use SPSS\Buffer;
use SPSS\Sav\Record;

/** @implements \ArrayAccess<array-key, mixed> */
class Info extends Record implements \ArrayAccess
{
    public const TYPE    = 7;

    public const SUBTYPE = 0;

    /**
     * @var array<array-key, mixed>
     */
    protected $data = [];

    /**
     * @var int Size of each piece of data in the data part, in bytes
     */
    protected $dataSize = 1;

    /**
     * @var int Number of pieces of data in the data part
     */
    protected $dataCount = 0;

    public function read(Buffer $buffer): void
    {
        $this->dataSize  = $buffer->readInt();
        $this->dataCount = $buffer->readInt();
    }

    public function write(Buffer $buffer): void
    {
        $buffer->writeInt(self::TYPE);
        $buffer->writeInt(static::SUBTYPE);
        $buffer->writeInt($this->dataSize);
        $buffer->writeInt($this->dataCount);
    }

    /**
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->data[] = $value;
        } else {
            $this->data[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}
