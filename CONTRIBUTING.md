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
