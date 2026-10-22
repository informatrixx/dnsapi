# Contributing

## Workflow

Create a focused branch from `main`, for example `agent/fix-netcup-dns`. Keep provider behavior, Certbot hooks, and repository infrastructure in separate changes where practical.

Use short imperative commit subjects, such as `Fix DNS propagation handling for Certbot`. Pull requests should explain the affected flow, provider-specific risk, validation commands, and any untested integration paths.

## Validation

Support PHP 8.4 and 8.5 with cURL. Run `php -d error_reporting=E_ALL -l file.php` for every changed PHP file on both versions and use `git diff --check`. Resolve deprecation warnings as well as syntax errors; CI checks both versions. Provider smoke tests must use an approved test zone or read-only commands such as:

```sh
./dnsapi-interface.php -x example.com -p
```

Do not perform DNS writes from CI. Do not include `/etc/dnsapi/config.json`, local `config.json`, API tokens, journal files, or Certbot runtime data in commits.

Run `php8.4 tests/hooks.php` and `php8.5 tests/hooks.php` for DNS, journal, and hook changes. These tests use synthetic DNS/provider responses and temporary local files; the deadline test uses only a loopback HTTP socket. No real DNS writes or API credentials are needed. Record live staging/renewal verification separately.

## Releases

Update `VERSION` and `CHANGELOG.md` together. Use a SemVer tag with a `v` prefix, such as `v0.1.0`. The release workflow checks that the tag exactly matches `VERSION` before publishing an archive.

Run `php8.4 tools/verify.php` and `php8.5 tools/verify.php` for the complete offline suite. Add provider protocol cases to the loopback router, not production endpoints. New shared code belongs under `src/`; root loaders must remain compatible. Never treat a successful API response as proof of DNS propagation.

Integrate changes into `main` before tagging. The release workflow verifies `main` ancestry, builds one archive, tests its unpacked contents on both PHP versions and publishes those same bytes with `SHA256SUMS`. `X.Y.Z-rc.N` tags (N >= 1) are prereleases and never Latest. Do not move existing release tags. Stable tags require a completed provider acceptance record and separate publication approval; see `docs/release-acceptance.md`.

Keep only actual CLI/hooks executable in Git (100755); includes, tests and the HTTP entry point remain 100644. If a checkout has `core.filemode=false`, inspect modes with `git -c core.filemode=true diff --summary` and preserve them when staging. Archive checks fail on wrong modes rather than repairing the package silently.
