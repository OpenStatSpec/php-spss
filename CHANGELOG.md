# Changelog

## 3.0.2 (2026-07-31)

- Moved active maintenance to the independent `OpenStatSpec/php-spss` repository.
- Renamed the Composer package from `tiamo/spss` to `openstatspec/spss-sav`, while keeping the stable `SPSS\` PHP namespace.
- Preserved the original author attribution and added Composer replacement metadata for migration compatibility.

## 3.0.1 (2026-07-31)

- Made fixed-width strings byte-safe in the target file encoding, with complete-character truncation, correct declared lengths, and space padding for variable and value labels.
- Corrected bytecode compression for integer-valued floats, signed zero, fractional compression biases, and non-UTF-8 string payloads in SAV and ZSAV files.
- Added the explicit, strict `Utils::parseSpssDateTime()` helper for supported SPSS DATE, TIME, and DATETIME text forms; numeric cells are never converted implicitly.
- Added focused regression and mutation coverage for buffer boundaries, target encodings, long-string metadata, compressed opcodes, and date/time validation.

## 3.0.0 (2026-07-26)

- Raised the minimum PHP version to 8.4.1.
- Upgraded the test suite from PHPUnit 7 to PHPUnit 13.
- Added PHPStan 2 level 6 analysis with strict and PHPUnit-specific rules.
- Added PHP-CS-Fixer 3, Rector 2, parallel syntax linting, Composer validation, and dependency vulnerability auditing.
- Replaced Travis CI with a GitHub Actions PHP 8.4/8.5 and lowest-dependency matrix.
- Added a versioned pre-push hook that runs the complete QA suite locally.
- Added weekly Dependabot updates for Composer and GitHub Actions.
- Modernized the codebase for PHP 8.4 and removed PHP 8.5 deprecations.
- Added an immutable typed Dataset API that preserves supported file, variable, value-label, missing-value, set, and attribute metadata.
- Added complete SAV and zlib-compressed ZSAV semantic round trips, including UTF-8 very-long strings and display metadata.
- Added bidirectional R/haven SAV and ZSAV interoperability tests.
- Added malformed-input fuzzing and bounded memory/resource regression tests.
- Added line, branch, and Infection mutation gates to CI and the versioned pre-push hook.

## 2.2.2

2021-01

* Compatibility: is_countable (PHP 7 >= 7.3.0, PHP 8) ([#58](https://github.com/tiamo/spss/pull/58))

## 2.2.1

2020-12

* Fix date writer ([#55](https://github.com/tiamo/spss/pull/55))

## 2.2.0

2020-06

+ Fixed bugs
+ Improved tests
+ Code refactored
+ Added linters

## 2.1.*

2018-10

+ Full code refactory
+ Fixes many bugs
+ Updates Testing/developer environment (phpunit 7+)
+ Examples added
+ Tests added
