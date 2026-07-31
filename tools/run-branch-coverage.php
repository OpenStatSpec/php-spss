#!/usr/bin/env php
<?php

declare(strict_types=1);

use SebastianBergmann\CodeCoverage\Report\Facade as ReportFacade;
use SebastianBergmann\CodeCoverage\Serialization\Merger;

require dirname(__DIR__) . '/vendor/autoload.php';

final class BranchCoverageRunner
{
    private const float MINIMUM_BRANCH_COVERAGE = 74.0;

    /** @var array<string, list<string>> */
    private const array SHARDS = [
        'a' => [
            'BufferBoundaryTest',
            'DatasetAssemblerTest',
            'DatasetRoundTripTest',
            'HeaderValidationTest',
            'NamingTest',
        ],
        'b' => [
            'InfoAttributeRecordsTest',
            'InfoSetRecordsTest',
            'LongStringTest',
            'MachineFloatingPointTest',
            'ValueLabelRoundTripTest',
        ],
        'd' => [
            'MalformedContainerTest',
            'SavBinaryLayerTest',
            'SavDateFormatTest',
            'SavDateTimeValueTest',
            'VeryLongStringWriterTest',
            'WriteMultibyteTest',
        ],
    ];

    private readonly string $rootDirectory;

    private readonly string $coverageDirectory;

    private readonly string $reportPath;

    /** @var list<string> */
    private array $shardArtifacts = [];

    public function __construct()
    {
        $this->rootDirectory = dirname(__DIR__);
        $this->coverageDirectory = $this->rootDirectory . '/build/coverage';
        $this->reportPath = $this->coverageDirectory . '/branches.xml';

        foreach (array_keys(self::SHARDS) as $shard) {
            $this->shardArtifacts[] = $this->coverageDirectory . '/branch-' . $shard . '.php';
        }
    }

    public function run(): int
    {
        $status = 1;

        try {
            $this->prepareOutputDirectory();
            $this->removeArtifacts($this->shardArtifacts);
            $this->removeArtifacts([$this->reportPath]);

            foreach (self::SHARDS as $shard => $tests) {
                $this->runShard($shard, $tests);
            }

            $merged = new Merger()->merge($this->shardArtifacts, false);
            $report = ReportFacade::fromSerializedData($merged);
            $report->renderCobertura($this->reportPath);
            $summary = $report->summary();

            printf(
                "Lines: %.2f%% (%d/%d)\n",
                $summary->lineCoverageAsPercentage(),
                $summary->numberOfExecutedLines(),
                $summary->numberOfExecutableLines(),
            );
            printf(
                "Branches: %.2f%% (%d/%d)\n",
                $summary->branchCoverageAsPercentage(),
                $summary->numberOfExecutedBranches(),
                $summary->numberOfExecutableBranches(),
            );
            printf(
                "Paths: %.2f%% (%d/%d)\n",
                $summary->pathCoverageAsPercentage(),
                $summary->numberOfExecutedPaths(),
                $summary->numberOfExecutablePaths(),
            );

            if (!$summary->hasBranchAndPathCoverage()) {
                throw new \RuntimeException('Merged coverage contains no branch or path data.');
            }

            $branchCoverage = $summary->branchCoverageAsPercentage();
            if ($branchCoverage + 0.000_001 < self::MINIMUM_BRANCH_COVERAGE) {
                throw new \RuntimeException(sprintf(
                    'Branch coverage %.2f%% is below the required %.2f%%.',
                    $branchCoverage,
                    self::MINIMUM_BRANCH_COVERAGE,
                ));
            }

            printf(
                "PASS: branch coverage %.2f%% meets the %.2f%% lower bound.\n",
                $branchCoverage,
                self::MINIMUM_BRANCH_COVERAGE,
            );
            printf("Cobertura report: %s\n", $this->reportPath);
            $status = 0;
        } catch (\Throwable $throwable) {
            fwrite(STDERR, 'ERROR: ' . $throwable->getMessage() . "\n");
            $status = 1;
        } finally {
            try {
                $this->removeArtifacts($this->shardArtifacts);
            } catch (\Throwable $exception) {
                fwrite(STDERR, 'ERROR: ' . $exception->getMessage() . "\n");
                $status = 1;
            }
        }

        return $status;
    }

    private function prepareOutputDirectory(): void
    {
        if (is_dir($this->coverageDirectory)) {
            return;
        }

        if (!mkdir($this->coverageDirectory, 0o777, true) && !is_dir($this->coverageDirectory)) {
            throw new \RuntimeException(sprintf(
                'Unable to create coverage output directory %s.',
                $this->coverageDirectory,
            ));
        }
    }

    /**
     * @param non-empty-string $shard
     * @param list<string> $tests
     */
    private function runShard(string $shard, array $tests): void
    {
        $artifact = $this->coverageDirectory . '/branch-' . $shard . '.php';
        $command = [
            PHP_BINARY,
            $this->rootDirectory . '/vendor/bin/phpunit',
            '--do-not-cache-result',
            '--path-coverage',
            '--coverage-php',
            $artifact,
        ];

        foreach ($tests as $test) {
            $testPath = $this->rootDirectory . '/tests/' . $test . '.php';
            if (!is_file($testPath)) {
                throw new \RuntimeException(sprintf('Shard %s test file does not exist: %s.', $shard, $testPath));
            }

            $command[] = $testPath;
        }

        printf("\n== Branch coverage shard %s ==\n", strtoupper($shard));
        $status = $this->runProcess($command);
        if (0 !== $status) {
            throw new \RuntimeException(sprintf('Branch coverage shard %s failed with exit status %d.', $shard, $status));
        }

        clearstatcache(true, $artifact);
        $artifactSize = filesize($artifact);
        if (!is_file($artifact) || false === $artifactSize || 0 === $artifactSize) {
            throw new \RuntimeException(sprintf('Branch coverage shard %s produced no serialized coverage.', $shard));
        }
    }

    /** @param list<string> $command */
    private function runProcess(array $command): int
    {
        $descriptorSpec = [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ];
        $environment = getenv();
        $environment['XDEBUG_MODE'] = 'coverage';

        $process = proc_open(
            $command,
            $descriptorSpec,
            $pipes,
            $this->rootDirectory,
            $environment,
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException(sprintf('Unable to start command: %s.', implode(' ', $command)));
        }


        return proc_close($process);
    }

    /** @param list<string> $paths */
    private function removeArtifacts(array $paths): void
    {
        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            if (!unlink($path)) {
                throw new \RuntimeException(sprintf('Unable to remove temporary coverage artifact %s.', $path));
            }
        }
    }
}

exit(new BranchCoverageRunner()->run());
