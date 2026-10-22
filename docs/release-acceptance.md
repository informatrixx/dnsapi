# Release Acceptance

## Gates and Evidence

The next candidate is `0.3.0-rc.1`, not a stable production release. Keep separate
results for source tests, packaged tests, upgrade/rollback, GitHub CI and live
provider acceptance. A passed local check never implies publication or live success.
The bounded local review is recorded in [release-review.md](release-review.md).

Record the version, source commit (or explicitly an uncommitted tree), archive
SHA256, PHP version, environment, date, test case, result and remaining risks.
Keep private inventories/logs outside Git with 0700 directories and 0600 files.
Only publish sanitized summaries; omit credentials, challenge values, account
details and certificate private keys. `SHA256SUMS` is not a publisher signature.

Required gates before an RC: reviewed diff with no unresolved destructive-write
or credential-leak findings, both PHP matrices green, executable archive and
successful offline upgrade/rollback. Before stable `0.3.0`, additionally require
both Hetzner Cloud and Netcup live checks below, reviewed compatibility changes,
and separate operator publication approval. Never move an existing tag. Functional
changes after an RC require a new RC and renewed relevant acceptance.

## Offline Archive Check

Only run archives from trusted, reviewed project sources: this command executes
the included PHP tests and is not an untrusted-code sandbox. PHP cURL, OpenSSL,
zlib and Linux are required. No provider credentials are required.

After an approved commit, build the candidate and the pinned baseline locally:

```sh
mkdir -p dist
VERSION="$(tr -d '\n' < VERSION)"
git -c tar.umask=0022 archive --format=tar.gz --prefix="dnsapi-$VERSION/" HEAD > "dist/dnsapi-$VERSION.tar.gz"
git -c tar.umask=0022 archive --format=tar.gz --prefix=dnsapi-0.2.1/ 1a4a08f1f55e8315fa836f142266b7f88550c0f6 > dist/dnsapi-0.2.1.tar.gz
php8.4 tools/verify-release.php --archive "dist/dnsapi-$VERSION.tar.gz" --version "$VERSION" --baseline-archive dist/dnsapi-0.2.1.tar.gz
php8.5 tools/verify-release.php --archive "dist/dnsapi-$VERSION.tar.gz" --version "$VERSION" --baseline-archive dist/dnsapi-0.2.1.tar.gz
```

`git archive HEAD` does not include uncommitted changes. Do not use it to claim
acceptance of an uncommitted working tree. CI always uses a committed tree.
The verifier rejects path traversal, duplicate entries, links, unexpected TAR
extensions, unsafe permissions, runtime files, missing implementation and version
mismatches. Exit 0 means the checks it reports passed, not live acceptance.
Omitting `--baseline-archive` reports `upgrade_rollback: not_run` and is insufficient
for release acceptance. The final JSON includes the archive digest and source
commit; tree-only archives report no commit.

The baseline test runs the actual pinned v0.2.1 and candidate entry points with
synthetic providers and external temporary state. It checks pending-journal reads,
stable locking, exact cleanup, repeated recovery and corrupt-journal preservation
in both directions. It never overwrites the host installation. It does not prove
that old CLI output or TLSA semantics are interchangeable with the new version.

CI builds one candidate archive, tests it on PHP 8.4/8.5 and hands that same
artifact to publication. RC tags use `--prerelease --latest=false`. Stable tagging
is an operator gate; the workflow does not infer live approval from offline CI.

## Live Certbot Acceptance: Separate Write Approval

Run once for Hetzner Cloud and once for legacy Netcup on the intended deployment
network. Obtain explicit approval for the exact test names and record types first.
Past approval for `mocu.at`, `thinkwww.at` or a Netcup domain is not a blanket
authorization for new tests. Stop competing renewals/writers for these names.

Prepare a private minimal configuration containing only the approved zone and its
credentials, without production TLSA mappings. Select `strict` unless the known
network restriction requires explicitly reviewed `recursive` mode. Never silently
weaken policy after a failed check. The test name should be a disposable subdomain
of the approved zone, not an existing production certificate name.

Set these operator-supplied values in a dedicated Bash session. `APP` points to
the complete extracted candidate archive, not a mix of installed files:

```sh
set -euo pipefail
umask 077
: "${APP:?candidate directory required}"
: "${TEST_ZONE:?approved configured zone required}"
: "${TEST_NAME:?approved disposable FQDN required}"
: "${DNSAPI_CONFIG_FILE:?private test configuration required}"
: "${ACME_EMAIL:?staging account email required}"
export DNSAPI_CONFIG_FILE
PHP_BIN="$(command -v php8.5)"
"$PHP_BIN" -v
"$PHP_BIN" "$APP/dnsapi-interface.php" --config-check
RUN_DIR="$(mktemp -d /var/tmp/dnsapi-staging.XXXXXXXX)"
export DNSAPI_JOURNAL_FILE="$RUN_DIR/journal.json"
export DNSAPI_RUN_ID="staging-$(openssl rand -hex 16)"
"$PHP_BIN" "$APP/dnsapi-interface.php" -x "$TEST_ZONE" -p --json > "$RUN_DIR/before.json"
```

