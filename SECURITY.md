# Security Policy

Submit reports through [this package's private vulnerability reporting form](https://github.com/nvl-laravel-suite/translations/security/advisories/new).

The prepared `5.x` release line targets PHP 8.4–8.5 and Laravel 12–13. This compatibility matrix remains candidate/unverified until same-source execution evidence is reviewed; upstream security lifecycle limits still apply.

Report vulnerabilities privately through the repository host's security-advisory feature. Include the profile, normalized root, file format, conflict strategy, operation, and impact without attaching proprietary translation catalogs.

Keep APIs disabled, authorize workspace operations, reject traversal and symlink escape, prevent unintended vendor writes, hold process locks, validate temporary output, and require explicit force for destructive replacement.
