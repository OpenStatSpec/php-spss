<?php

declare(strict_types=1);

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class MultipleResponseSets extends Info
{
    public const SUBTYPE = 7;

    public const COUNTED_VALUES_SUBTYPE = 19;

    public int $subtype = self::SUBTYPE;

    /** @var array<array-key, mixed> */
    public $data = [];

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $this->assertSubtype();
        $payload = $this->readPayload($buffer);
        $this->data = [];
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            while ($offset < $length && "\n" === $payload[$offset]) {
                ++$offset;
            }

            if ($offset >= $length) {
                break;
            }

            $recordOffset = $offset;
            $equalsOffset = strpos($payload, '=', $offset);
            $lineFeedOffset = strpos($payload, "\n", $offset);
            if (false === $equalsOffset || (false !== $lineFeedOffset && $equalsOffset > $lineFeedOffset)) {
                throw $this->malformed($recordOffset, 'missing equals sign after the set name');
            }

            $rawName = substr($payload, $offset, $equalsOffset - $offset);
            if ('' === $rawName || '$' !== $rawName[0] || preg_match('/[\x20\r\n]/', $rawName)) {
                throw $this->malformed($recordOffset, 'set name must begin with "$" and cannot contain whitespace');
            }

            $name = $this->decode($rawName, $buffer);
            if (isset($this->data[$name])) {
                throw $this->malformed($recordOffset, sprintf('duplicate set name "%s"', $name));
            }

            $offset = $equalsOffset + 1;
            $type = $this->readByte($payload, $offset, $recordOffset, 'set type');
            if (!in_array($type, $this->allowedTypes(), true)) {
                throw $this->malformed($recordOffset, sprintf(
                    'type "%s" is invalid for subtype %d; expected %s',
                    $type,
                    $this->subtype,
                    implode(' or ', $this->allowedTypes()),
                ));
            }

            $labelSource = null;
            if ('E' === $type) {
                $this->expectSpace($payload, $offset, $recordOffset, 'before label source');
                $labelSourceValue = $this->readDecimal($payload, $offset, $recordOffset, 'label source');
                if (1 !== $labelSourceValue && 11 !== $labelSourceValue) {
                    throw $this->malformed($recordOffset, sprintf(
                        'label source must be 1 or 11, got %d',
                        $labelSourceValue,
                    ));
                }

                $labelSource = $labelSourceValue;
                $this->expectSpace($payload, $offset, $recordOffset, 'after label source');
            }

            $countedValue = null;
            if ('D' === $type || 'E' === $type) {
                $countedValueLength = $this->readDecimal($payload, $offset, $recordOffset, 'counted value length');
                if ($countedValueLength <= 0) {
                    throw $this->malformed($recordOffset, 'counted value length must be positive');
                }

                $this->expectSpace($payload, $offset, $recordOffset, 'after counted value length');
                $countedValue = $this->decode(
                    $this->readSizedValue($payload, $offset, $countedValueLength, $recordOffset, 'counted value'),
                    $buffer,
                );
            }

            $this->expectSpace($payload, $offset, $recordOffset, 'before label length');
            $labelLength = $this->readDecimal($payload, $offset, $recordOffset, 'label length');
            $this->expectSpace($payload, $offset, $recordOffset, 'after label length');
            $label = $this->decode(
                $this->readSizedValue($payload, $offset, $labelLength, $recordOffset, 'label'),
                $buffer,
            );
            $this->expectSpace($payload, $offset, $recordOffset, 'before variable names');

            $lineFeedOffset = strpos($payload, "\n", $offset);
            if (false === $lineFeedOffset) {
                throw $this->malformed($recordOffset, 'set must end with a line feed');
            }

            $rawVariables = substr($payload, $offset, $lineFeedOffset - $offset);
            if (str_contains($rawVariables, "\r") || str_contains($rawVariables, '  ')
                || str_starts_with($rawVariables, ' ') || str_ends_with($rawVariables, ' ')) {
                throw $this->malformed($recordOffset, 'variable names must be separated by one space');
            }

            $variables = [];
            if ('' !== $rawVariables) {
                foreach (explode(' ', $rawVariables) as $rawVariable) {
                    if ($rawVariable !== strtolower($rawVariable)) {
                        throw $this->malformed($recordOffset, sprintf(
                            'variable name "%s" must be lowercase',
                            $rawVariable,
                        ));
                    }

                    $variables[] = $this->decode($rawVariable, $buffer);
                }
            }

            $this->data[$name] = [
                'type' => $type,
                'countedValue' => $countedValue,
                'label' => $label,
                'labelSource' => $labelSource,
                'variables' => $variables,
            ];
            $offset = $lineFeedOffset + 1;
        }
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        $this->assertSubtype();
        $payload = '';
        /** @var array<string, array{type: 'C'|'D'|'E', countedValue: string|null, label: string, labelSource: 1|11|null, variables: list<string>}> $sets */
        $sets = $this->data;
        foreach ($sets as $name => $set) {
            $rawName = $this->encode($name, $buffer);
            if ('' === $rawName || '$' !== $rawName[0] || preg_match('/[=\x20\r\n]/', $rawName)) {
                throw new \InvalidArgumentException(sprintf(
                    'Multiple-response set name "%s" must begin with "$" and cannot contain spaces, equals signs, or line breaks.',
                    $name,
                ));
            }

            $type = $set['type'];
            if (!in_array($type, $this->allowedTypes(), true)) {
                throw new \InvalidArgumentException(sprintf(
                    'Multiple-response set "%s" type "%s" is invalid for subtype %d; expected %s.',
                    $name,
                    $type,
                    $this->subtype,
                    implode(' or ', $this->allowedTypes()),
                ));
            }

            $payload .= $rawName . '=' . $type;
            if ('E' === $type) {
                $labelSource = $set['labelSource'];
                if (1 !== $labelSource && 11 !== $labelSource) {
                    throw new \InvalidArgumentException(sprintf(
                        'Multiple-response set "%s" labelSource must be 1 or 11.',
                        $name,
                    ));
                }

                $payload .= ' ' . $labelSource . ' ';
            } elseif (null !== $set['labelSource']) {
                throw new \InvalidArgumentException(sprintf(
                    'Multiple-response set "%s" labelSource is only valid for type E.',
                    $name,
                ));
            }

            if ('D' === $type || 'E' === $type) {
                if (null === $set['countedValue']) {
                    throw new \InvalidArgumentException(sprintf(
                        'Multiple-response set "%s" requires a countedValue.',
                        $name,
                    ));
                }

                $rawCountedValue = $this->encode($set['countedValue'], $buffer);
                if ('' === $rawCountedValue) {
                    throw new \InvalidArgumentException(sprintf(
                        'Multiple-response set "%s" countedValue cannot be empty.',
                        $name,
                    ));
                }

                $payload .= strlen($rawCountedValue) . ' ' . $rawCountedValue;
            } elseif (null !== $set['countedValue']) {
                throw new \InvalidArgumentException(sprintf(
                    'Multiple-response set "%s" countedValue is only valid for type D or E.',
                    $name,
                ));
            }

            $rawLabel = $this->encode($set['label'], $buffer);
            $payload .= ' ' . strlen($rawLabel) . ' ' . $rawLabel . ' ';

            $rawVariables = [];
            foreach ($set['variables'] as $variable) {
                $rawVariable = $this->encode($variable, $buffer);
                if ('' === $rawVariable || preg_match('/\s/', $rawVariable) || $rawVariable !== strtolower($rawVariable)) {
                    throw new \InvalidArgumentException(sprintf(
                        'Multiple-response set "%s" variable name "%s" must be non-empty, lowercase, and contain no whitespace.',
                        $name,
                        $variable,
                    ));
                }

                $rawVariables[] = $rawVariable;
            }

            $payload .= implode(' ', $rawVariables) . "\n";
        }

        $this->dataSize = 1;
        $this->dataCount = strlen($payload);
        $buffer->writeInt(self::TYPE);
        $buffer->writeInt($this->subtype);
        $buffer->writeInt($this->dataSize);
        $buffer->writeInt($this->dataCount);
        $buffer->write($payload);
    }

    /** @return list<'C'|'D'|'E'> */
    private function allowedTypes(): array
    {
        return self::COUNTED_VALUES_SUBTYPE === $this->subtype ? ['E'] : ['C', 'D'];
    }

    private function assertSubtype(): void
    {
        if (self::SUBTYPE !== $this->subtype && self::COUNTED_VALUES_SUBTYPE !== $this->subtype) {
            throw new \InvalidArgumentException(sprintf(
                'Multiple-response sets subtype must be %d or %d, got %d.',
                self::SUBTYPE,
                self::COUNTED_VALUES_SUBTYPE,
                $this->subtype,
            ));
        }
    }

    private function readPayload(Buffer $buffer): string
    {
        if (1 !== $this->dataSize) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed multiple-response sets record: element size must be 1, got %d.',
                $this->dataSize,
            ));
        }

        if ($this->dataCount < 0) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed multiple-response sets record: byte count cannot be negative, got %d.',
                $this->dataCount,
            ));
        }

        if (0 === $this->dataCount) {
            return '';
        }

        $payload = $buffer->read($this->dataCount);
        if (false === $payload || strlen($payload) !== $this->dataCount) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed multiple-response sets record: expected %d payload bytes, got %d.',
                $this->dataCount,
                false === $payload ? 0 : strlen($payload),
            ));
        }

        return $payload;
    }

    private function readByte(string $payload, int &$offset, int $recordOffset, string $description): string
    {
        if (!isset($payload[$offset])) {
            throw $this->malformed($recordOffset, sprintf('missing %s', $description));
        }

        return $payload[$offset++];
    }

    private function expectSpace(string $payload, int &$offset, int $recordOffset, string $description): void
    {
        if (!isset($payload[$offset]) || ' ' !== $payload[$offset]) {
            throw $this->malformed($recordOffset, sprintf('expected a space %s', $description));
        }

        ++$offset;
    }

    private function readDecimal(string $payload, int &$offset, int $recordOffset, string $description): int
    {
        $start = $offset;
        $length = strlen($payload);
        while ($offset < $length && $payload[$offset] >= '0' && $payload[$offset] <= '9') {
            ++$offset;
        }

        if ($start === $offset) {
            throw $this->malformed($recordOffset, sprintf('missing decimal %s', $description));
        }

        $digits = substr($payload, $start, $offset - $start);
        if (strlen($digits) > strlen((string) PHP_INT_MAX)
            || (strlen($digits) === strlen((string) PHP_INT_MAX) && strcmp($digits, (string) PHP_INT_MAX) > 0)) {
            throw $this->malformed($recordOffset, sprintf('%s exceeds the supported integer range', $description));
        }

        return (int) $digits;
    }

    private function readSizedValue(
        string $payload,
        int &$offset,
        int $valueLength,
        int $recordOffset,
        string $description,
    ): string {
        if ($valueLength > strlen($payload) - $offset) {
            throw $this->malformed($recordOffset, sprintf(
                '%s declares %d bytes but only %d remain',
                $description,
                $valueLength,
                strlen($payload) - $offset,
            ));
        }

        $value = substr($payload, $offset, $valueLength);
        $offset += $valueLength;

        return $value;
    }

    private function malformed(int $offset, string $reason): \UnexpectedValueException
    {
        return new \UnexpectedValueException(sprintf(
            'Malformed multiple-response sets record near byte %d: %s.',
            $offset,
            $reason,
        ));
    }

    private function encode(string $value, Buffer $buffer): string
    {
        $charset = $buffer->charset ?? mb_internal_encoding();
        if (0 === strcasecmp($charset, mb_internal_encoding())) {
            return $value;
        }

        return mb_convert_encoding($value, $charset, mb_internal_encoding());
    }

    private function decode(string $value, Buffer $buffer): string
    {
        $charset = $buffer->charset ?? mb_internal_encoding();
        if (0 === strcasecmp($charset, mb_internal_encoding())) {
            return $value;
        }

        return mb_convert_encoding($value, mb_internal_encoding(), $charset);
    }
}
