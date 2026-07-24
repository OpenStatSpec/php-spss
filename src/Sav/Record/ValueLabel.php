<?php

namespace SPSS\Sav\Record;

use SPSS\Buffer;
use SPSS\Exception;
use SPSS\Sav\Record;
use SPSS\Utils;

/**
 * The value label records documented in this section are used for numeric and short string variables only.
 * Long string variables may have value labels, but their value labels are recorded using a different record type.
 *
 * @see Info\LongStringValueLabels
 */
class ValueLabel extends Record
{
    public const TYPE             = 3;

    public const LABEL_MAX_LENGTH = 255;

    /**
     * @var list<array{value: float|string, label: string}>
     */
    public $labels = [];

    /**
     * @var list<int>
     *            A list of dictionary indexes of variables to which to apply the value labels
     *            String variables wider than 8 bytes may not be specified in this list
     */
    public $indexes = [];

    /**
     * @var list<Variable>
     */
    protected $variables = [];

    /**
     * @param list<Variable> $variables
     */
    public function setVariables(array $variables): void
    {
        $this->variables = $variables;
    }

    public function read(Buffer $buffer): void
    {
        /** @var int $labelCount Number of value labels present in this record. */
        $labelCount = $buffer->readInt();

        for ($i = 0; $i < $labelCount; $i++) {
            // A numeric value or a short string value padded as necessary to 8 bytes in length.
            $value          = $buffer->readDouble();
            $labelLength    = \ord($buffer->read(1));
            $label          = $buffer->readString(Utils::roundUp($labelLength + 1, 8) - 1);
            $this->labels[] = [
                'value' => $value,
                'label' => rtrim($label),
            ];
        }

        // The value label variables record is always immediately followed after a value label record.
        $recType = $buffer->readInt();
        if (4 !== $recType) {
            throw new Exception(sprintf('Error reading Variable Index record: bad record type [%s]. Expecting Record Type 4.', $recType));
        }

        // Number of variables that the associated value labels from the value label record are to be applied.
        $varCount = $buffer->readInt();
        $decodeShortVar = false;
        for ($i = 0; $i < $varCount; $i++) {
            $varIndex = $buffer->readInt() - 1;
            $this->indexes[] = $varIndex;

            if (isset($this->variables[$varIndex]) && ($this->variables[$varIndex]->width > 0)) {
                $decodeShortVar = true;
            }
        }

        // Decode values for short variables
        if ($decodeShortVar) {
            foreach ($this->labels as $labelIdx => $label) {
                $this->labels[$labelIdx]['value'] = rtrim(Utils::doubleToString($label['value']));
            }
        }
    }

    public function write(Buffer $buffer): void
    {
        $var = (count($this->variables) > 0) ? $this->variables[count($this->variables) - 1] : null;
        $convertToDouble = (isset($var) && ($var->width > 0));

        // Value label record.
        $buffer->writeInt(self::TYPE);
        $buffer->writeInt(\count($this->labels));
        foreach ($this->labels as $item) {
            $labelLength      = min(mb_strlen($item['label']), self::LABEL_MAX_LENGTH);
            $label            = mb_substr($item['label'], 0, $labelLength);
            $labelLengthBytes = mb_strlen($label, '8bit');
            while ($labelLengthBytes > 255) {
                // Strip one char, can be multiple bytes
                $label            = mb_substr($label, 0, -1);
                $labelLengthBytes = mb_strlen($label, '8bit');
            }

            if ($convertToDouble) {
                $item['value'] = Utils::stringToDouble($item['value']);
            }

            $buffer->writeDouble($item['value']);
            $buffer->write(\chr($labelLengthBytes));
            $buffer->writeString($label, Utils::roundUp($labelLengthBytes + 1, 8) - 1);
        }

        // Value label variable record.
        $buffer->writeInt(4);
        $buffer->writeInt(\count($this->indexes));
        foreach ($this->indexes as $varIndex) {
            $buffer->writeInt($varIndex);
        }
    }
}
