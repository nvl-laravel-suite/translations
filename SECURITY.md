# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/translations/security/advisories/new).

Security fixes are provided for the published `5.x` release line. Composer declares PHP `^8.4` and Laravel `^12.0|^13.0`. The local Dagger release gate verifies PHP 8.4/Laravel 13 with MySQL 8.4 and PostgreSQL 17 persistence contracts; PHP 8.5, Laravel 12 and MariaDB require separate compatibility evidence. Upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the profile, normalized root, file format, conflict strategy, operation, and impact without attaching proprietary translation catalogs.

Keep APIs disabled, authorize workspace operations, reject traversal and symlink escape, prevent unintended vendor writes, hold process locks, validate temporary output, and require explicit force for destructive replacement.
