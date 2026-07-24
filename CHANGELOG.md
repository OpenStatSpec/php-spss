# Changelog

## 3.0.0 (unreleased)

- Raised the minimum PHP version to 8.4.1.
- Upgraded the test suite from PHPUnit 7 to PHPUnit 13.
- Added PHPStan 2 level 6 analysis with strict and PHPUnit-specific rules.
- Added PHP-CS-Fixer 3, Rector 2, parallel syntax linting, Composer validation, and dependency vulnerability auditing.
- Replaced Travis CI with a GitHub Actions PHP 8.4/8.5 and lowest-dependency matrix.
- Added a versioned pre-push hook that runs the complete QA suite locally.
- Added weekly Dependabot updates for Composer and GitHub Actions.
- Modernized the codebase for PHP 8.4 and removed PHP 8.5 deprecations.

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
