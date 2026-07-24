<?php

namespace SPSS\Tests;

use SPSS\Sav\Reader;
use SPSS\Sav\Variable;

class TestCase extends \PHPUnit\Framework\TestCase
{
    /**
     * @param array<string, bool|float|int|string|null> $header
     */
    protected function checkHeader(array $header, Reader $reader): void
    {
        /** @var array<string, mixed> $actualHeader */
        $actualHeader = get_object_vars($reader->header);

        foreach ($header as $key => $value) {
            $this->assertArrayHasKey($key, $actualHeader);
            $actualValue = $actualHeader[$key];

            $this->assertEquals(
                $value,
                $actualValue,
                sprintf(
                    'Header line `%s` is invalid: expected `%s` but got `%s`.',
                    $key,
                    var_export($value, true),
                    var_export($actualValue, true),
                ),
            );
        }
    }

    /**
     * @param array{id?: string, numeric?: int, casesCount?: int} $opts
     * @return array{name: string, label: string, columns: int, alignment: int, measure: int, width: int, format: int, decimals: int, data: list<string>}
     */
    protected static function generateVariable($opts = []): array
    {
        $opts = array_merge(
            [
                'id'         => uniqid('', true),
                'numeric'    => mt_rand(0, 1),
                'casesCount' => 0,
            ],
            $opts,
        );

        $var = [
            'name'      => sprintf('VAR%s', $opts['id']),
            'label'     => sprintf('Label (%s)', $opts['id']),
            'columns'   => mt_rand(0, 100),
            'alignment' => mt_rand(0, 2),
            'measure'   => mt_rand(1, 3),
            'width'     => 8,
            'format'     => Variable::FORMAT_TYPE_A,
            'decimals'   => 0,
            'data'       => [],
        ];

        if (1 === $opts['numeric']) {
            $var['format']   = Variable::FORMAT_TYPE_F;
            $var['decimals'] = mt_rand(0, 2);
            for ($c = 0; $c < $opts['casesCount']; $c++) {
                $var['data'][$c] = mt_rand(1, 99999) . '.' . mt_rand(1, 99999);
            }
        } else {
            $var['format'] = Variable::FORMAT_TYPE_A;
            $var['width']    = mt_rand(2, 2000);
            $var['decimals'] = 0;
            for ($c = 0; $c < $opts['casesCount']; $c++) {
                $var['data'][$c] = trim(self::generateRandomString(mt_rand(0, $var['width'])));
            }
        }

        return $var;
    }

    /**
     * @param int $length
     */
    protected static function generateRandomString($length = 10): string
    {
        $characters       = '_0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = \strlen($characters);
        $randomString     = '';
        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[mt_rand(0, $charactersLength - 1)];
        }

        return trim($randomString);
    }
}
