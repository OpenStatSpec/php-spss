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
     * @var array<int, Record\Info>
     */
    public $data = [];

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
        $subtype              = $buffer->readInt();
        $class                = self::getClassBySubtype($subtype);
        $initialData = Record\Info\MultipleResponseSets::class === $class ? ['subtype' => $subtype] : [];
        $this->data[$subtype] = $class::fill($buffer, $initialData);

        return $this->data;
    }
}
