<?php

namespace SPSS\Sav\Record;

use SPSS\Buffer;
use SPSS\Sav\Record;

class InfoCollection
{
    /**
     * @var list<class-string<Record\Info>>
     */
    public static $classMap = [
        Record\Info\MachineInteger::class,
        Record\Info\MachineFloatingPoint::class,
        Record\Info\VariableDisplayParam::class,
        Record\Info\VariableSets::class,
        Record\Info\MultipleResponseSets::class,
        Record\Info\LongVariableNames::class,
        Record\Info\VeryLongString::class,
        Record\Info\ExtendedNumberOfCases::class,
        Record\Info\DataFileAttributes::class,
        Record\Info\VariableAttributes::class,
        Record\Info\CharacterEncoding::class,
        Record\Info\LongStringValueLabels::class,
        Record\Info\LongStringMissingValues::class,
    ];

    /**
     * Legacy subtype-keyed view. Repeated subtypes replace the previous value.
     *
     * @var array<int, Record\Info>
     */
    public $data = [];

    /** @var list<Record\Info> Physical records in file order, including duplicate subtypes. */
    public $records = [];

    /** @var array<int, Record\Info> Semantically merged subtype view. */
    public $mergedData = [];

    /**
     * @return class-string<Record\Info>
     */
    protected static function getClassBySubtype(int $subtype): string
    {
        if (Record\Info\MultipleResponseSets::COUNTED_VALUES_SUBTYPE === $subtype) {
            return Record\Info\MultipleResponseSets::class;
        }

        foreach (self::$classMap as $class) {
            if ($subtype === $class::SUBTYPE && is_subclass_of($class, Record\Info::class)) {
                return $class;
            }
        }

        return Record\Info\Unknown::class;
    }

    /**
     * @return array<int, Record\Info>
     */
    public function fill(Buffer $buffer): array
    {
        $subtype = $buffer->readInt();
        $class = self::getClassBySubtype($subtype);
        $initialData = Record\Info\MultipleResponseSets::class === $class ? ['subtype' => $subtype] : [];
        $record = $class::fill($buffer, $initialData);
        $this->records[] = $record;
        $this->data[$subtype] = $record;

        if ($record instanceof Record\Info\VariableAttributes) {
            $mergedRecord = $this->mergedData[$subtype] ?? null;
            if ($mergedRecord instanceof Record\Info\VariableAttributes) {
                $mergedRecord = clone $mergedRecord;
                $mergedRecord->merge($record);
            } else {
                $mergedRecord = clone $record;
            }

            $this->mergedData[$subtype] = $mergedRecord;
        } else {
            $this->mergedData[$subtype] = $record;
        }

        return $this->data;
    }
}
