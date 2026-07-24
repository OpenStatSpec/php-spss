<?php

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;
use SPSS\Utils;

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
        $buffer = $buffer->allocate($this->dataCount * $this->dataSize);
        while ($varNameLength = $buffer->readInt()) {
            $varName              = $buffer->readString($varNameLength);
            $varWidth             = $buffer->readInt(); // The width of the variable, in bytes, which will be between 9 and 32767
            $valuesCount          = $buffer->readInt();
            $this->data[$varName] = [
                'width'  => $varWidth,
                'values' => [],
            ];
            for ($i = 0; $i < $valuesCount; $i++) {
                $valueLength                            = $buffer->readInt();
                $value                                  = rtrim($buffer->readString($valueLength));
                $labelLength                            = $buffer->readInt();
                $label                                  = rtrim($buffer->readString($labelLength));
                $this->data[$varName]['values'][$value] = $label;
            }
        }
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        $localBuffer = Buffer::factory('', ['memory' => true]);
        foreach ($this->data as $varName => $data) {
            if (!isset($data['width'])) {
                throw new \InvalidArgumentException('width required');
            }

            if (!isset($data['values'])) {
                throw new \InvalidArgumentException('values required');
            }

            $width = (int) $data['width'];
            $localBuffer->writeInt(mb_strlen((string) $varName));
            $localBuffer->writeString($varName, mb_strlen((string) $varName));
            $localBuffer->writeInt($width);
            $localBuffer->writeInt(Utils::is_countable($data['values']) ? \count($data['values']) : 0);
            foreach ($data['values'] as $value => $label) {
                $localBuffer->writeInt($width);
                $localBuffer->writeString($value, $width);
                $localBuffer->writeInt(mb_strlen((string) $label));
                $localBuffer->writeString($label, mb_strlen((string) $label));
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
