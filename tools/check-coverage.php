#!/usr/bin/env php
<?php

declare(strict_types=1);

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

if (4 !== \count($arguments) || !\in_array($arguments[1], ['line', 'branch'], true) || !is_numeric($arguments[3])) {
    fwrite(STDERR, "Usage: tools/check-coverage.php <line|branch> <report.xml> <minimum-percent>\n");

    exit(2);
}

$metric = $arguments[1];
$reportPath = $arguments[2];
$minimum = (float) $arguments[3];

if ($minimum < 0.0 || $minimum > 100.0) {
    fwrite(STDERR, "Coverage threshold must be between 0 and 100.\n");

    exit(2);
}

$document = new \DOMDocument();
$previousUseInternalErrors = libxml_use_internal_errors(true);
$loaded = $document->load($reportPath, LIBXML_NONET);
libxml_clear_errors();
libxml_use_internal_errors($previousUseInternalErrors);

if (!$loaded) {
    fwrite(STDERR, sprintf("Unable to read coverage report %s.\n", $reportPath));

    exit(2);
}

[$covered, $total] = 'line' === $metric ? readCloverLineCoverage($document) : readCoberturaBranchCoverage($document);

if (0 === $total) {
    fwrite(STDERR, sprintf("Coverage report %s contains no %s coverage data.\n", $reportPath, $metric));

    exit(2);
}

$percentage = 100 * $covered / $total;
printf(
    "%s coverage: %.2f%% (%d/%d), required: %.2f%%\n",
    ucfirst($metric),
    $percentage,
    $covered,
    $total,
    $minimum,
);

exit($percentage + 0.000_001 >= $minimum ? 0 : 1);

/** @return array{int, int} */
function readCloverLineCoverage(\DOMDocument $document): array
{
    $projects = $document->getElementsByTagName('project');
    $project = $projects->item(0);
    if (!$project instanceof \DOMElement) {
        return [0, 0];
    }

    foreach ($project->childNodes as $node) {
        if ($node instanceof \DOMElement && 'metrics' === $node->tagName) {
            return [
                (int) $node->getAttribute('coveredstatements'),
                (int) $node->getAttribute('statements'),
            ];
        }
    }

    return [0, 0];
}

/** @return array{int, int} */
function readCoberturaBranchCoverage(\DOMDocument $document): array
{
    $coverage = $document->documentElement;
    if (!$coverage instanceof \DOMElement || 'coverage' !== $coverage->tagName) {
        return [0, 0];
    }

    $total = (int) $coverage->getAttribute('branches-valid');
    if (0 === $total) {
        return [0, 0];
    }

    $rate = (float) $coverage->getAttribute('branch-rate');

    return [(int) round($rate * $total), $total];
}
