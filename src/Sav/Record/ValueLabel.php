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

    /** Whether values use SPSS's 8-byte short-string representation. */
    public ?bool $stringValues = null;

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
        $labelCount = $buffer->readInt();
        if (false === $labelCount || $labelCount < 0) {
            throw new Exception(sprintf(
                'Invalid SPSS value label record: label count must be non-negative, got %s.',
                false === $labelCount ? 'truncated input' : (string) $labelCount,
            ));
        }

        $minimumLabelBytes = 16;
        $reservedIndexHeaderBytes = 8;
        $availableLabelBytes = max($buffer->remaining() - $reservedIndexHeaderBytes, 0);
        $maximumLabelCount = intdiv($availableLabelBytes, $minimumLabelBytes);
        if ($labelCount > $maximumLabelCount) {
            throw new Exception(sprintf(
                'Invalid SPSS value label record: declares %d labels, but the payload can contain at most %d.',
                $labelCount,
                $maximumLabelCount,
            ));
        }

        for ($i = 0; $i < $labelCount; $i++) {
            $value = $buffer->readDouble();
            $labelLengthByte = $buffer->read(1);
            if (false === $value || false === $labelLengthByte) {
                throw new Exception(sprintf('Invalid SPSS value label record: label %d is truncated.', $i + 1));
            }

            $labelLength = \ord($labelLengthByte);
            $label = $buffer->readString(Utils::roundUp($labelLength + 1, 8) - 1);
            if (false === $label) {
                throw new Exception(sprintf('Invalid SPSS value label record: label %d text is truncated.', $i + 1));
            }

            $this->labels[] = [
                'value' => $value,
                'label' => rtrim($label),
            ];
        }

        $recType = $buffer->readInt();
        if (4 !== $recType) {
            throw new Exception(sprintf('Error reading Variable Index record: bad record type [%s]. Expecting Record Type 4.', $recType));
        }

        $varCount = $buffer->readInt();
        if (false === $varCount || $varCount < 0) {
            throw new Exception(sprintf(
                'Invalid SPSS value label record: variable count must be non-negative, got %s.',
                false === $varCount ? 'truncated input' : (string) $varCount,
            ));
        }

        $maximumVariableCount = intdiv($buffer->remaining(), 4);
        if ($varCount > $maximumVariableCount) {
            throw new Exception(sprintf(
                'Invalid SPSS value label record: declares %d variables, but the payload can contain at most %d.',
                $varCount,
                $maximumVariableCount,
            ));
        }

        $decodeShortVar = false;
        for ($i = 0; $i < $varCount; $i++) {
            $rawVarIndex = $buffer->readInt();
            if (false === $rawVarIndex) {
                throw new Exception(sprintf('Invalid SPSS value label record: variable index %d is truncated.', $i + 1));
            }

            $varIndex = $rawVarIndex - 1;
            $this->indexes[] = $varIndex;

            if (isset($this->variables[$varIndex]) && ($this->variables[$varIndex]->width > 0)) {
                $decodeShortVar = true;
            }
        }

        $this->stringValues = $decodeShortVar;

        if ($decodeShortVar) {
            foreach ($this->labels as $labelIdx => $label) {
                $this->labels[$labelIdx]['value'] = rtrim(Utils::doubleToString($label['value']));
            }
        }
    }

    public function write(Buffer $buffer): void
    {
        $convertToDouble = $this->stringValues;
        if (null === $convertToDouble) {
            $convertToDouble = false;
            foreach ($this->indexes as $variableIndex) {
                if (isset($this->variables[$variableIndex]) && $this->variables[$variableIndex]->width > 0) {
                    $convertToDouble = true;
                    break;
                }
            }
        }

        // Value label record.
        $buffer->writeInt(self::TYPE);
        $buffer->writeInt(\count($this->labels));
        foreach ($this->labels as $item) {
            $label = $buffer->encodeString($item['label'], self::LABEL_MAX_LENGTH);
            $labelLengthBytes = \strlen($label);

            if ($convertToDouble) {
                $item['value'] = Utils::stringToDouble($item['value']);
            }

            $buffer->writeDouble($item['value']);
            $buffer->write(\chr($labelLengthBytes));
            $buffer->writeString(
                $label,
                Utils::roundUp($labelLengthBytes + 1, 8) - 1,
                $buffer->charset ?? mb_internal_encoding(),
            );
        }

        // Value label variable record.
        $buffer->writeInt(4);
        $buffer->writeInt(\count($this->indexes));
        foreach ($this->indexes as $varIndex) {
            $buffer->writeInt($varIndex + 1);
        }
    }
}
