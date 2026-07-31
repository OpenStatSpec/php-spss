<?php

namespace SPSS;

use SPSS\Sav\Record\Variable;
use SPSS\Sav\Variable as SavVariable;

class Utils
{
    private const array SPSS_MONTHS = [
        'jan' => 1,
        'feb' => 2,
        'mar' => 3,
        'apr' => 4,
        'may' => 5,
        'jun' => 6,
        'jul' => 7,
        'aug' => 8,
        'sep' => 9,
        'oct' => 10,
        'nov' => 11,
        'dec' => 12,
    ];

    /**
     * SPSS represents a date as the number of seconds since the epoch, midnight, Oct. 14, 1582.
     *
     * @param $timestamp
     */
    public static function formatDate(int $timestamp, string $format = 'Y M d'): string
    {
        return date($format, strtotime('1582-10-14 00:00:00') + $timestamp);
    }

    /**
     * Parses supported human-readable SPSS DATE, TIME, and DATETIME values.
     *
     * Supported forms are DATE `dd-Mmm-yyyy`, TIME `h+:mm[:ss[.fraction]]`,
     * and DATETIME `dd-Mmm-yyyy HH:mm[:ss[.fraction]]`. Month names are the
     * English three-letter abbreviations (case-insensitive). TIME is an SPSS
     * duration and may exceed 23 hours; DATETIME uses a 00-23 hour clock.
     *
     * @throws \InvalidArgumentException If the format code or value is invalid.
     */
    public static function parseSpssDateTime(string $value, int $format): float
    {
        return match ($format) {
            SavVariable::FORMAT_TYPE_DATE => (float) (self::parseSpssDate($value) * 86400),
            SavVariable::FORMAT_TYPE_TIME => self::parseSpssTime($value, false),
            SavVariable::FORMAT_TYPE_DATETIME => self::parseSpssDateTimeValue($value),
            default => throw new \InvalidArgumentException(sprintf(
                'Unsupported SPSS date/time format code %d.',
                $format,
            )),
        };
    }

