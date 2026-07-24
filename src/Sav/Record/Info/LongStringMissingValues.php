<?php

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class LongStringMissingValues extends Info
{
    public const SUBTYPE = 22;

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $payloadLength = $this->dataCount * $this->dataSize;
        $buffer = $buffer->allocate($payloadLength);
        while ($buffer->position() < $payloadLength) {
            $varNameLength = $buffer->readInt();
            if (false === $varNameLength || $varNameLength <= 0) {
                throw new \InvalidArgumentException('Invalid variable name length.');
            }

            $varName = $buffer->readString($varNameLength);
            if (false === $varName) {
                throw new \InvalidArgumentException('Unable to read variable name.');
            }

            $countByte = $buffer->read(1);
            if (false === $countByte || \strlen($countByte) !== 1) {
                throw new \InvalidArgumentException('Unable to read missing value count.');
            }

            $count = \ord($countByte);
            if ($count < 1 || $count > 3) {
                throw new \InvalidArgumentException('Missing value count must be between 1 and 3.');
            }

            $valueLength = $buffer->readInt();
            if (8 !== $valueLength) {
                throw new \InvalidArgumentException('Missing value length must be 8 bytes.');
            }

            $this->data[$varName] = [];
            for ($i = 0; $i < $count; $i++) {
                $value = $buffer->readString($valueLength);
                if (false === $value) {
                    throw new \InvalidArgumentException('Unable to read string missing value.');
                }

                $this->data[$varName][] = rtrim($value, ' ');
            }
        }
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        if ([] !== $this->data) {
            $localBuffer = Buffer::factory('', ['memory' => true]);
            $localBuffer->charset = $buffer->charset;
            $localBuffer->isBigEndian = $buffer->isBigEndian;
            foreach ($this->data as $varName => $values) {
                if (!\is_array($values)) {
                    throw new \InvalidArgumentException('Missing values must be an array.');
                }

                $count = \count($values);
                if ($count < 1 || $count > 3) {
                    throw new \InvalidArgumentException('Missing value count must be between 1 and 3.');
                }

                $varName = (string) $varName;
                $varNameLength = \strlen($this->encode($buffer, $varName));
                $localBuffer->writeInt($varNameLength);
                $localBuffer->writeString($varName, $varNameLength);
                $localBuffer->write(\chr($count), 1);
                $localBuffer->writeInt(8);
                foreach ($values as $value) {
                    $value = (string) $value;
                    if (\strlen(rtrim($this->encode($buffer, $value), ' ')) > 8) {
                        throw new \InvalidArgumentException('Only the first 8 bytes of a long string missing value may be non-spaces.');
                    }

                    $localBuffer->writeString($value, 8);
                }
            }

            $this->dataCount = $localBuffer->position();
            parent::write($buffer);
            $localBuffer->rewind();
            $buffer->writeStream($localBuffer->getStream(), $this->dataCount);
        }
    }

    private function encode(Buffer $buffer, string $value): string
    {
        $charsetTo = $buffer->charset ?? mb_internal_encoding();
        $charsetFrom = mb_internal_encoding();
        if (strtolower($charsetFrom) !== strtolower($charsetTo)) {
            return mb_convert_encoding($value, $charsetTo, $charsetFrom);
        }

        return $value;
    }
}
