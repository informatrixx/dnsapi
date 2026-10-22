# Repository Guidelines

## Project Structure & Module Organization
Root PHP files are stable entry points and compatibility loaders. Implementation lives in `src/Provider`, `src/Dns`, `src/Certbot`, `src/Tlsa`, `src/Cli` and `src/Runtime`. `index.php` handles DynDNS; `dnsapi-interface.php` manages records; `certbot-*.php` integrate certificate operations. `dnsapi-check.php` performs a read-only mail DNS inventory. See `docs/architecture.md` and `docs/operations.md`.

## Build, Test, and Development Commands
There is no build system or Composer setup. Use PHP CLI tooling directly:

- `php -d error_reporting=E_ALL -l index.php` checks syntax for a single file.
- `php -d error_reporting=E_ALL -l dnsapi-interface.php` checks the CLI entry point.
- `./dnsapi-interface.php -x example.com -p` prints records for a configured zone.
- `php8.4 tests/hooks.php` and `php8.5 tests/hooks.php` run offline behavior tests, including hook subprocesses with synthetic providers.
- `php8.4 tools/verify.php` and `php8.5 tools/verify.php` run all syntax, behavior and loopback HTTP contract checks.
- `./dnsapi-interface.php --config-check` validates configuration without network access; add `--dry-run` to mutations to inspect a read-only plan.
- `./dnsapi-interface.php -x example.com -s test:A=203.0.113.10:600` performs a manual record upsert against configured provider credentials.

Run manual integration checks only with non-production zones or throwaway records.

## Coding Style & Naming Conventions
Follow the existing style: tabs for indentation, K&R-style braces, and procedural top-level scripts with provider classes. Keep file names in the current pattern: `dnsapi-<Provider>.inc.php` for adapters and `certbot-*.php` for Certbot hooks. Match the codebase’s use of `array(...)`, `$a*` local variables, and descriptive constant names such as `CONFIG_FILE`. Support PHP 8.4 and 8.5 with cURL and resolve deprecation warnings on both versions. Executable scripts use `/usr/bin/php`; the HTTP endpoint also supports either version.

## Testing Guidelines
No formal coverage threshold exists. Run `tools/verify.php` on PHP 8.4 and 8.5. `tests/hooks.php` tests Certbot, `tests/product.php` covers CLI/TLSA/DynDNS and `tests/providers.php` verifies real adapter requests against loopback fixtures. Preserve compatibility loaders and exact-value cleanup. Live smoke tests require an approved zone and explicit authorization for writes. Document untested integration paths in the PR.

`tools/verify-release.php --archive FILE --version VERSION --baseline-archive FILE` tests a trusted release archive and upgrade/rollback against pinned v0.2.1. `tests/release.php` tests archive safety and RC versions. Follow `docs/release-acceptance.md`; no live DNS or publication belongs in offline CI.

## Commit & Pull Request Guidelines
Git history uses short, imperative commit subjects such as `Fix undefined variable checks in dynamic DNS handlers`. Keep subjects concise and behavior-focused. PRs should describe the affected flow, note provider-specific risk, list manual verification steps, and include sample requests or command lines when behavior changes.

## Security & Configuration Tips
Runtime scripts read `/etc/dnsapi/config.json` and write cache or journal data under `/var/cache/dnsapi`. Never commit live API tokens, secrets, or journal files. Treat the repository `config.json` as local-only reference data and scrub credentials before sharing changes.
