# Contributing to NVL Translations

This public repository is a publication mirror of private source. Open an issue
here for a bug or proposal; include a reproduction and, if helpful, a patch.
Maintainers apply accepted changes in source and publish a mirror release.
Direct mirror pull requests do not update source. See the
[organization contribution guide](https://github.com/nvl-laravel-suite/.github/blob/main/CONTRIBUTING.md).

Changes must preserve exact deterministic UTF-8 string/null PHP and JSON round trips and file authority.

Test nested keys, nulls, long and case-sensitive keys, Unicode, escaping, custom roots and outputs, source conflicts, concurrent edits and exporters, locks, batch atomic failures, backups, pruning, malformed files, encoding, path attacks, and SQLite/MySQL/PostgreSQL parity. Run Pest, Pint, PHPStan at maximum strictness, Composer validation, dependency analysis, and distribution validation.

File-writing and authorization branches require complete coverage.
