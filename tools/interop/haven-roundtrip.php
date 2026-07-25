#!/usr/bin/env php
<?php

declare(strict_types=1);

use SPSS\Sav\Reader;
use SPSS\Sav\Record\Header;
use SPSS\Sav\Variable;
use SPSS\Sav\Writer;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

const INTEROP_SKIP = 77;
const MULTI_BLOCK_CASES = 470_000;

/**
 * @param list<string> $command
 * @return array{status: int, stdout: string, stderr: string}
 */
function runProcess(array $command): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    try {
        $process = @proc_open($command, $descriptorSpec, $pipes);
    } catch (\Throwable $throwable) {
        return [
            'status' => 127,
            'stdout' => '',
            'stderr' => $throwable->getMessage(),
        ];
    }

    if (!\is_resource($process)) {
        return [
            'status' => 127,
            'stdout' => '',
            'stderr' => sprintf('Unable to start command: %s', implode(' ', $command)),
        ];
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'status' => proc_close($process),
        'stdout' => false === $stdout ? '' : $stdout,
        'stderr' => false === $stderr ? '' : $stderr,
    ];
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

/** @param array<string, mixed> $configuration */
function writeSav(array $configuration, string $path): void
{
    $writer = new Writer($configuration);
    $written = $writer->save($path);
    expect(false !== $written && $written > 0, sprintf('Unable to write %s.', $path));
}

function writePhpFixtures(string $workDirectory): void
{
    $longValue = str_repeat("\xC3\x95", 320);
    expect(640 === strlen($longValue), 'The UTF-8 long-string probe must be 640 bytes.');

    $variables = [
        [
            'name' => 'number',
            'format' => Variable::FORMAT_TYPE_F,
            'width' => 8,
            'data' => [1, 2.5, 999],
        ],
        [
            'name' => 'short_text',
            'format' => Variable::FORMAT_TYPE_A,
            'width' => 8,
            'data' => ['abc', '', 'xyz'],
        ],
        [
            'name' => 'long_text',
            'format' => Variable::FORMAT_TYPE_A,
            'width' => 700,
            'data' => [$longValue, '', 'tail'],
        ],
    ];

    writeSav([
        'header' => [
            'recType' => Header::NORMAL_REC_TYPE,
            'compression' => 1,
        ],
        'info' => ['characterEncoding' => 'UTF-8'],
        'variables' => $variables,
    ], $workDirectory . '/php-byte.sav');

    writeSav([
        'header' => [
            'recType' => Header::ZLIB_REC_TYPE,
            'compression' => 2,
        ],
        'info' => ['characterEncoding' => 'UTF-8'],
        'variables' => $variables,
    ], $workDirectory . '/php-zlib.zsav');

    $values = [];
    for ($case = 0; $case < MULTI_BLOCK_CASES; $case++) {
        $values[] = $case + 0.5;
    }

    writeSav([
        'header' => [
            'recType' => Header::ZLIB_REC_TYPE,
            'compression' => 2,
        ],
        'variables' => [[
            'name' => 'number',
            'format' => Variable::FORMAT_TYPE_F,
            'width' => 8,
            'data' => $values,
        ]],
    ], $workDirectory . '/php-multiblock.zsav');

    unset($values);
    gc_collect_cycles();

    $metadata = Reader::fromFile($workDirectory . '/php-multiblock.zsav')->readMetaData();
    $stream = fopen($workDirectory . '/php-multiblock.zsav', 'rb');
    expect(false !== $stream, 'Unable to inspect the multi-block ZSAV.');

    try {
        expect(0 === fseek($stream, $metadata->dataPosition + 12), 'Unable to seek to the ZSAV trailer offset.');
        $trailerBytes = fread($stream, 8);
        expect(false !== $trailerBytes && 8 === strlen($trailerBytes), 'Unable to read the ZSAV trailer offset.');
        $trailer = unpack('qoffset', $trailerBytes);
        expect(\is_array($trailer), 'Unable to decode the ZSAV trailer offset.');

        expect(0 === fseek($stream, $trailer['offset'] + 20), 'Unable to seek to the ZSAV block count.');
        $blockBytes = fread($stream, 4);
        expect(false !== $blockBytes && 4 === strlen($blockBytes), 'Unable to read the ZSAV block count.');
        $blockCount = unpack('icount', $blockBytes);
        expect(\is_array($blockCount) && $blockCount['count'] > 1, 'The ZSAV probe did not cross a block boundary.');
    } finally {
        fclose($stream);
    }
}

