# Repository Guidelines

## Project Structure & Module Organization
This repository is a flat PHP codebase. `index.php` is the HTTP entry point for dynamic DNS updates. `dnsapi-interface.php` is the CLI for record management. Provider adapters live in `dnsapi-Hetzner.inc.php`, `dnsapi-HetznerCloud.inc.php`, and `dnsapi-Netcup.inc.php`. Certbot hooks are in `certbot-dnsapi-authenticator.php`, `certbot-dnsapi-cleanup.php`, and `certbot-tlsa-generator.php`.

## Build, Test, and Development Commands
There is no build system, Composer setup, or automated test runner in this repo. Use PHP CLI tooling directly:

- `php -l index.php` checks syntax for a single file.
- `php -l dnsapi-interface.php` checks the CLI entry point.
- `php dnsapi-interface.php -x example.com -p` prints records for a configured zone.
- `php dnsapi-interface.php -x example.com -s test:A=203.0.113.10:600` performs a manual record upsert against configured provider credentials.

Run manual integration checks only with non-production zones or throwaway records.

## Coding Style & Naming Conventions
Follow the existing style: tabs for indentation, K&R-style braces, and procedural top-level scripts with provider classes. Keep file names in the current pattern: `dnsapi-<Provider>.inc.php` for adapters and `certbot-*.php` for Certbot hooks. Match the codebase’s use of `array(...)`, `$a*` local variables, and descriptive constant names such as `CONFIG_FILE`. The code uses named arguments and runs on PHP 8.4, so keep new code compatible with modern PHP.

## Testing Guidelines
No formal coverage threshold exists. At minimum, lint changed PHP files and smoke-test the affected path. For HTTP changes, hit `index.php` with a known test host/domain/secret combination. For provider changes, verify `-p`, `-s`, and delete flows through `dnsapi-interface.php`. Document any untested paths in the PR.

## Commit & Pull Request Guidelines
Git history uses short, imperative commit subjects such as `Fix undefined variable checks in dynamic DNS handlers`. Keep subjects concise and behavior-focused. PRs should describe the affected flow, note provider-specific risk, list manual verification steps, and include sample requests or command lines when behavior changes.

## Security & Configuration Tips
Runtime scripts read `/etc/dnsapi/config.json` and write cache or journal data under `/var/cache/dnsapi`. Never commit live API tokens, secrets, or journal files. Treat the repository `config.json` as local-only reference data and scrub credentials before sharing changes.
