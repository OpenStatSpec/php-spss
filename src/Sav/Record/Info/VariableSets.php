<?php

declare(strict_types=1);

namespace SPSS\Sav\Record\Info;

use SPSS\Buffer;
use SPSS\Sav\Record\Info;

class VariableSets extends Info
{
    public const SUBTYPE = 5;

    /** @var array<array-key, mixed> */
    public $data = [];

    #[\Override]
    public function read(Buffer $buffer): void
    {
        parent::read($buffer);
        $payload = $this->readPayload($buffer);
        $this->data = [];

        if ('' === $payload) {
            return;
        }

        if (!str_ends_with($payload, "\n")) {
            throw new \UnexpectedValueException('Malformed variable sets record: the final set must end with a line feed.');
        }

        $lines = explode("\n", substr($payload, 0, -1));
        foreach ($lines as $lineNumber => $line) {
            if (str_ends_with($line, "\r")) {
                $line = substr($line, 0, -1);
            }

            if (!preg_match('/\A([^=\x20\r\n]+)= (.*)\z/sD', $line, $matches)) {
                throw new \UnexpectedValueException(sprintf(
                    'Malformed variable sets record at line %d: expected "name= member ...".',
                    $lineNumber + 1,
                ));
            }

            $name = $this->decode($matches[1], $buffer);
            if (isset($this->data[$name])) {
                throw new \UnexpectedValueException(sprintf(
                    'Malformed variable sets record at line %d: duplicate set name "%s".',
                    $lineNumber + 1,
                    $name,
                ));
            }

            $members = [];
            if ('' !== $matches[2]) {
                if (str_contains($matches[2], '  ') || str_starts_with($matches[2], ' ') || str_ends_with($matches[2], ' ')) {
                    throw new \UnexpectedValueException(sprintf(
                        'Malformed variable sets record at line %d: members must be separated by one space.',
                        $lineNumber + 1,
                    ));
                }

                foreach (explode(' ', $matches[2]) as $member) {
                    $members[] = $this->decode($member, $buffer);
                }
            }

            $this->data[$name] = $members;
        }
    }

    #[\Override]
    public function write(Buffer $buffer): void
    {
        $payload = '';
        /** @var array<string, list<string>> $sets */
        $sets = $this->data;
        foreach ($sets as $name => $members) {
            $encodedName = $this->encodeIdentifier($name, $buffer, 'set name');
            $encodedMembers = [];
            foreach ($members as $member) {
                $encodedMembers[] = $this->encodeIdentifier($member, $buffer, 'variable name');
            }

            $payload .= $encodedName . '= ' . implode(' ', $encodedMembers) . "\n";
        }

        $this->dataSize = 1;
        $this->dataCount = strlen($payload);
        parent::write($buffer);
        $buffer->write($payload);
    }

    private function readPayload(Buffer $buffer): string
    {
        if (1 !== $this->dataSize) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed variable sets record: element size must be 1, got %d.',
                $this->dataSize,
            ));
        }

        if ($this->dataCount < 0) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed variable sets record: byte count cannot be negative, got %d.',
                $this->dataCount,
            ));
        }

        if (0 === $this->dataCount) {
            return '';
        }

        $payload = $buffer->read($this->dataCount);
        if (false === $payload || strlen($payload) !== $this->dataCount) {
            throw new \UnexpectedValueException(sprintf(
                'Malformed variable sets record: expected %d payload bytes, got %d.',
                $this->dataCount,
                false === $payload ? 0 : strlen($payload),
            ));
        }

        return $payload;
    }

    private function encodeIdentifier(string $value, Buffer $buffer, string $description): string
    {
        $encoded = $this->encode($value, $buffer);
        if ('' === $encoded || preg_match('/[=\x20\r\n]/', $encoded)) {
            throw new \InvalidArgumentException(sprintf(
                'Variable sets %s "%s" must be non-empty and cannot contain spaces, equals signs, or line breaks.',
                $description,
                $value,
            ));
        }

        return $encoded;
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
