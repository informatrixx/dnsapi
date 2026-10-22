# Certbot Operation and Recovery

## Upgrade and Dependencies

PHP 8.4/8.5 with cURL, `dig` (BIND), and Linux are supported. The executable
shebang remains `/usr/bin/php`. Install the full repository, including the new
`dnsapi-*.inc.php` compatibility loaders and the complete `src/` directory, in
`/var/lib/dnsapi`; do not replace only the hooks.
Stop active Certbot/hook processes before upgrading: old processes do not use
the new stable journal lock. Auth/cleanup require no additional PHP extension;
the TLSA hook requires PHP OpenSSL rather than a shell pipeline.

All entry points, including HTTP, CLI and TLSA, support `DNSAPI_CONFIG_FILE` (default
`/etc/dnsapi/config.json`). Auth, cleanup, doctor and recovery retain `DNSAPI_JOURNAL_FILE` (default
`/var/cache/dnsapi/journal.json`). Give each independent instance its own journal.
Use the same environment and operating-system user for auth, cleanup and recovery.
New journal directories use 0700; files and the stable `.lock` file use 0600.

## Configuration

Optional top-level configuration, shown with defaults:

```json
{
  "certbot": {
    "propagation_mode": "strict",
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
New batches record the expected operation count and reject missing or unconfirmed
entries before the next mutation or final verification. Interrupted older batches
without that count require recovery; do not resume them across a code upgrade.
Existing Cloud challenge RRSet TTLs are retained, including `null` (zone inheritance).
Only new challenge RRSets request TTL 120; Netcup keeps its zone-level TTL.

## Verification and Diagnostics

`propagation_mode` accepts only `strict` (the default) or `recursive`. There is
no automatic fallback between them. Unknown values fail before DNS writes.

In `strict` mode every authoritative nameserver must confirm the exact TXT value with AA set;
every configured recursive resolver must also confirm it. For multiple IPs of
one nameserver, at least one must confirm; contradictory reachable answers block
success. An unreachable IPv6 address alone does not defeat successful IPv4.
CNAMEs and delegated challenge names are rejected by the strict authoritative preflight
before mutation. There is no automatic weaker verification mode.

Use `recursive` explicitly for networks where direct authoritative queries are
unavailable. This mode makes no authoritative or NS-discovery DNS calls in the
hooks. It still requires the provider's active record and the exact challenge
value at **every** configured recursive resolver, within the same deadline.
Old TXT values, partial resolver success and elapsed time alone never suffice.
All challenges are created first and verified together by the last hook.

Before writing, recursive mode checks the provider inventory for CNAME at the
challenge owner, NS delegation below the configured zone along the name's path,
and DNAME on that path. These are rejected; ordinary zone-apex NS records are
allowed. No recursive query of the new challenge name is made before creation,
avoiding unnecessary negative cache entries. Administrators must verify actual
public delegation and zone mapping; inventory checks cannot prove these remotely.

This mode gives up direct authority confirmation, not ACME validation. Multiple
resolver IPs need not be independent if the network redirects DNS. Let's Encrypt
still checks the challenge itself. Repairing the network remains a separate task.
Certbot can continue ACME validation after a failed manual auth hook. Therefore
its successful exit or an issued certificate alone does not prove that the local
resolver policy passed. Check auth-hook errors as well as the Certbot result;
DNSAPI does not forcibly terminate the parent Certbot process on hook failure.
To enable the mode, add `"propagation_mode": "recursive"` inside the existing
`certbot` configuration object. Keep a separate configuration for initial tests.

All groups are queried per round within the deadline, even when one group fails.
Diagnostics include RCODE, AA/RA/RD, target IP, transport, duration, matching state
and SOA-derived negative TTL. Long negative TTLs are reported, not used alone to
abort. TXT-TTL does not bound negative caching. These checks neither prove global
visibility nor identify network interception. Inspect the target network separately.

```sh
./dnsapi-doctor.php --domain example.com
./dnsapi-doctor.php --domain mail.example.com --zone example.com
./dnsapi-doctor.php --domain example.com --authorities
```

Doctor performs DNS reads only, without provider calls. Without
`DNSAPI_EXPECTED_TXT` it diagnoses DNS paths, not challenge propagation. Set that
environment variable privately to check a specific value; it is never printed.
Output identifies the configured mode. In recursive mode `--authorities` adds
optional direct-authority diagnostics with a separate 60-second budget; their
failure does not change the recursive check's exit status. Without that flag,
recursive-mode Doctor does not discover or query authorities.
Avoid querying nonexistent challenges through recursive resolvers immediately
before issuance: this can create negative cache entries. Normal auth preflight
queries challenge names only at authoritative IPs in strict mode; recursive
mode uses only provider inventory for preflight.

Diagnostics use stderr (doctor uses stdout JSON) without raw answers or TXT values.
Process failures distinguish `NETWORK_UNREACHABLE`, `NO_ROUTE`,
`CONNECTION_REFUSED`, `PERMISSION_DENIED`, `TIMEOUT` and `NO_RESPONSE`.
DNS `REFUSED` is a received DNS response, not a refused TCP connection.
An absent route or a local permission error is not retried over TCP. A successful
IPv4 authority check still works when the same nameserver's IPv6 is unroutable.
The old `authenticator-debug.log` is no longer appended. If redirecting stderr,
protect and rotate that external log with the service's logging facilities.

### Investigating REFUSED Responses

Compare the same zone SOA query from the affected container, its host and an
independent network. Use the IPs discovered for that zone, not unrelated resolvers:

```sh
dig -r @AUTHORITY_IP example.com. SOA +norecurse +notcp +ignore +time=3 +tries=1
dig -r @AUTHORITY_IP example.com. SOA +norecurse +tcp +time=3 +tries=1
dig -r @AUTHORITY_IP example.com. SOA +recurse +tcp +time=3 +tries=1
```

A DNS response advertising recursion without AA does not confirm authority.
Recursive answers from the addressed path can indicate a forwarding/interception
path or an unexpected server configuration; the flags alone do not locate its
cause. Inspect outbound TCP/UDP port 53 policies and DNS redirects on the host,
hypervisor and upstream gateway. Changing `/etc/resolv.conf` cannot repair an
explicit `@IP` query that is intercepted downstream. Never count recursive
responses as authoritative to hide this failure.

On the development host on 2026-09-09, direct IPv4 SOA queries to all three
discovered Hetzner authority addresses returned `REFUSED` without AA and with RA.
A recursive query to the same addressed path returned answers for another domain.
Local nftables inspection found no matching outbound DNS redirect; IPv6 routing
reported network unreachable. The failing external DNS path remains unresolved;
no gateway or network policies were modified. The staging attempt created no
challenge or certificate. This is a host-specific observation, not a general
claim about Hetzner's nameservers.

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

### Recursive-Mode Live Verification (2026-09-09)

An isolated `mocu.at` staging test used all three default resolvers. The first
issuance obtained a staging certificate, but its auth hook timed out on a negative
Quad9 cache response; this was not a successful local-policy check. The subsequent
renewal dry-run succeeded with fresh challenge creation, exact matches at all three
resolvers and no hook errors. Provider reads confirmed removal of both test
challenge values, and the test journal was empty. Production configuration and
certificates were not changed. These results validate this test environment, not
global DNS visibility or future cache behavior.
