<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$files = [
    // __DIR__ . '/test2.sav',
    __DIR__ . '/data.sav',
];

function __header(string $str, $char = '#'): string
{
    $line = str_repeat((string) $char, 100);

    $res = '';
    $res .= $line . PHP_EOL;
    $res .= "#\t\t" . $str . PHP_EOL;

    return $res . ($line . PHP_EOL);
}

function __title($title, $char = '.'): string
{
    return PHP_EOL .
        str_repeat((string) $char, 10) . ' ' .
        mb_strtoupper((string) $title) . ' ' .
        str_repeat((string) $char, 70) .
        PHP_EOL;
}

function __content($data): string
{
    $data = json_encode($data);
    // $data = json_decode($data, true);

    return print_r($data, true);
}

foreach ($files as $file) {
    $reader = \SPSS\Sav\Reader::fromFile($file)->readMetaData();

    echo PHP_EOL;

    echo __header(sprintf('OPEN FILE %s', $file));

    echo __title('Header');
    echo __content($reader->header);

    echo __title('Documents');
    echo __content($reader->documents);

    echo __title('Variables');
    echo __content($reader->variables);

    echo __title('Values-labels');
    echo __content($reader->valueLabels);

    echo __title('Additional-info');
    echo __content($reader->info);

    while ($reader->readCase()) {
        echo __title('Case ' . $reader->getCaseNumber());
        echo __content($reader->getCase());
    }

    echo PHP_EOL;
}