Review the inventory privately. Do not make recursive queries for a nonexistent
challenge immediately before issuance, because that can prime negative caches.
The following command creates real DNS TXT records despite using an ACME staging
server. The approval variable is only an operator safety interlock, not permission
to act without authorization:

```sh
test "${DNSAPI_LIVE_TEST_APPROVED:-no}" = yes
certbot certonly --staging --manual --preferred-challenges dns \
  --non-interactive --agree-tos --email "$ACME_EMAIL" \
  --config-dir "$RUN_DIR/config" --work-dir "$RUN_DIR/work" --logs-dir "$RUN_DIR/logs" \
  --no-directory-hooks --cert-name dnsapi-rc-test \
  --manual-auth-hook "$PHP_BIN $APP/certbot-dnsapi-authenticator.php" \
  --manual-cleanup-hook "$PHP_BIN $APP/certbot-dnsapi-cleanup.php" \
  -d "$TEST_NAME" -d "*.$TEST_NAME" > "$RUN_DIR/issuance.txt" 2>&1
```

Do not install this untrusted staging certificate or reload any service. Review
the hook diagnostics even if Certbot exits successfully. Require two separate
challenge values, batch verification by the last hook, exact matches for every
configured resolver (and authorities in strict mode), and no hook errors.
If the CA reused authorizations and no fresh challenges ran, mark that case
`not exercised`, not passed. Compare propagation duration to actual diagnostics;
elapsed time alone is not a success criterion and no check proves global visibility.

Run an isolated renewal using the configuration just created, not the production
renewal directory. Do not enable deploy hooks:

```sh
test "${DNSAPI_LIVE_TEST_APPROVED:-no}" = yes
export DNSAPI_RUN_ID="staging-$(openssl rand -hex 16)"
certbot renew --dry-run --cert-name dnsapi-rc-test \
  --config-dir "$RUN_DIR/config" --work-dir "$RUN_DIR/work" --logs-dir "$RUN_DIR/logs" \
  --no-directory-hooks > "$RUN_DIR/renewal.txt" 2>&1
"$PHP_BIN" "$APP/dnsapi-recovery.php" > "$RUN_DIR/recovery-plan.txt"
"$PHP_BIN" "$APP/dnsapi-interface.php" -x "$TEST_ZONE" -p --json > "$RUN_DIR/after.json"
jq -S 'sort_by(.name,.type,.value,.id)' "$RUN_DIR/before.json" > "$RUN_DIR/before.sorted.json"
jq -S 'sort_by(.name,.type,.value,.id)' "$RUN_DIR/after.json" > "$RUN_DIR/after.sorted.json"
cmp "$RUN_DIR/before.sorted.json" "$RUN_DIR/after.sorted.json"
test ! -s "$RUN_DIR/recovery-plan.txt"
```

Successful renewal must show fresh exact verification, no hook errors, an empty
recovery plan and unchanged pre-existing DNS inventory. If a command fails, keep
the private state and logs, stop the associated Certbot invocation, inspect the
recovery plan and request/confirm approval before applying recovery. Never delete
the journal or all `_acme-challenge` values to make the test look clean.

## TLSA and Deployment Boundary

Certbot `--dry-run` does not execute deploy hooks by default; with
`--run-deploy-hooks` it uses the active certificate, not the temporary test one.
See the [official Certbot documentation](https://eff-certbot.readthedocs.io/en/stable/using.html).
Thus the Certbot result above is not a TLSA rollover acceptance.

For a separately approved TLSA smoke test, use only an unused `_25._tcp.TEST_NAME`
owner and a separate test-only TLSA mapping. Generate two temporary certificates
locally, publish their two distinct SPKI hashes additively, verify both values,
then retire only the first hash with the second certificate as the current hash
source. No service is activated. Finally delete only the second test value with
the record CLI and confirm the original inventory. Record the TTL actually used,
including Netcup's zone TTL and Cloud's inherited TTL. Do not test this sequence
on a production mail host. The full production procedure remains in `tlsa.md`.

## Acceptance Record Template

Store a sanitized report containing:

| Field | Required evidence |
| --- | --- |
| Identity | Version, commit/tree, archive SHA256, date, reviewer |
| Offline | PHP 8.4/8.5 results, exact-archive checks, upgrade/rollback |
| GitHub | CI run URL and tested commit; `not_run` before pushing |
| Provider | Hetzner Cloud or Netcup, scoped test reference, runtime version |
| DNS policy | Explicit strict/recursive mode, resolver set, measured duration |
| Certbot | Fresh apex/wildcard challenges, issuance and renewal, hook results |
| Cleanup | Empty recovery plan and before/after equality |
| TLSA | Separate smoke-test result or explicit `not_run` |
| Decision | Remaining risks and explicit RC/stable publication approval |

Never prefill live or remote results as passed. Retain previous failed reports
alongside the correction rather than overwriting the evidence.
