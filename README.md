# SPSS SAV/ZSAV for PHP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/openstatspec/spss-sav.svg?style=flat-square)](https://packagist.org/packages/openstatspec/spss-sav)
[![QA](https://github.com/OpenStatSpec/php-spss/actions/workflows/qa.yml/badge.svg)](https://github.com/OpenStatSpec/php-spss/actions/workflows/qa.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/openstatspec/spss-sav.svg?style=flat-square)](https://packagist.org/packages/openstatspec/spss-sav)
[![License](https://poser.pugx.org/openstatspec/spss-sav/license)](https://packagist.org/packages/openstatspec/spss-sav)

A PHP library for reading and writing SPSS / PSPP SAV and ZSAV data files.

Maintained by [OpenStatSpec](https://github.com/OpenStatSpec). This project continues the MIT-licensed [`tiamo/spss`](https://github.com/tiamo/spss) codebase created by Vladyslav Korniienko. The stable PHP namespace remains `SPSS\` for compatibility.

## Requirements

- PHP 8.4.1 or newer
- mbstring extension
- bcmath extension
- zlib extension

## Installation

```bash
composer require openstatspec/spss-sav
```

## Usage

Read a file into the v3 typed dataset model:

```php
use SPSS\Sav\Reader;

$dataset = Reader::fromFile('/path/to/input.sav')->readDataset();

foreach ($dataset->rows() as $row) {
    // Values follow the order of $dataset->variables().
}
```

Write a typed dataset:

```php
use SPSS\Sav\Writer;

$writer = new Writer($dataset);
$writer->save('/path/to/output.sav');
$writer->close();
```

Numeric `null` cells represent SPSS system-missing values. Strings must always be strings; an empty string is an ordinary value. SPSS dates, times, and currencies remain numeric values whose presentation is described by `VariableFormat`.

See the [v3 typed Dataset guide](./docs/index.md) for creating datasets, metadata, SAV/ZSAV support, and migration from the legacy array API. The older low-level [examples](./examples) remain available during migration.

## Changelog

Please have a look in [CHANGELOG](CHANGELOG.md)

## Development

Install the locked development dependencies:

```bash
composer install
```

The install command activates the versioned pre-push hook in `.githooks`. It runs the same complete QA suite used in CI before every push. You can also run the checks directly:

```bash
composer qa
composer qa:release
```

`composer qa` validates and audits Composer dependencies, lints PHP syntax, checks coding style, runs Rector in dry-run mode, performs PHPStan level 6 analysis with strict rules, and executes the PHPUnit 13 tests. `composer qa:release` additionally enforces:

- at least 85% executable-line coverage;
- at least 74% branch coverage across the deterministic branch shards;
- Infection's mutation-testing threshold, with timeouts counted against the score and capped;
- bidirectional SAV/ZSAV interoperability with R/haven.

The release suite requires Xdebug coverage plus `Rscript` with the `haven` package. The pre-push hook runs this complete release suite. Run `composer fix` to apply safe Rector and coding-style fixes.

See [Quality and release gates](./docs/quality-gates.md) for individual commands, report locations, and the rationale for each threshold.

## License

Licensed under the [MIT license](http://opensource.org/licenses/MIT).
