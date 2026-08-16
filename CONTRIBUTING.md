# Contributing

## Workflow

Create a focused branch from `main`, for example `agent/fix-netcup-dns`. Keep provider behavior, Certbot hooks, and repository infrastructure in separate changes where practical.

Use short imperative commit subjects, such as `Fix DNS propagation handling for Certbot`. Pull requests should explain the affected flow, provider-specific risk, validation commands, and any untested integration paths.

## Validation

Run PHP syntax checks for every changed file and use `git diff --check`. Provider smoke tests must use an approved test zone or read-only commands such as:

```sh
./dnsapi-interface.php -x example.com -p
```

Do not perform DNS writes from CI. Do not include `/etc/dnsapi/config.json`, local `config.json`, API tokens, journal files, or Certbot runtime data in commits.

## Releases

Update `VERSION` and `CHANGELOG.md` together. Use a SemVer tag with a `v` prefix, such as `v0.1.0`. The release workflow checks that the tag exactly matches `VERSION` before publishing an archive.
