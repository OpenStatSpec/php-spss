<?php

declare(strict_types=1);

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class DataFileAttributes extends Info
{
    public const SUBTYPE = 17;

    /** @var array<array-key, mixed> */
    public $data = [];

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $payload = $this->readPayload($buffer);
        $this->data = AttributeSetCodec::decode($payload, $buffer->charset);
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        /** @var array<string, list<string>> $attributes */
        $attributes = $this->data;
        $payload = AttributeSetCodec::encode($attributes, $buffer->charset);
        $this->dataSize = 1;
        $this->dataCount = strlen($payload);
        parent::write($buffer);
        $buffer->write($payload);
    }

    private function readPayload(Buffer $buffer): string
    {
        if (1 !== $this->dataSize) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed data file attributes record: element size must be 1, got %d.',
                $this->dataSize,
            ));
        }

        if ($this->dataCount <= 0) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed data file attributes record: byte count must be positive, got %d.',
                $this->dataCount,
            ));
        }

        $payload = $buffer->read($this->dataCount);
        if (false === $payload || strlen($payload) !== $this->dataCount) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed data file attributes record: expected %d payload bytes, got %d.',
                $this->dataCount,
                false === $payload ? 0 : strlen($payload),
            ));
        }

        return $payload;
    }
}
