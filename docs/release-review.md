# Local Review: 0.3.0-rc.1

Review date: 2026-10-22. Branch: `agent/product-hardening`. At the initial review,
the changes were local and uncommitted. This is a bounded review of mutation safety, hooks and release
packaging, not an independent security certification or production approval.

## Findings Addressed

| Finding | Correction and regression coverage |
| --- | --- |
| Certbot always requested TTL 120 on existing Cloud RRSets | Retain explicit or inherited TTL; hook and actual-adapter fixtures cover both |
| Missing batch entries could leave a smaller final verification set | Persist expected operation count; reject incomplete/unconfirmed batches before new writes or final success |
| Cloud creation could return the intended payload without finding it after the action | Require the actual record in the refreshed inventory |
| Incomplete Cloud pagination/record data could undermine absence checks | Validate complete pagination and record shapes; fail closed |
| Netcup delete acknowledged success without checking absence or value/ID agreement | Refresh inventory, validate exact selector, confirm deletion; retained-value regression |
| Netcup MX compatibility records could duplicate priority or fail exact raw-record deletion | Normalize priority once and test create/read/delete round trips |
| Netcup accepted a per-record TTL field despite zone-level TTL semantics | Do not send unsupported TTL; retain explicit caller warning |
| Recovery/Doctor accepted unknown CLI options | Strict argument parsing before configuration, journal or network side effects |
| Executable scripts lacked executable Git modes | Explicit CLI/hook modes; archive verification rejects wrong modes rather than repairing them |
| Source checks alone did not verify the delivered package | Test unpacked archive on both PHP versions, including previous-version recovery |

## Verification Boundary

The source suites and archive verification use PHP 8.4/8.5, synthetic providers,
loopback HTTP and private temporary state only. Provider fixtures cover writes
followed by response failures, incomplete replies and asynchronous timeouts.
Hook fixtures verify exact reconciliation or retention of the prepared intent
when cleanup also fails. No live DNS records were read or changed for these checks.

The upgrade suite executes both the actual pinned v0.2.1 tree and the candidate
against the same synthetic external state. It covers forward/backward journal
reads, locks, exact cleanup, repeated recovery and corrupt-state preservation.
This proves the tested journal/recovery paths, not full behavioral equivalence of
old CLI/TLSA semantics or a production upgrade while Certbot is running.

The initial local archive output identified `source_commit: null` for an
uncommitted-tree snapshot; it must not be represented as a tested release commit. Exact digests
and runtime results are retained locally under ignored `dist/`. Repeat CI and
archive checks after the eventual reviewed merge to `main`.

## Remaining Gates and Risks

- At this review, GitHub CI, merge, live Hetzner Cloud/Netcup acceptance and
  publication had not run. Track subsequent commit-specific CI results in the PR;
  they do not replace live acceptance or publication approval.
- Provider operations are not cross-record transactions; a failure can follow a
  completed write. Inspect inventory rather than assuming rollback or retrying blindly.
- Local locks do not coordinate independent hosts or external DNS writers.
- Interrupted batches must be recovered before changing code versions; do not
  continue an old in-flight Certbot sequence with the new code.
- TLSA publication alone does not wait out caches, validate DNSSEC or activate
  service certificates. Production rollover remains an explicit operational task.
- The legacy Hetzner adapter has no equivalent new live acceptance or complete
  wire-test matrix. Netcup CloudDNS is not implemented.
- Archive path checks and SHA256SUMS are not a complete secret scan or a release
  signature. Review the eventual staged diff and trust the code before running tests.

Use [Release Acceptance](release-acceptance.md) for the remaining gated steps.
