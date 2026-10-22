# TLSA Publication and Key Rotation

## Safety Contract

The hook publishes DANE-EE SPKI SHA-256 values (`3 1 1`) using PHP OpenSSL.
Missing or invalid certificates abort before provider writes. The public key is
extracted from `cert.pem`; private keys are neither read nor logged.

Default publication adds the new value and preserves existing values. It does
not replace the entire RRSet, restart services, prove DNSSEC validation or wait
for DNS propagation. A successful hook is not proof that remote clients can
validate a newly activated service key. Use concrete service hostnames; `%`
expands only non-wildcard names from `RENEWED_DOMAINS`.

## Rotation Runbook

1. Keep the currently served key active. Obtain the future certificate without
   loading it into SMTP/IMAP/HTTPS. Separate certificate issuance from service reload.
2. Publish the future public key, retaining the existing key. Supply the configured
   lineage as the mapping key and the future certificate as the hash source:

```sh
./certbot-tlsa-generator.php --lineage /etc/letsencrypt/live/example.com \
  --certificate /secure/staging/cert.pem --domains mail.example.com --dry-run
```

3. Review the plan, then repeat without `--dry-run` only with DNS write approval.
   Verify both values on the authoritative servers and trusted validating resolvers.
4. Wait at least two prior TLSA TTLs after publication is confirmed before
   activating the new key. If TLSA did not exist, account for negative caching
   as well. Netcup's zone TTL must be obtained separately; do not assume the
   requested record TTL was honored. Unknown TTL/propagation means do not activate.
5. Activate/reload the new service certificate, verify the served key on all
   endpoints, retain the old value through the planned rollback/cache window.
6. Retire only the exact old value after the rollover is complete:

```sh
./certbot-tlsa-generator.php --lineage /etc/letsencrypt/live/example.com \
  --domains mail.example.com --mode retire --old-value '3 1 1 OLD_SHA256' --dry-run
```

Replace `OLD_SHA256` with the actual 64-character old public-key hash. Applying
retirement additionally requires `--confirm-rollover-complete`. This is an
operator assertion, not an automated timing or DNSSEC check. The tool refuses
to delete the current certificate's value or leave a target without that value.
For rollback, the old key must still have a published, propagated TLSA value.

The default Certbot deploy-hook invocation remains supported but is only an
additive publisher. Do not chain an immediate service reload to it during key
rotation. Renewals retaining the same SPKI do not require a key rollover.

The timing and overlap model follows
[RFC 7671 section 8.1](https://www.rfc-editor.org/rfc/rfc7671.html#section-8.1).
Automated service deployment remains outside the DNSAPI hook.
