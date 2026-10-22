# Architecture and Contracts

## Layout

Root scripts remain executable interfaces using `/usr/bin/php`; root include
files load their replacements under `src/`. No Composer install is required.
`src/bootstrap.php` loads the single provider interface, runtime and configuration.
`src/Provider` owns API translation, `src/Dns` owns normalized records and DNS
observation, `src/Certbot` owns challenge batches, `src/Tlsa` owns certificate
interpretation, and `src/Runtime` owns configuration, deadlines and local state.

## Mutation Semantics

The shared record service distinguishes additive creation, exact deletion,
unambiguous update/upsert, and explicitly requested RRSet replacement. Plans
include before/after values and re-read inventory immediately before applying.
A changed inventory aborts. This is not a provider transaction or distributed
lock: another actor can still race after that read. Serialize administrative
writes and avoid concurrent writers to the same RRSet.

Failures return nonzero exit codes and sanitized stable codes, not raw provider
responses. Bulk calls can partially succeed across RRSets/zones; inspect inventory
before retrying. Do not blindly retry writes after a timeout.

Provider adapters retain the old `DNSAPIv1` methods for compatibility. Application
code should use the record service, not assume that provider-native IDs or record
arrays are interchangeable. Netcup IDs identify individual records; Cloud IDs
identify entire RRSets. Netcup Legacy TTL is zone-level, not a record guarantee.

## Verification

`tests/hooks.php` covers challenge ownership, propagation, journal and recovery.
`tests/product.php` covers safety boundaries for CLI, TLSA, HTTP and packaging.
`tests/providers.php` runs actual adapters against a loopback HTTP fixture to
check request bodies, IDs, pagination, asynchronous actions and exact deletion.
`DNSAPIHttpTransport` provides an injectable transport boundary; production uses
cURL. There is no environment switch that bypasses provider URL validation.

The mail check reports DNS inventory, not full deliverability or compliance.
DKIM needs an explicit selector; TLSA needs a concrete mail host. Missing inputs
are reported as skipped. The AD flag is an assertion by the selected resolver,
not local DNSSEC validation. MTA-STS HTTPS retrieval, SPF mechanism evaluation,
DKIM signature validation and live TLS key comparison are outside this command.

## Netcup CloudDNS Assessment

The existing `Netcup` adapter uses the legacy CCP API. Netcup now documents a
separate API for CloudDNS domains with separately generated API keys:
[official introduction](https://www.netcup.com/en/helpcenter/documentation/general/netcup-api)
and [API reference](https://www.netcup.com/en/helpcenter/documentation/netcup-api).
Do not point the legacy adapter at that endpoint or migrate live domains implicitly.

A separate `NetcupCloud` adapter is a follow-up integration, not currently shipped.
Before enabling it, capture the current request/response schemas, add the same
contract cases for record identity, exact TXT removal, pagination and TTL scope,
and verify against an explicitly authorized disposable CloudDNS zone. The current
offline Netcup tests do not establish CloudDNS compatibility.
