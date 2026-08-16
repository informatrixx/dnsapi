# dnsapi

`dnsapi` provides dynamic DNS management and Certbot DNS-01 hooks for Hetzner, Hetzner Cloud, and Netcup.

## Requirements

- PHP 8.4 with cURL support
- `dig` for Certbot propagation checks
- Provider credentials in `/etc/dnsapi/config.json`

The configuration file is intentionally not part of the repository. Never commit API tokens, DNS journals, or production configuration.

## Usage

The CLI entry point is executable:

```sh
./dnsapi-interface.php -x example.com -p
```

This prints the records for a configured zone. Record changes use the provider configuration and should only be tested with an explicitly approved test record.

Certbot uses `certbot-dnsapi-authenticator.php` and `certbot-dnsapi-cleanup.php` as manual hooks. The authenticator waits for the expected TXT record on authoritative nameservers and public resolvers before Certbot validation continues.

## Development

There is no Composer dependency or build system. Lint PHP files directly:

```sh
for file in $(git ls-files '*.php'); do php -l "$file"; done
```

DNS integration tests are read-only by default. See `CONTRIBUTING.md` and `AGENTS.md` for contributor and automation rules.

## Releases

The version is maintained in `VERSION` using Semantic Versioning. Release tags must match the file, for example `VERSION=0.1.0` requires tag `v0.1.0`. GitHub Actions creates the release archive only from a matching tag and never includes ignored runtime configuration.