    private static function parseSpssDateTimeValue(string $value): float
    {
        if (1 !== preg_match('/^(\d{2}-[A-Za-z]{3}-\d{4}) (.+)$/D', $value, $matches)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid SPSS DATETIME value "%s"; expected dd-Mmm-yyyy HH:mm[:ss[.fraction]].',
                $value,
            ));
        }

        return self::parseSpssDate($matches[1]) * 86400
            + self::parseSpssTime($matches[2], true);
    }

    private static function parseSpssDate(string $value): int
    {
        if (1 !== preg_match('/^(\d{2})-([A-Za-z]{3})-(\d{4})$/D', $value, $matches)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid SPSS DATE value "%s"; expected dd-Mmm-yyyy.',
                $value,
            ));
        }

        $day = (int) $matches[1];
        $month = self::SPSS_MONTHS[strtolower($matches[2])] ?? null;
        $year = (int) $matches[3];
        if (null === $month || !checkdate($month, $day, $year)) {
            throw new \InvalidArgumentException(sprintf('Invalid SPSS calendar date "%s".', $value));
        }

        return self::gregorianDayNumber($year, $month, $day)
            - self::gregorianDayNumber(1582, 10, 14);
    }

    private static function parseSpssTime(string $value, bool $timeOfDay): float
    {
        if (1 !== preg_match('/^(\d+):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/D', $value, $matches)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid SPSS %s value "%s"; expected %s.',
                $timeOfDay ? 'DATETIME time' : 'TIME',
                $value,
                $timeOfDay ? 'HH:mm[:ss[.fraction]]' : 'h+:mm[:ss[.fraction]]',
            ));
        }

        $hours = (float) $matches[1];
        $minutes = (int) $matches[2];
        $seconds = isset($matches[3]) ? (int) $matches[3] : 0;
        if (!is_finite($hours)
            || $minutes > 59
            || $seconds > 59
            || ($timeOfDay && (2 !== \strlen($matches[1]) || $hours > 23))
        ) {
            throw new \InvalidArgumentException(sprintf('Invalid SPSS time value "%s".', $value));
        }

        $fraction = isset($matches[4]) ? (float) ('0.' . $matches[4]) : 0.0;
        $result = $hours * 3600 + $minutes * 60 + $seconds + $fraction;
        if (!is_finite($result)) {
            throw new \InvalidArgumentException(sprintf('SPSS time value "%s" is out of range.', $value));
        }

        return $result;
    }

    /** Gregorian day number calculated without Unix timestamps or a default timezone. */
    private static function gregorianDayNumber(int $year, int $month, int $day): int
    {
        $a = intdiv(14 - $month, 12);
        $adjustedYear = $year + 4800 - $a;
        $adjustedMonth = $month + 12 * $a - 3;

        return $day
            + intdiv(153 * $adjustedMonth + 2, 5)
            + 365 * $adjustedYear
            + intdiv($adjustedYear, 4)
            - intdiv($adjustedYear, 100)
            + intdiv($adjustedYear, 400)
            - 32045;
    }

    /**
     * Rounds X up to the next multiple of Y.
     */
    public static function roundUp(int $x, int $y): int
    {
        return (int) (ceil($x / $y) * $y);
    }

    /**
     * Rounds X down to the prev multiple of Y.
     */
    public static function roundDown(int $x, int $y): int
    {
        return (int) (floor($x / $y) * $y);
    }

    /**
     * Convert bytes to string.
     *
     * @param list<int> $bytes
     */
    public static function bytesToString(array $bytes): string
    {
        $str = '';
        foreach ($bytes as $byte) {
            $str .= \chr($byte);
        }

        return $str;
    }

    /**
     * Convert double to string.
     *
     *
     */
    public static function doubleToString(float $num): string
    {
        $bytes = unpack('C8', pack('d', $num));
        if (false === $bytes) {
            throw new Exception('Unable to unpack a double value.');
        }

        return self::bytesToString(array_values($bytes));
    }

    public static function stringToDouble(string $str): float
    {
        // if (strlen($str) < 8) {
        //     throw new Exception('String must be a 8 length');
        // }

        $value = unpack('d', pack('A8', $str));
        if (false === $value || !isset($value[1]) || !\is_float($value[1])) {
            throw new Exception('Unable to unpack a string as a double value.');
        }

        return $value[1];
    }

    /**
     * @param list<int> $bytes
     * @return ($unsigned is true ? int : int|numeric-string)
     */
    public static function bytesToInt(array $bytes, bool $unsigned = true): int|string
    {
        $bytes = array_reverse($bytes);
        $value = 0;
        foreach ($bytes as $i => $b) {
            $value |= $b << $i * 8;
        }

        return $unsigned ? $value : self::unsignedToSigned($value, \count($bytes) * 8);
    }

    /**
     * @return list<int>
     */
    public static function intToBytes(int $int, int $size = 32): array
    {
        $size  = self::roundUp($size, 8);
        $bytes = [];
        for ($i = 0; $i < $size; $i += 8) {
            $bytes[] = 0xFF & $int >> $i;
        }

        return array_reverse($bytes);
    }

    /**
     *
     * @return int|numeric-string
     */
    public static function unsignedToSigned(int $value, int $size = 32): int|string
    {
        $size = self::roundUp($size, 8);
        if (bccomp((string) $value, bcpow('2', (string) ($size - 1))) >= 0) {
            return bcsub((string) $value, bcpow('2', (string) $size));
        }

        return $value;
    }

    public static function signedToUnsigned(int $value, int $size = 32): int|float
    {
        return $value + bcpow('2', (string) $size);
    }

    /**
     * Returns the number of bytes of uncompressed case data used for writing a variable of the given WIDTH to a system file.
     * All required space is included, including trailing padding and internal padding.
     *
     *
     */
    public static function widthToBytes(int $width): int
    {
        // assert($width >= 0);

        if (0 === $width) {
            $bytes = 8;
        } elseif (Variable::isVeryLong($width) === false) {
            $bytes = $width;
        } else {
            $chunks    = $width / Variable::EFFECTIVE_VLS_CHUNK;
            $remainder = $width % Variable::EFFECTIVE_VLS_CHUNK;
            $bytes     = floor($chunks) * Variable::REAL_VLS_CHUNK + $remainder;
        }

        return self::roundUp($bytes, 8);
    }

    /**
     * Returns the number of 8-byte units (octs) used to write data for a variable of the given WIDTH.
     */
    public static function widthToOcts(int $width): int
    {
        $result = 0;
        foreach (self::getSegments($width) as $segmentWidth) {
            $result += ceil($segmentWidth / 8);
        }

        return (int) max(1, $result);
    }

    /**
     * Returns the number of "segments" used for writing case data for a variable of the given WIDTH.
     * A segment is a physical variable in the system file that represents some piece of a logical variable.
     * Only very long string variables have more than one segment.
     *
     *
     */
    public static function widthToSegments(int $width): int
    {
        return Variable::isVeryLong($width) ? (int) ceil($width / Variable::EFFECTIVE_VLS_CHUNK) : 1;
    }

    /**
     * @return \Generator<int, int, void, void>
     */
    public static function getSegments(int $width): \Generator
    {
        $count = self::widthToSegments($width);
        for ($i = 1; $i < $count; $i++) {
            yield 255;
        }

        yield $width - ($count - 1) * Variable::EFFECTIVE_VLS_CHUNK;
    }

    /**
     * Returns the width to allocate to the given SEGMENT within a variable of the given WIDTH.
     * A segment is a physical variable in the system file that represents some piece of a logical variable.
     *
     *
     */
    public static function segmentAllocWidth(int $width, int $segment = 0): int
    {
        $segmentCount = self::widthToSegments($width);
        // assert($segment < $segmentCount);

        if (Variable::isVeryLong($width) === false) {
            return $width;
        }

        return $segment < $segmentCount - 1 ? Variable::REAL_VLS_CHUNK : $width - $segment * Variable::EFFECTIVE_VLS_CHUNK;
    }

    /**
     * Returns the number of bytes to allocate to the given SEGMENT within a variable of the given width.
     * This is the same as.
     *
     *
     *
     * @see segmentAllocWidth, except that a numeric value takes up 8 bytes despite having a width of 0.
     */
    public static function segmentAllocBytes(int $width, int $segment): int
    {
        \assert($segment < self::widthToSegments($width));

        return 0 === $width ? 8 : self::roundUp(self::segmentAllocWidth($width, $segment), 8);
    }

    /**
     * @param mixed $values
     */
    public static function is_countable($values): bool
    {
        return \is_countable($values);
    }
}
