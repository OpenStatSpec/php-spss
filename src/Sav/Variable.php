<?php

declare(strict_types=1);

namespace SPSS\Sav;

/**
 * @phpstan-type VariableData array{
 *     name?: string|null,
 *     type?: VariableType|null,
 *     width?: int,
 *     decimals?: int,
 *     format?: int,
 *     printFormat?: VariableFormat|null,
 *     writeFormat?: VariableFormat|null,
 *     columns?: int|null,
 *     alignment?: int|null,
 *     measure?: int|null,
 *     role?: int|null,
 *     label?: string|null,
 *     values?: array<array-key, string>,
 *     valueLabelSet?: ValueLabelSet|null,
 *     missing?: list<int|float|string>,
 *     missingValues?: MissingValues|null,
 *     attributes?: array<string, int|float|string|array<array-key, int|float|string>>,
 *     data?: array<int, int|float|string|null>
 * }
 */
class Variable
{
    // const TYPE_NUMERIC = 1;
    // const TYPE_STRING = 2;

    public const FORMAT_TYPE_A        = 1;

    public const FORMAT_TYPE_AHEX     = 2;

    public const FORMAT_TYPE_COMMA    = 3;

    public const FORMAT_TYPE_DOLLAR   = 4;

    public const FORMAT_TYPE_F        = 5;

    public const FORMAT_TYPE_IB       = 6;

    public const FORMAT_TYPE_PIBHEX   = 7;

    public const FORMAT_TYPE_P        = 8;

    public const FORMAT_TYPE_PIB      = 9;

    public const FORMAT_TYPE_PK       = 10;

    public const FORMAT_TYPE_RB       = 11;

    public const FORMAT_TYPE_RBHEX    = 12;

    public const FORMAT_TYPE_Z        = 15;

    public const FORMAT_TYPE_N        = 16;

    public const FORMAT_TYPE_E        = 17;

    public const FORMAT_TYPE_DATE     = 20;

    public const FORMAT_TYPE_TIME     = 21;

    public const FORMAT_TYPE_DATETIME = 22;

    public const FORMAT_TYPE_ADATE    = 23;

    public const FORMAT_TYPE_JDATE    = 24;

    public const FORMAT_TYPE_DTIME    = 25;

    public const FORMAT_TYPE_WKDAY    = 26;

    public const FORMAT_TYPE_MONTH    = 27;

    public const FORMAT_TYPE_MOYR     = 28;

    public const FORMAT_TYPE_QYR      = 29;

    public const FORMAT_TYPE_WKYR     = 30;

    public const FORMAT_TYPE_PCT      = 31;

    public const FORMAT_TYPE_DOT      = 32;

    public const FORMAT_TYPE_CCA      = 33;

    public const FORMAT_TYPE_CCB      = 34;

    public const FORMAT_TYPE_CCC      = 35;

    public const FORMAT_TYPE_CCD      = 36;

    public const FORMAT_TYPE_CCE      = 37;

    public const FORMAT_TYPE_EDATE    = 38;

    public const FORMAT_TYPE_SDATE    = 39;

    public const ALIGN_LEFT   = 0;

    public const ALIGN_RIGHT  = 1;

    public const ALIGN_CENTER = 2;

    public const MEASURE_UNKNOWN = 0;

    public const MEASURE_NOMINAL = 1;

    public const MEASURE_ORDINAL = 2;

    public const MEASURE_SCALE   = 3;

    public const ROLE_INPUT     = 0;

    public const ROLE_TARGET    = 1;

    public const ROLE_BOTH      = 2;

    public const ROLE_NONE      = 3;

    public const ROLE_PARTITION = 4;

    public const ROLE_SPLIT     = 5;

    /** @var string|null */
    public $name;

    public ?VariableType $type = null;

    public ?VariableFormat $printFormat = null;

    public ?VariableFormat $writeFormat = null;

    /** @var int */
    public $width    = 8;

    /** @var int */
    public $decimals = 0;

    /** @var int */
    public $format   = 0;

    /** @var int|null */
    public $columns;

    /** @var int|null */
    public $alignment;

    /** @var int|null */
    public $measure;

    /** @var int|null */
    public $role;

    /** @var string|null */
    public $label;

    /** @var array<array-key, string> */
    public $values  = [];

    public ?ValueLabelSet $valueLabelSet = null;

    /** @var list<int|float|string> */
    public $missing = [];

    public ?MissingValues $missingValues = null;

    /**
     * @var array<string, int|float|string|array<array-key, int|float|string>>
     */
    public $attributes = [
        // '$@Role' => self::ROLE_BOTH
    ];

    /**
     * @var array<int, int|float|string|null>
     */
    public $data = [];

    /**
     * Variable constructor.
     *
     * @param VariableData $data
     */
    public function __construct($data = [])
    {
        foreach ($data as $key => $value) {
            switch ($key) {
                case 'name':
                    $this->name = $value;
                    break;
                case 'type':
                    $this->type = $value;
                    break;
                case 'width':
                    $this->width = $value;
                    break;
                case 'decimals':
                    $this->decimals = $value;
                    break;
                case 'format':
                    $this->format = $value;
                    break;
                case 'printFormat':
                    $this->printFormat = $value;
                    break;
                case 'writeFormat':
                    $this->writeFormat = $value;
                    break;
                case 'columns':
                    $this->columns = $value;
                    break;
                case 'alignment':
                    $this->alignment = $value;
                    break;
                case 'measure':
                    $this->measure = $value;
                    break;
                case 'role':
                    $this->role = $value;
                    break;
                case 'label':
                    $this->label = $value;
                    break;
                case 'values':
                    $this->values = $value;
                    break;
                case 'valueLabelSet':
                    $this->valueLabelSet = $value;
                    break;
                case 'missing':
                    $this->missing = $value;
                    break;
                case 'missingValues':
                    $this->missingValues = $value;
                    break;
                case 'attributes':
                    $this->attributes = $value;
                    break;
                case 'data':
                    $this->data = $value;
                    break;
                default:
                    throw new \InvalidArgumentException(sprintf('Unknown %s property "%s".', self::class, $key));
            }
        }
    }

