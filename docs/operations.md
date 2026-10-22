# Installation, Upgrade and Release

## Local Validation

```sh
php8.4 tools/verify.php
php8.5 tools/verify.php
git diff --check
```

No provider credentials or external DNS calls are used by these tests. The HTTP
contract suite starts a temporary server bound only to `127.0.0.1` and terminates
it afterwards. Fixtures never use production configuration. PHP cURL and OpenSSL
are required; release verification also needs zlib. Certbot DNS verification
additionally uses `dig`. See `release-acceptance.md` for exact-archive checks.

## Upgrade

Stop the Certbot scheduler and wait for the entire Certbot invocation, not just
the currently executing hook, before switching code versions. Reconcile interrupted
runs with recovery; do not resume their challenge sequence across an upgrade.
Install the entire archive,
including `src/`, as one versioned directory; never mix old and new include files.
Keep `/etc/dnsapi/config.json` and `/var/cache/dnsapi` outside the source directory.
Back up configuration and journals privately before upgrading. Do not delete or
reset a pending journal to make an upgrade pass.

Validate a private copy of configuration with `DNSAPI_CONFIG_FILE=...` and
`./dnsapi-interface.php --config-check`; this does not contact providers.
Review a read-only `-p --json` inventory and `--dry-run` plan before any approved
write test. A Certbot staging run requires explicit write approval even though
the issued certificate is not production-trusted.

Executable entry points retain `/usr/bin/php` and are shipped as 0755; include
files are 0644. Verify archive modes rather than making every PHP file executable.
Do not publish the complete
repository as a web document root. Expose only `index.php` through an explicit
webserver location and isolate its OS account/permissions from Certbot.

DynDNS uses `dyn-cache-v2.json`; the old cache is not migrated or deleted. Cache
contents are disposable only while the HTTP service is stopped; Certbot journals
are not disposable. Restrict configuration/state directories to their service
users, avoid root-run web handlers, and redact DynDNS query secrets from logs.

Compatibility changes: CLI inventory is normalized, unknown/malformed config
fails early, ambiguous RRSet changes fail, repeated batch targets require separate
calls, and TLSA publication is additive. Review scripts that relied on raw provider
arrays, implicit replacement or automatic TLSA removal.

## Release Discipline

Integrate the product branch and previous release work into `main` via a reviewed
PR before creating another tag. The historic release branch and `main` diverged;
do not solve that with force-pushes or moving published tags. Run checks on the
merged result. The workflow now rejects tags outside `main` ancestry.

Update `VERSION` and `CHANGELOG.md` together, then tag that exact commit only after
publication approval. Versions accept `X.Y.Z` and `X.Y.Z-rc.N` (N starts at 1,
no leading zeroes). RCs are GitHub prereleases with `--latest=false`; a stable
tag requires the completed live acceptance record and explicit operator sign-off.
The workflow does not manufacture or certify live acceptance automatically.

CI produces one archive and tests its unpacked contents on PHP 8.4/8.5, including
upgrade/rollback against the pinned v0.2.1 commit. The publish job downloads that
same artifact and verifies its checksums; it never rebuilds it. Archives include only Git-tracked files;
the path guard rejects runtime configuration, journals, logs and key/certificate
files. It is not a comprehensive secret scanner: inspect staged diffs separately.
`SHA256SUMS` verifies download integrity, not publisher identity or a signature.
The archive verifier executes included PHP tests: use it only for trusted project
archives. It is not a sandbox or a substitute for reviewing code and credentials.

## Recovery and Rollback

On write failure inspect actual provider inventory before retrying. Multiple
operations are not a transaction; earlier changes may already have succeeded.
For Certbot cleanup use the recovery plan/apply flow in `certbot.md` and preserve
ownership metadata. Roll back code as a complete tree, not individual scripts.
Journal format remains version 2; do not roll back to versions unable to read it.
For TLSA, follow `tlsa.md` and ensure the rollback key is still valid in DNS.

Do not infer successful renewal solely from Certbot's exit code. Check hook
diagnostics as well. Monitor certificate expiry, failed cleanup/recovery entries
and nonzero operational command results independently.
