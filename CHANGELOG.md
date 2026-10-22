# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [0.3.0-rc.1] - Unreleased

Release candidate in preparation. Not a stable production release; live provider
acceptance and publication are separate approvals.

### Added

- Shared runtime/configuration, provider contract, normalized record plans and replaceable HTTP transport under `src/`; legacy entry points remain available.
- CLI JSON output, structured record arguments, read-only dry-run and offline configuration validation; credential-free example configuration.
- Read-only mail DNS inventory and explicit TLSA publish/retire operations with a rollover runbook.
- Product regressions and loopback HTTP contract tests; reusable PHP 8.4/8.5 checks for branches and release tags, runtime-artifact filtering and SHA256SUMS.
- Exact-archive verification, pinned v0.2.1 upgrade/rollback tests and a provider staging acceptance runbook. CI tests one archive on both PHP versions and publishes the same bytes; RC tags are prereleases, never Latest.

### Fixed

- Refuse TLSA generation from missing or malformed certificates instead of hashing empty pipeline output.
- Netcup upserts carry existing IDs, reject ambiguous targets and use the session ID for logout; bulk replacement preserves unrelated records.
- Preserve IPv6/SPF values in CLI parsing and return nonzero errors; reject stale or ambiguous mutation plans.
- Isolate DynDNS caches per provider/zone/name/type, authenticate before writes, validate IP addresses and atomically preserve cache data.
- Correct release runtime-file filtering and prevent publication from a tag not integrated into `main`.
- Preserve existing and inherited Hetzner Cloud challenge TTLs; reject incomplete challenge batches and unconfirmed record creation/deletion.
- Fail closed on incomplete provider inventory; reject unknown Doctor/Recovery options before side effects and retain uncertain writes for reconciliation.
- Reject invalid legacy Hetzner inventory JSON, record lists and fields instead of confirming false absence and discarding recovery intents.
- Serialize long Hetzner Cloud TXT/DKIM contents as escaped character strings of at most 255 octets, independently of semantic value comparison.
- Match multipart TXT values by decoded content and send their original provider representation for exact deletion, preserving sibling records and comments.

### Changed

- TLSA publication is additive; removal requires an exact old value and explicit rollover confirmation. Service activation and TTL waiting remain operator responsibilities.
- Deploy the complete source tree including `src/`. All entry points accept `DNSAPI_CONFIG_FILE`; legacy Netcup TTL remains zone-level.
- CLI print output is normalized. Ambiguous mutations and duplicate targets within a batch now fail explicitly instead of guessing.
- Executable CLI/hooks carry executable file modes; includes do not. Interrupted batches from older versions require recovery rather than resuming across an upgrade.

## [0.2.1] - 2026-09-09

### Added

- Explicit `certbot.propagation_mode` (`strict` by default, or `recursive`) for resolver-based verification without direct authoritative DNS access; no automatic fallback.
- Recursive preflight checks provider records for CNAME, NS delegation and DNAME; Doctor supports optional non-blocking authority diagnostics via `--authorities`.

### Fixed

- Distinguish unroutable networks, refused connections, permission errors and missing responses from actual DNS timeouts; classify stderr without logging raw input.
- Skip ineffective TCP retries when an IP has no route or access is denied, while retaining strict authoritative verification.

## [0.2.0] - 2026-09-09

Withdrawn from public GitHub Releases in favor of 0.2.1: direct authoritative
DNS verification cannot work on some restricted network paths. The Git tag is
retained for traceability; use 0.2.1 with an explicitly selected propagation mode.

### Added

- Structured DNS diagnostics and read-only `dnsapi-doctor.php`, explicit discovery resolver, authoritative AA checks and bounded TCP fallback.
- Atomic version-2 journal with stable locking, pre-mutation intents, operation/run IDs and explicit `dnsapi-recovery.php` plan/apply commands.
- Offline DNS, journal, cleanup and hook subprocess tests for PHP 8.4 and 8.5.

### Changed

- Certbot hooks share configuration, DNS and journal helpers; install the complete source tree when upgrading.
- Propagation uses a monotonic deadline including provider/DNS calls. Cleanup has a separate 60-second budget.
- CNAME/delegated challenges and overlapping runs for the same identifier are rejected before writes. Legacy journal ownership requires explicit recovery adoption.

### Fixed

- Preserve malformed journal data, reject unsafe cleanup without a value, and treat confirmed absent values as already cleaned up.
- Keep provider response bodies out of Cloud errors and challenge values out of hook diagnostics.
- Correct the README Cloud URL and provider filename casing; declare Netcup record storage for PHP 8.4/8.5.

## [0.1.1] - 2026-09-09

Withdrawn from public GitHub Releases in favor of 0.2.1, which includes subsequent
journal, propagation and diagnostic fixes. The Git tag is retained for traceability.

### Changed

- Support PHP 8.4 and 8.5 in CI and documentation; CLI and Certbot executable scripts retain the generic `/usr/bin/php` interpreter.

### Fixed

- Remove deprecated `curl_close()` usage in HetznerCloud and declare nullable record types explicitly in Hetzner and Netcup adapters.

## [0.1.0] - 2026-08-16

### Added

- Initial versioned baseline for the dynamic DNS CLI, HTTP endpoint, provider adapters, and Certbot hooks.
- Automated PHP syntax checks and tag-based GitHub release packaging.
