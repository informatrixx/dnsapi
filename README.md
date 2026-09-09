# dnsapi

`dnsapi` ist ein leichtgewichtiges PHP-Toolset für DNS-Automatisierung und Zertifikatbetrieb im `Hetzner`, `HetznerCloud` und `Netcup`-Umfeld. Es liefert:

- `dnsapi-interface.php`: Kommandozeilen-Interface für das direkte Record-Management.
- `certbot-dnsapi-authenticator.php` + `certbot-dnsapi-cleanup.php`: Certbot DNS-01 Hooks für `_acme-challenge`.
- `certbot-tlsa-generator.php`: Erstellt/aktualisiert TLSA-Records nach erfolgreicher Zertifikatserneuerung.
- `index.php`: DynDNS-HTTP-Endpunkt (optional).

## Voraussetzungen

- PHP 8.4 oder 8.5 mit cURL (CLI; für den HTTP-Endpunkt zusätzlich PHP-FPM/Apache mit einer dieser Versionen)
- `dig` im PATH (für TXT-Propagation-Prüfung)
- `openssl` im PATH (für TLSA-Hash)
- Laufzeit-Config unter `/etc/dnsapi/config.json` (nicht ins Repo einchecken)

## Installation und Berechtigungen

```bash
git clone <repo>
cd dnsapi
chmod +x *.php
```

Erforderliche Runtime-Pfade:

- `/etc/dnsapi/config.json`
- `/var/cache/dnsapi` (journal/cache)

Empfehlung: Datei- und Verzeichnisrechte restriktiv halten (`0600` / `0700`).

### PHP-Version

CLI und Certbot-Hooks verwenden `/usr/bin/php` als Interpreter und unterstützen
PHP 8.4 und 8.5. Prüfe die aktive Version und die cURL-Erweiterung:

```bash
/usr/bin/php -v
/usr/bin/php --ri curl
```

Direkte Aufrufe wie `./dnsapi-interface.php` bleiben unverändert. Ein Wechsel
zwischen PHP 8.4 und 8.5 erfordert keine Anpassung der Skripte. Für `index.php`
wird die PHP-Version separat über den FPM-Pool bzw. das Apache-Modul bestimmt.

## Konfiguration

`/etc/dnsapi/config.json` enthält Provider- und Domain-Mapping in diesem Muster:

```json
{
  "API": {
    "HetznerCloud": { "recordsURL": "https://api.hetzner.cloud/v1/zones", "APIToken": "..." },
    "Netcup": {
      "endpointURL": "https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON",
      "KundenNR": "...",
      "APIKey": "...",
      "APIPass": "..."
    }
  },
  "domains": {
    "example.com": { "zoneID": "989221", "API": "HetznerCloud" },
    "example.net": { "zoneID": "example.net", "API": "Netcup" }
  },
  "tlsa-records": {
    "/etc/letsencrypt/live/example": {
      "%": [25, 443],
      "mail.example.com": [465]
    }
  }
}
```

- `domains`: Suffix-basierte Domainauflösung für Hooks und CLI.
- `API`: Adapter-Name muss exakt zu `dnsapi-<Name>.inc.php` passen: `Hetzner`, `HetznerCloud` oder `Netcup`. Die Auth-/Cleanup-Hooks akzeptieren auch bestehende Alias-Schreibweisen.
- `zoneID`: Für Netcup i. d. R. Zonennamen, für H-Cloud typischerweise Zone-Identifier.

## Bedienung CLI

```bash
./dnsapi-interface.php -x example.com -p
./dnsapi-interface.php -x example.com -pTXT
./dnsapi-interface.php -x example.com -s _acme-challenge.example.com:TXT=token:120
./dnsapi-interface.php -x example.com -d test.example.com:TXT
```

- `-x` / `--domain`: Zone auswählen.
- `-s`: Setzen/Update (erstellt wenn nicht vorhanden).
- `-c`: Neu anlegen.
- `-d`: Löschen.
- `-p[TYPE]`: Listet Records (optional gefiltert).
- `-h`: Hilfe.

Weitere Hinweise zu Flows und Formaten stehen in `./dnsapi-interface.php -h`.

## Certbot-Integration

```bash
certbot renew \
  --manual \
  --preferred-challenges dns \
  --manual-auth-hook /var/lib/dnsapi/certbot-dnsapi-authenticator.php \
  --manual-cleanup-hook /var/lib/dnsapi/certbot-dnsapi-cleanup.php
```

Die Hooks erstellen zuerst alle Challenges. Der letzte Auth-Hook prüft alle
Werte gesammelt gegen die autoritativen Nameserver und die konfigurierten
öffentlichen Resolver. Diagnoseausgaben gehen nach stderr; stdout enthält nur
die Vorgangskennung für den Cleanup. Details, Konfiguration und Recovery:
[Certbot-Betrieb](docs/certbot.md).

TLSA automatisch nach erfolgreicher Erneuerung:

```bash
certbot renew \
  --deploy-hook /var/lib/dnsapi/certbot-tlsa-generator.php
```

Die Hooks verwenden intern ein gemeinsames Journaling unter `/var/cache/dnsapi/journal.json`.

TLSA ist in `tlsa-records` nach `RENEWED_LINEAGE` organisiert. Der Platzhalter `%` gilt für alle Domains der aktiven Erneuerung.

## DynDNS HTTP-Endpunkt

`index.php` reagiert auf Requests wie:

```bash
curl "https://example.com/?host=mail&secret=...&domain=example.com&ip=203.0.113.10"
```

Die Domain muss als DynDNS-Host in der Konfiguration freigeschaltet sein.

## Entwicklung

Kein Buildsystem. Direkte PHP-Syntaxprüfung:

```bash
for f in $(git ls-files '*.php'); do php -d error_reporting=E_ALL -l "$f"; done
```

CI prüft PHP 8.4 und 8.5. Lokal lässt sich derselbe Check mit `php8.4` und
`php8.5` statt `php` für beide installierten Versionen ausführen.

Offline-Verhaltenstests ohne Providerzugriffe:

```bash
php8.4 tests/hooks.php
php8.5 tests/hooks.php
```

## Versionierung

`VERSION` verwendet SemVer, z. B. `0.1.0`. Release-Tags folgen `v<version>`.