    /**
     * @param int $format
     */
    public static function isNumberFormat($format): bool
    {
        return 0 !== $format && !\in_array($format, [self::FORMAT_TYPE_A, self::FORMAT_TYPE_AHEX], true);
    }

    public static function isStringFormat(int $format): bool
    {
        return \in_array($format, [self::FORMAT_TYPE_A, self::FORMAT_TYPE_AHEX], true);
    }

    /**
     * This method returns the print / write format code of a variable.
     * The returned value is a tuple consisting of the format abbreviation
     * (string <= 8 chars) and a meaning (long string).
     * Non-existent codes have a (null, null) tuple returned.
     *
     * @param int $format
     *
     * @return array{string|null, string|null}
     */
    public static function getFormatInfo($format): array
    {
        return match ($format) {
            0 => ['', 'Continuation of string variable'],
            self::FORMAT_TYPE_A => ['A', 'Alphanumeric'],
            self::FORMAT_TYPE_AHEX => ['AHEX', 'alphanumeric hexadecimal'],
            self::FORMAT_TYPE_COMMA => ['COMMA', 'F format with commas'],
            self::FORMAT_TYPE_DOLLAR => ['DOLLAR', 'Commas and floating point dollar sign'],
            self::FORMAT_TYPE_F => ['F', 'F (default numeric) format'],
            self::FORMAT_TYPE_IB => ['IB', 'Integer binary'],
            self::FORMAT_TYPE_PIBHEX => ['PIBHEX', 'Positive binary integer - hexadecimal'],
            self::FORMAT_TYPE_P => ['P', 'Packed decimal'],
            self::FORMAT_TYPE_PIB => ['PIB', 'Positive integer binary (Unsigned)'],
            self::FORMAT_TYPE_PK => ['PK', 'Positive packed decimal (Unsigned)'],
            self::FORMAT_TYPE_RB => ['RB', 'Floating point binary'],
            self::FORMAT_TYPE_RBHEX => ['RBHEX', 'Floating point binary - hexadecimal'],
            self::FORMAT_TYPE_Z => ['Z', 'Zoned decimal'],
            self::FORMAT_TYPE_N => ['N', 'N format - unsigned with leading zeros'],
            self::FORMAT_TYPE_E => ['E', 'E format - with explicit power of ten'],
            self::FORMAT_TYPE_DATE => ['DATE', 'Date format dd-mmm-yyyy'],
            self::FORMAT_TYPE_TIME => ['TIME', 'Time format hh:mm:ss.s'],
            self::FORMAT_TYPE_DATETIME => ['DATETIME', 'Date and time'],
            self::FORMAT_TYPE_ADATE => ['ADATE', 'Date in mm/dd/yyyy form'],
            self::FORMAT_TYPE_JDATE => ['JDATE', 'Julian date - yyyyddd'],
            self::FORMAT_TYPE_DTIME => ['DTIME', 'Date-time dd hh:mm:ss.s'],
            self::FORMAT_TYPE_WKDAY => ['WKDAY', 'Day of the week'],
            self::FORMAT_TYPE_MONTH => ['MONTH', 'Month'],
            self::FORMAT_TYPE_MOYR => ['MOYR', 'mmm yyyy'],
            self::FORMAT_TYPE_QYR => ['QYR', 'q Q yyyy'],
            self::FORMAT_TYPE_WKYR => ['WKYR', 'ww WK yyyy'],
            self::FORMAT_TYPE_PCT => ['PCT', 'Percent - F followed by "%"'],
            self::FORMAT_TYPE_DOT => ['DOT', 'Like COMMA, switching dot for comma'],
            self::FORMAT_TYPE_CCA => ['CCA', 'User-programmable currency format (1)'],
            self::FORMAT_TYPE_CCB => ['CCB', 'User-programmable currency format (2)'],
            self::FORMAT_TYPE_CCC => ['CCC', 'User-programmable currency format (3)'],
            self::FORMAT_TYPE_CCD => ['CCD', 'User-programmable currency format (4)'],
            self::FORMAT_TYPE_CCE => ['CCE', 'User-programmable currency format (5)'],
            self::FORMAT_TYPE_EDATE => ['EDATE', 'Date in dd.mm.yyyy style'],
            self::FORMAT_TYPE_SDATE => ['SDATE', 'Date in yyyy/mm/dd style'],
            default => [null, null],
        };
    }

    /**
     * @param int $alignment
     */
    public static function alignmentToString($alignment): string
    {
        return match ($alignment) {
            self::ALIGN_LEFT => 'Left',
            self::ALIGN_RIGHT => 'Right',
            self::ALIGN_CENTER => 'Center',
            default => 'Invalid',
        };
    }

    /**
     * @return int
     */
    public function getMeasure()
    {
        if (null !== $this->measure) {
            return $this->measure;
        }

        return 0 === $this->width ? self::MEASURE_UNKNOWN : self::MEASURE_NOMINAL;
    }

    /**
     * @return int
     */
    public function getAlignment()
    {
        if (null !== $this->alignment) {
            return $this->alignment;
        }

        return 0 === $this->width ? self::ALIGN_RIGHT : self::ALIGN_LEFT;
    }

    /**
     * @return int
     */
    public function getColumns()
    {
        if (null !== $this->columns) {
            return $this->columns;
        }

        return 8;
    }
}
