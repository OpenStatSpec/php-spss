<?php

declare(strict_types=1);

namespace SPSS\Sav\Record\Info;

/** @internal Shared codec for subtype 17 and 18 attribute sets. */
final class AttributeSetCodec
{
    /**
     * @return array<string, non-empty-list<string>>
     */
    public static function decode(string $payload, ?string $charset = null): array
    {
        $offset = 0;
        $attributes = self::decodeFrom($payload, $offset, $charset);
        if ($offset !== strlen($payload)) {
            throw self::malformed($offset, 'unexpected bytes after the attribute set');
        }

        return $attributes;
    }

    /**
     * Decodes one attribute set, stopping before an optional outer-record delimiter.
     *
     * @return array<string, non-empty-list<string>>
     */
    public static function decodeFrom(
        string $payload,
        int &$offset,
        ?string $charset = null,
        ?string $terminator = null,
    ): array {
        if (null !== $terminator && 1 !== strlen($terminator)) {
            throw new \InvalidArgumentException('Attribute set terminator must be exactly one byte.');
        }

        $attributes = [];
        $length = strlen($payload);
        while ($offset < $length && (null === $terminator || $payload[$offset] !== $terminator)) {
            $nameOffset = $offset;
            $openParenthesis = strpos($payload, '(', $offset);
            if (false === $openParenthesis) {
                throw self::malformed($nameOffset, 'attribute name is not followed by an opening parenthesis');
            }

            if (null !== $terminator) {
                $nextTerminator = strpos($payload, $terminator, $offset);
                if (false !== $nextTerminator && $nextTerminator < $openParenthesis) {
                    throw self::malformed($nameOffset, 'attribute name is not followed by an opening parenthesis');
                }
            }

            $rawName = substr($payload, $offset, $openParenthesis - $offset);
            self::validateRawIdentifier($rawName, $nameOffset, 'attribute name');
            $name = self::decodeText($rawName, $charset);
            $offset = $openParenthesis + 1;

            $values = [];
            while (true) {
                if (!isset($payload[$offset]) || "'" !== $payload[$offset]) {
                    throw self::malformed($offset, sprintf(
                        'attribute "%s" must contain at least one single-quoted value',
                        $name,
                    ));
                }

                $valueOffset = ++$offset;
                $lineFeed = strpos($payload, "\n", $offset);
                if (false === $lineFeed) {
                    throw self::malformed($valueOffset, sprintf(
                        'attribute "%s" value is not terminated by a quote and line feed',
                        $name,
                    ));
                }

                if ($lineFeed === $valueOffset || "'" !== $payload[$lineFeed - 1]) {
                    throw self::malformed($valueOffset, sprintf(
                        'attribute "%s" value must end with a single quote before its line feed',
                        $name,
                    ));
                }

                $rawValue = substr($payload, $valueOffset, $lineFeed - $valueOffset - 1);
                $values[] = self::decodeText($rawValue, $charset);
                $offset = $lineFeed + 1;

                if (isset($payload[$offset]) && "'" === $payload[$offset]) {
                    continue;
                }

                if (!isset($payload[$offset]) || ')' !== $payload[$offset]) {
                    throw self::malformed($offset, sprintf(
                        'attribute "%s" values are not followed by a closing parenthesis',
                        $name,
                    ));
                }

                ++$offset;
                break;
            }

            if (isset($attributes[$name])) {
                array_push($attributes[$name], ...$values);
            } else {
                $attributes[$name] = $values;
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, list<string>> $attributes
     */
    public static function encode(array $attributes, ?string $charset = null): string
    {
        if ([] === $attributes) {
            throw new \InvalidArgumentException('Attribute set must contain at least one attribute.');
        }

        $payload = '';
        foreach ($attributes as $name => $values) {
            $rawName = self::encodeText($name, $charset);
            self::validateRawIdentifier($rawName, strlen($payload), 'attribute name', \InvalidArgumentException::class);
            if ([] === $values) {
                throw new \InvalidArgumentException(sprintf(
                    'Attribute "%s" must contain at least one value.',
                    $name,
                ));
            }

            $payload .= $rawName . '(';
            foreach ($values as $value) {
                $rawValue = self::encodeText($value, $charset);
                if (str_contains($rawValue, "\n")) {
                    throw new \InvalidArgumentException(sprintf(
                        'Attribute "%s" values cannot contain line feeds.',
                        $name,
                    ));
                }

                $payload .= "'" . $rawValue . "'\n";
            }

            $payload .= ')';
        }

        return $payload;
    }

    public static function encodeText(string $value, ?string $charset = null): string
    {
        $charset ??= mb_internal_encoding();
        if (0 === strcasecmp($charset, mb_internal_encoding())) {
            return $value;
        }

        return mb_convert_encoding($value, $charset, mb_internal_encoding());
    }

    public static function decodeText(string $value, ?string $charset = null): string
    {
        $charset ??= mb_internal_encoding();
        if (0 === strcasecmp($charset, mb_internal_encoding())) {
            return $value;
        }

        return mb_convert_encoding($value, mb_internal_encoding(), $charset);
    }

    /**
     * @param class-string<\InvalidArgumentException|\UnexpectedValueException> $exceptionClass
     */
    private static function validateRawIdentifier(
        string $identifier,
        int $offset,
        string $description,
        string $exceptionClass = \UnexpectedValueException::class,
    ): void {
        if ('' !== $identifier && !preg_match('/[\s()\'\/:=]/', $identifier)) {
            return;
        }

        $message = sprintf(
            'Malformed attribute set near byte %d: %s must be non-empty and cannot contain whitespace or attribute delimiters.',
            $offset,
            $description,
        );
        throw new $exceptionClass($message);
    }

    private static function malformed(int $offset, string $reason): \UnexpectedValueException
    {
        return new \UnexpectedValueException(sprintf(
            'Malformed attribute set near byte %d: %s.',
            $offset,
            $reason,
        ));
    }
}
