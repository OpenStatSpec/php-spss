<?php

declare(strict_types=1);

namespace SPSS\Sav;

use SPSS\Buffer;

/** @phpstan-consistent-constructor */
abstract class Record implements RecordInterface
{
    /**
     * Record constructor.
     *
     * @param array<array-key, mixed> $data
     */
    public function __construct(array $data = [])
    {
        foreach ($data as $key => $value) {
            $property = (string) $key;
            if (!property_exists($this, $property)) {
                throw new \InvalidArgumentException(sprintf('Unknown %s property "%s".', static::class, $key));
            }

            new \ReflectionProperty($this, $property)->setValue($this, $value);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return static
     */
    public static function fill(Buffer $buffer, array $data = [])
    {
        $record = new static($data);
        $record->read($buffer);

        return $record;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return static
     */
    public static function create(array $data = [])
    {
        return new static($data);
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return [];
    }
}
