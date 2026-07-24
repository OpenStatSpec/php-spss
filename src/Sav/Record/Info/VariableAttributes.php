<?php

declare(strict_types=1);

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class VariableAttributes extends Info
{
    public const SUBTYPE = 18;

    /** @var array<array-key, mixed> */
    public $data = [];

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $payload = $this->readPayload($buffer);
        $this->data = [];
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $variableOffset = $offset;
            $colon = strpos($payload, ':', $offset);
            $slash = strpos($payload, '/', $offset);
            if (false === $colon || (false !== $slash && $slash < $colon)) {
                throw $this->malformed($variableOffset, 'variable name is not followed by a colon');
            }

            $rawVariable = substr($payload, $offset, $colon - $offset);
            $this->validateRawVariableName($rawVariable, $variableOffset);
            $variable = AttributeSetCodec::decodeText($rawVariable, $buffer->charset);
            $offset = $colon + 1;
            $attributes = AttributeSetCodec::decodeFrom($payload, $offset, $buffer->charset, '/');
            if ([] === $attributes) {
                throw $this->malformed($offset, sprintf(
                    'variable "%s" must contain at least one attribute',
                    $variable,
                ));
            }

            /** @var array<string, array<string, non-empty-list<string>>> $current */
            $current = $this->data;
            $this->data = self::mergeData($current, [$variable => $attributes]);

            if ($offset < $length) {
                ++$offset;
                if ($offset >= $length) {
                    throw $this->malformed($offset - 1, 'record cannot end with a variable-set delimiter');
                }
            }
        }
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        /** @var array<string, array<string, list<string>>> $variables */
        $variables = $this->data;
        if ([] === $variables) {
            throw new \InvalidArgumentException('Variable attributes record must contain at least one variable.');
        }

        $sets = [];
        foreach ($variables as $variable => $attributes) {
            $rawVariable = AttributeSetCodec::encodeText($variable, $buffer->charset);
            $this->validateRawVariableName($rawVariable, 0, \InvalidArgumentException::class);
            $sets[] = $rawVariable . ':' . AttributeSetCodec::encode($attributes, $buffer->charset);
        }

        $payload = implode('/', $sets);
        $this->dataSize = 1;
        $this->dataCount = strlen($payload);
        parent::write($buffer);
        $buffer->write($payload);
    }

    public function merge(self $other): void
    {
        /** @var array<string, array<string, non-empty-list<string>>> $current */
        $current = $this->data;
        /** @var array<string, array<string, non-empty-list<string>>> $additional */
        $additional = $other->data;
        $this->data = self::mergeData($current, $additional);
    }

    /**
     * @param  array<string, array<string, non-empty-list<string>>> $current
     * @param  array<string, array<string, non-empty-list<string>>> $additional
     * @return array<string, array<string, non-empty-list<string>>>
     */
    public static function mergeData(array $current, array $additional): array
    {
        foreach ($additional as $variable => $attributes) {
            if (!isset($current[$variable])) {
                $current[$variable] = $attributes;
                continue;
            }

            foreach ($attributes as $name => $values) {
                if (isset($current[$variable][$name])) {
                    array_push($current[$variable][$name], ...$values);
                } else {
                    $current[$variable][$name] = $values;
                }
            }
        }

        return $current;
    }

    private function readPayload(Buffer $buffer): string
    {
        if (1 !== $this->dataSize) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed variable attributes record: element size must be 1, got %d.',
                $this->dataSize,
            ));
        }

        if ($this->dataCount <= 0) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed variable attributes record: byte count must be positive, got %d.',
                $this->dataCount,
            ));
        }

        $payload = $buffer->read($this->dataCount);
        if (false === $payload || strlen($payload) !== $this->dataCount) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed variable attributes record: expected %d payload bytes, got %d.',
                $this->dataCount,
                false === $payload ? 0 : strlen($payload),
            ));
        }

        return $payload;
    }

    /**
     * @param class-string<\InvalidArgumentException|\UnexpectedValueException> $exceptionClass
     */
    private function validateRawVariableName(
        string $variable,
        int $offset,
        string $exceptionClass = \UnexpectedValueException::class,
    ): void {
        if ('' !== $variable && !preg_match('/[\s()\'\/:=]/', $variable)) {
            return;
        }

        throw new $exceptionClass(sprintf(
            'Malformed variable attributes record near byte %d: variable name must be non-empty and cannot contain whitespace or record delimiters.',
            $offset,
        ));
    }

    private function malformed(int $offset, string $reason): \UnexpectedValueException
    {
        return new \UnexpectedValueException(sprintf(
            'Malformed variable attributes record near byte %d: %s.',
            $offset,
            $reason,
        ));
    }
}
