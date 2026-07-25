<?php

namespace SPSS\Sav\Record;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Record;

/** @implements \ArrayAccess<array-key, mixed> */
class Document extends Record implements \ArrayAccess
{
    public const TYPE   = 6;

    public const LENGTH = 80;

    /**
     * @var array<array-key, mixed>
     */
    protected $lines = [];

    public function read(Buffer $buffer): void
    {
        $count = $buffer->readInt();
        if (false === $count) {
            throw new Exception('Invalid SPSS document record: missing line count.');
        }

        if ($count < 0) {
            throw new Exception(sprintf('Invalid SPSS document record: negative line count %d.', $count));
        }

        $maximumCount = intdiv($buffer->remaining(), self::LENGTH);
        if ($count > $maximumCount) {
            throw new Exception(sprintf(
                'Invalid SPSS document record: declares %d lines, but the payload can contain at most %d.',
                $count,
                $maximumCount,
            ));
        }

        for ($i = 0; $i < $count; $i++) {
            $line = $buffer->readString(self::LENGTH);
            if (false === $line) {
                throw new Exception(sprintf('Invalid SPSS document record: line %d is truncated.', $i + 1));
            }

            $this->lines[] = trim($line);
        }
    }

    public function write(Buffer $buffer): void
    {
        $buffer->writeInt(self::TYPE);
        $buffer->writeInt(\count($this->lines));
        foreach ($this->lines as $line) {
            $buffer->writeString((string) $line, self::LENGTH);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    #[\Override]
    public function toArray(): array
    {
        return $this->lines;
    }

    /**
     * @param array<array-key, mixed> $lines
     */
    public function append(array $lines): void
    {
        foreach ($lines as $line) {
            $this->lines[] = $line;
        }
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->lines[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->lines[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (null === $offset) {
            $this->lines[] = $value;
        } else {
            $this->lines[$offset] = $value;
        }
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->lines[$offset]);
    }
}
