# Quality and release gates

Run the full local release gate before proposing a PHP-SPSS v3 release:

```bash
composer qa:release
```

Composer installs `.githooks/pre-push` as the repository's Git hooks path, so every push runs the same gate. No release or tag should be created unless this command and the GitHub Actions workflow are green.

## Gates

`composer qa` runs dependency validation and auditing, syntax linting, PHP-CS-Fixer, Rector dry-run, PHPStan strict analysis, and the PHPUnit suite.

`composer qa:lowest` runs the same code checks against the lowest dependency versions allowed by `composer.json`. It validates Composer metadata but intentionally omits the vulnerability audit because the compatibility job creates a temporary lock file containing old versions; the committed lock file remains fully audited by `composer qa` in both local and locked CI jobs.

`composer test:coverage` creates `build/coverage/clover.xml` and requires at least 85% executable-line coverage. This is a whole-suite regression gate.

`composer test:coverage:branch` runs three deterministic, memory-bounded Xdebug path-coverage shards, merges them, creates `build/coverage/branches.xml`, and requires at least 74% branch coverage. The shard list is intentionally explicit so that the metric remains reproducible without the excessive memory cost of collecting path coverage for the entire suite in one process. Path coverage is reported for diagnostics but is not a release threshold because the number of possible paths is combinatorial.

`composer test:mutation` runs Infection against covered production code and requires a covered mutation score indicator (MSI) of at least 80%. Mutant timeouts count as escaped mutations, use a 60-second ceiling, and are capped at 15 so runaway loop mutations remain bounded without inflating the score. Reports are written under `build/infection/`.

`composer test:interop` performs bidirectional semantic round trips with R/haven:

- PHP writes byte-compressed SAV and zlib-compressed ZSAV, then haven reads both;
- haven writes SAV and ZSAV, then PHP reads both through bulk and iterator APIs;
- UTF-8, very-long strings, compressed opcode padding, and multi-block ZSAV data are exercised.

This gate requires `Rscript` and the R `haven` package. Exit status `77` means that the prerequisite is unavailable and is still a failing release gate. CI installs haven before running it.

## Individual commands

```bash
composer test:unit
composer test:coverage
composer test:coverage:branch
composer test:mutation
composer test:interop
```

Expected report artifacts:

| Gate | Report |
| --- | --- |
| Line coverage | `build/coverage/clover.xml` |
| Branch coverage | `build/coverage/branches.xml` |
| Mutation testing | `build/infection/summary.log`, `infection.json`, and HTML/per-mutator reports |

## Release checklist

1. Ensure the working tree is clean and the intended v3 branch is synchronized with its remote.
2. Run `composer qa:release` locally through the pre-push hook.
3. Verify every GitHub Actions job is green, including locked/lowest dependencies, coverage/mutation, and R/haven interoperability.
4. Review the changelog and public API documentation.
5. Only then prepare a release or tag as a separate, explicit operation.
