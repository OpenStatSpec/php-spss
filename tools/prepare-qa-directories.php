#!/usr/bin/env php
<?php

declare(strict_types=1);

foreach ([
    __DIR__ . '/../build/coverage',
    __DIR__ . '/../build/infection',
] as $directory) {
    if (is_dir($directory)) {
        continue;
    }

    if (!mkdir($directory, 0o777, true) && !is_dir($directory)) {
        fwrite(STDERR, sprintf("Unable to create QA output directory %s.\n", $directory));
        exit(1);
    }
}
