<?php

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class LongStringValueLabels extends Info
{
    public const SUBTYPE = 21;

    /**
     * @var array<array-key, mixed>
     */
    public $data = [];

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

            $varWidth = $buffer->readInt();
            if (false === $varWidth || $varWidth < 9 || $varWidth > 32767) {
                throw new \InvalidArgumentException('width must be between 9 and 32767 bytes');
            }

            $valuesCount = $buffer->readInt();
            if (false === $valuesCount || $valuesCount < 0) {
                throw new \InvalidArgumentException('Invalid value label count.');
            }

            $this->data[$varName] = [
                'width'  => $varWidth,
                'values' => [],
            ];
            for ($i = 0; $i < $valuesCount; $i++) {
                $valueLength = $buffer->readInt();
                if ($valueLength !== $varWidth) {
                    throw new \InvalidArgumentException('Value length must equal the variable width.');
                }

                $value = $buffer->readString($valueLength);
                if (false === $value) {
                    throw new \InvalidArgumentException('Unable to read value label value.');
                }

                $labelLength = $buffer->readInt();
                if (false === $labelLength || $labelLength < 0 || $labelLength > 120) {
                    throw new \InvalidArgumentException('label must not exceed 120 bytes');
                }

                $label = $buffer->readString($labelLength);
                if (false === $label) {
                    throw new \InvalidArgumentException('Unable to read value label.');
                }

                $value = rtrim($value, ' ');
                $this->data[$varName]['values'][$value] = $label;
            }
        }
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        $localBuffer = Buffer::factory('', ['memory' => true]);
        $localBuffer->charset = $buffer->charset;
        $localBuffer->isBigEndian = $buffer->isBigEndian;
        foreach ($this->data as $varName => $data) {
            if (!isset($data['width'])) {
                throw new \InvalidArgumentException('width required');
            }

            if (!isset($data['values'])) {
                throw new \InvalidArgumentException('values required');
            }

            if (!\is_array($data['values'])) {
                throw new \InvalidArgumentException('values must be an array');
            }

            $width = (int) $data['width'];
            if ($width < 9 || $width > 32767) {
                throw new \InvalidArgumentException('width must be between 9 and 32767 bytes');
            }

            $varName = (string) $varName;
            $varNameLength = $this->encodedLength($buffer, $varName);
            $localBuffer->writeInt($varNameLength);
            $localBuffer->writeString($varName, $varNameLength);
            $localBuffer->writeInt($width);
            $localBuffer->writeInt(\count($data['values']));
            foreach ($data['values'] as $value => $label) {
                $value = (string) $value;
                if ($this->encodedLength($buffer, $value) > $width) {
                    throw new \InvalidArgumentException('value exceeds the variable width');
                }

                $label = (string) $label;
                $labelLength = $this->encodedLength($buffer, $label);
                if ($labelLength > 120) {
                    throw new \InvalidArgumentException('label must not exceed 120 bytes');
                }

                $localBuffer->writeInt($width);
                $localBuffer->writeString($value, $width);
                $localBuffer->writeInt($labelLength);
                $localBuffer->writeString($label, $labelLength);
            }
        }

        // retrieve bytes count
        $this->dataCount = $localBuffer->position();
        if ($this->dataCount > 0) {
            parent::write($buffer);
            $localBuffer->rewind();
            $buffer->writeStream($localBuffer->getStream());
        }
    }

    private function encodedLength(Buffer $buffer, string $value): int
    {
        $charsetTo = $buffer->charset ?? mb_internal_encoding();
        $charsetFrom = mb_internal_encoding();
        if (strtolower($charsetFrom) !== strtolower($charsetTo)) {
            $value = mb_convert_encoding($value, $charsetTo, $charsetFrom);
        }

        return \strlen($value);
    }
}