/** @return list<array<int, float|string>> */
function expectedHavenRows(): array
{
    return [
        [1.0, 'abc'],
        [2.5, ''],
        [999.0, 'xyz'],
    ];
}

function verifyHavenFixture(string $path, string $recordType, int $compression): void
{
    $reader = Reader::fromFile($path)->read();

    expect($recordType === $reader->header->recType, sprintf('%s has an unexpected record type.', basename($path)));
    expect($compression === $reader->header->compression, sprintf('%s has unexpected compression.', basename($path)));
    expect(expectedHavenRows() === $reader->data, sprintf('%s has unexpected data.', basename($path)));

    $iterator = Reader::fromFile($path)->readMetaData();
    $rows = [];
    while ($iterator->readCase()) {
        $rows[] = $iterator->getCase();
    }

    expect(expectedHavenRows() === $rows, sprintf('%s has unexpected iterator data.', basename($path)));
}

function cleanupInteropDirectory(string $workDirectory): void
{
    if (!is_dir($workDirectory)) {
        return;
    }

    $entries = scandir($workDirectory);
    if (false === $entries) {
        throw new \RuntimeException(sprintf('Unable to inspect temporary directory %s.', $workDirectory));
    }

    foreach ($entries as $entry) {
        if ('.' === $entry) {
            continue;
        }
        if ('..' === $entry) {
            continue;
        }
        $path = $workDirectory . DIRECTORY_SEPARATOR . $entry;
        if (!is_file($path) || !unlink($path)) {
            throw new \RuntimeException(sprintf('Unable to remove temporary interop file %s.', $path));
        }
    }

    if (!rmdir($workDirectory)) {
        throw new \RuntimeException(sprintf('Unable to remove temporary directory %s.', $workDirectory));
    }
}

$rScript = __DIR__ . '/haven-roundtrip.R';
$check = runProcess(['Rscript', $rScript, '--check']);
if (127 === $check['status'] || INTEROP_SKIP === $check['status']) {
    fwrite(STDERR, "SKIP: Rscript with haven is not available.\n");
    if ('' !== trim($check['stderr'])) {
        fwrite(STDERR, trim($check['stderr']) . "\n");
    }

    exit(INTEROP_SKIP);
}
if (0 !== $check['status']) {
    fwrite(STDERR, "ERROR: Unable to verify the R/haven prerequisite.\n" . $check['stderr']);
    exit(1);
}

printf("Interop prerequisite: %s", $check['stdout']);

$workDirectory = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    . DIRECTORY_SEPARATOR
    . 'php-spss-haven-'
    . getmypid()
    . '-'
    . bin2hex(random_bytes(4));

if (!mkdir($workDirectory, 0700)) {
    fwrite(STDERR, sprintf("ERROR: Unable to create temporary directory %s.\n", $workDirectory));
    exit(1);
}

$status = 1;
try {
    writePhpFixtures($workDirectory);

    $result = runProcess(['Rscript', $rScript, '--verify-php', $workDirectory]);
    if (INTEROP_SKIP === $result['status']) {
        fwrite(STDERR, "SKIP: haven became unavailable during the interoperability check.\n");
        $status = INTEROP_SKIP;
    } elseif (0 !== $result['status']) {
        throw new \RuntimeException(
            "R/haven rejected a PHP fixture.\n"
            . trim($result['stdout'] . "\n" . $result['stderr']),
        );
    } else {
        printf("%s", $result['stdout']);
        verifyHavenFixture($workDirectory . '/haven-byte.sav', Header::NORMAL_REC_TYPE, 1);
        verifyHavenFixture($workDirectory . '/haven-zlib.zsav', Header::ZLIB_REC_TYPE, 2);
        echo "PHP verified haven byte-compressed SAV and ZLIB-compressed ZSAV.\n";
        echo "PASS: bidirectional R/haven interoperability gate completed.\n";
        $status = 0;
    }
} catch (\Throwable $throwable) {
    fwrite(STDERR, 'ERROR: ' . $throwable->getMessage() . "\n");
    $status = 1;
} finally {
    try {
        cleanupInteropDirectory($workDirectory);
    } catch (\Throwable $exception) {
        fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
        $status = 1;
    }
}

exit($status);
