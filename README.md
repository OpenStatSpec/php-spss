# SPSS / PSPP

[![Latest Version on Packagist](https://img.shields.io/packagist/v/tiamo/spss.svg?style=flat-square)](https://packagist.org/packages/tiamo/spss)
[![QA](https://github.com/tiamo/spss/actions/workflows/qa.yml/badge.svg)](https://github.com/tiamo/spss/actions/workflows/qa.yml)
[![Total Downloads](https://img.shields.io/packagist/dt/tiamo/spss.svg?style=flat-square)](https://packagist.org/packages/tiamo/spss)
[![License](https://poser.pugx.org/tiamo/spss/license)](https://packagist.org/packages/tiamo/spss)

A PHP library for reading and writing SPSS / PSPP .sav data files.

## Requirements

- PHP 8.4.1 or newer
- mbstring extension
- bcmath extension

## Installation

```
composer require tiamo/spss
```

## Usage

* [Documentation](./docs/index.md)
* [Examples](./examples)

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
```

The suite validates and audits Composer dependencies, lints PHP syntax, checks coding style, runs Rector in dry-run mode, performs PHPStan level 6 analysis with strict rules, and executes the PHPUnit 13 tests. Run `composer fix` to apply safe Rector and coding-style fixes.

## License

Licensed under the [MIT license](http://opensource.org/licenses/MIT).
