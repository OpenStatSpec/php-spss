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
                'labels' => [],
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
                $this->data[$varName]['labels'][] = [
                    'value' => $value,
                    'label' => $label,
                ];
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

            $labels = $data['labels'] ?? null;
            if (null === $labels) {
                if (!isset($data['values'])) {
                    throw new \InvalidArgumentException('values or labels required');
                }
                if (!\is_array($data['values'])) {
                    throw new \InvalidArgumentException('values must be an array');
                }

                $labels = [];
                foreach ($data['values'] as $value => $label) {
                    $labels[] = ['value' => (string) $value, 'label' => (string) $label];
                }
            }
            if (!\is_array($labels)) {
                throw new \InvalidArgumentException('labels must be an array');
            }

            $width = (int) $data['width'];
            if ($width < 9 || $width > 32767) {
                throw new \InvalidArgumentException('width must be between 9 and 32767 bytes');
            }

            $varName = (string) $varName;
            $varNameLength = $buffer->encodedStringLength($varName);
            $localBuffer->writeInt($varNameLength);
            $localBuffer->writeString($varName, $varNameLength);
            $localBuffer->writeInt($width);
            $localBuffer->writeInt(\count($labels));
            foreach ($labels as $entry) {
                if (!\is_array($entry) || !array_key_exists('value', $entry) || !array_key_exists('label', $entry)) {
                    throw new \InvalidArgumentException('Each long string value label requires value and label keys.');
                }

                $value = $entry['value'];
                $label = $entry['label'];
                if (!\is_string($value) || !\is_string($label)) {
                    throw new \InvalidArgumentException('Long string value label values and labels must be strings.');
                }
                if ($buffer->encodedStringLength($value) > $width) {
                    throw new \InvalidArgumentException('value exceeds the variable width');
                }

                $labelLength = $buffer->encodedStringLength($label);
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
}
