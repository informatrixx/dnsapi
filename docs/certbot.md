# Certbot Operation and Recovery

## Upgrade and Dependencies

PHP 8.4/8.5 with cURL, `dig` (BIND), and Linux are supported. The executable
shebang remains `/usr/bin/php`. Install the full repository, including the new
`dnsapi-*.inc.php` helpers, in `/var/lib/dnsapi`; do not replace only the hooks.
Stop active Certbot/hook processes before upgrading: old processes do not use
the new stable journal lock. No new PHP extension is required.

The HTTP and TLSA tools keep their existing configuration paths. Auth, cleanup,
doctor and recovery support `DNSAPI_CONFIG_FILE` (default
`/etc/dnsapi/config.json`) and `DNSAPI_JOURNAL_FILE` (default
`/var/cache/dnsapi/journal.json`). Give each independent instance its own journal.
Use the same environment and operating-system user for auth, cleanup and recovery.
New journal directories use 0700; files and the stable `.lock` file use 0600.

## Configuration

Optional top-level configuration, shown with defaults:

```json
{
  "certbot": {
    "propagation_timeout_seconds": 1200,
    "dns_timeout_seconds": 3,
    "poll_interval_seconds": 10,
    "discovery_resolver": "1.1.1.1",
    "resolvers": ["1.1.1.1", "8.8.8.8", "9.9.9.9"]
  }
}
```

Time values must be positive integers; maxima are 7200, 30 and 300 seconds,
respectively. Resolver settings require IP literals (up to ten public-check
resolvers). NS discovery and A/AAAA resolution use only the discovery resolver.
Provider URLs must be the documented HTTPS endpoints; proxy endpoints are not
supported by these hooks. Administrators must verify domain-to-zone mappings.

The budget starts at each auth-hook invocation and includes its creation and
verification work, including API pagination, DNS subprocesses and sleep. Earlier
hooks return after creation; the last hook verifies the complete batch. Error
cleanup has a separate 60-second budget, so total hook runtime may exceed the
propagation budget by that amount. Cleanup failures retain recovery entries.

Certbot process identity is derived from its Linux `/proc` ancestry. If unavailable
(for example, a custom wrapper), export a unique `DNSAPI_RUN_ID` once before
starting Certbot and preserve it across that invocation's hooks. Do not generate
a separate ID inside each hook. Batches use Certbot's remaining-challenge counter,
not the number of unique domains. Apex and wildcard values remain separate.

## Verification and Diagnostics

Every authoritative nameserver must confirm the exact TXT value with AA set;
every configured recursive resolver must also confirm it. For multiple IPs of
one nameserver, at least one must confirm; contradictory reachable answers block
success. An unreachable IPv6 address alone does not defeat successful IPv4.
CNAMEs and delegated challenge names are rejected by the authoritative preflight
before mutation. There is no automatic weaker verification mode.

All groups are queried per round within the deadline, even when one group fails.
Diagnostics include RCODE, AA/RA/RD, target IP, transport, duration, matching state
and SOA-derived negative TTL. Long negative TTLs are reported, not used alone to
abort. TXT-TTL does not bound negative caching. These checks neither prove global
visibility nor identify network interception. Inspect the target network separately.

```sh
./dnsapi-doctor.php --domain example.com
./dnsapi-doctor.php --domain mail.example.com --zone example.com
```

Doctor performs DNS reads only, without provider calls. Without
`DNSAPI_EXPECTED_TXT` it diagnoses DNS paths, not challenge propagation. Set that
environment variable privately to check a specific value; it is never printed.
Avoid querying nonexistent challenges through recursive resolvers immediately
before issuance: this can create negative cache entries. Normal auth preflight
queries challenge names only at authoritative IPs.

Diagnostics use stderr (doctor uses stdout JSON) without raw answers or TXT values.
The old `authenticator-debug.log` is no longer appended. If redirecting stderr,
protect and rotate that external log with the service's logging facilities.

## Recovery

```sh
./dnsapi-recovery.php
./dnsapi-recovery.php --run RUN_ID
./dnsapi-recovery.php --run RUN_ID --apply
```

The first two commands show a plan and do not call providers or delete records.
They acquire/create the local journal lock. Stop the associated Certbot process
before applying recovery, including processes between hooks. `--apply` removes
only the exact journaled challenge values owned by that run. Reused pre-existing
values are retained. A confirmed absent value counts as success; API failures
and incomplete entries remain in the journal. Automatic retries never delete a
whole RRSet for a malformed entry.

Existing unversioned journals are read without dropping entries. Their ownership
is unknown, so normal cleanup retains them. Review them privately and, only when
ownership is established, use `--run legacy --adopt-legacy --apply`. Invalid or
unsupported JSON is rejected and left unchanged; repair it from a trusted backup.

The journal writes an intent before mutation and atomically replaces its file
after flushing/fsync. A stable separate lock serializes hook operations; active
overlapping batches for one identifier are rejected. Crash recovery can reconcile
an intent even when the provider response never reached the journal. This does
not coordinate independent hosts sharing a DNS zone or external writers.

## Scope and Remaining Work

Implemented feedback: structured DNS results, explicit discovery and authority
checks, deadlines, exact idempotent cleanup, protected/versioned atomic journals,
intent recovery, path overrides, safe hook errors, offline behavior tests and
corrected configuration examples.

Deferred: parallel DNS queries, full delegation/CNAME following, resolver quorum
modes, automatic HTTP-429/Retry-After scheduling, automatic signal cleanup and
built-in log rotation. Handled PHP failures attempt cleanup; SIGKILL, termination
and crashes require the recovery command. File fsync and atomic rename do not
promise power-loss durability of directory metadata on every filesystem.

The source feedback is retained in [docs/feedback](feedback/2026-09-09_10-28-48_UTC_DNSAPI_Feedback_naechste_Version.md).
Before production rollout, run staging issuance and renewal dry-run on an approved
zone in the target environment. Offline fixtures cannot validate that network path.
