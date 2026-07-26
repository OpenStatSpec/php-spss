# Changelog

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
