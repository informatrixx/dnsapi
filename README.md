# dnsapi

`dnsapi` ist ein leichtgewichtiges PHP-Toolset für DNS-Automatisierung und Zertifikatbetrieb im `Hetzner`, `HetznerCloud` und `Netcup`-Umfeld. Es liefert:

- `dnsapi-interface.php`: Kommandozeilen-Interface für das direkte Record-Management.
- `certbot-dnsapi-authenticator.php` + `certbot-dnsapi-cleanup.php`: Certbot DNS-01 Hooks für `_acme-challenge`.
- `certbot-tlsa-generator.php`: Erstellt/aktualisiert TLSA-Records nach erfolgreicher Zertifikatserneuerung.
- `index.php`: DynDNS-HTTP-Endpunkt (optional).

## Voraussetzungen

- PHP 8.4 oder 8.5 mit cURL (CLI; für den HTTP-Endpunkt zusätzlich PHP-FPM/Apache mit einer dieser Versionen)
- `dig` im PATH (für TXT-Propagation-Prüfung)
- PHP-OpenSSL-Erweiterung (für TLSA-Hash; keine Shell-Pipeline erforderlich)
- Laufzeit-Config unter `/etc/dnsapi/config.json` (nicht ins Repo einchecken)

## Installation und Berechtigungen

```bash
git clone https://github.com/informatrixx/dnsapi.git
cd dnsapi
```

CLI und Hooks sind im Release-Archiv bereits ausführbar; Include-Dateien bleiben
nicht ausführbar. Der Stand `0.3.0-rc.1` ist eine Vorabversion zur Abnahme,
keine stabile Produktionsfreigabe.

Immer den vollständigen Quellbaum einschließlich `src/` installieren. Die Dateien
im Projektstamm bleiben kompatible Einstiegspunkte, die Implementierung liegt in
`src/Provider`, `src/Dns`, `src/Certbot`, `src/Tlsa`, `src/Cli` und `src/Runtime`.
Upgrade, Sicherheitsgrenzen und Release-Ablauf: [Betrieb](docs/operations.md).

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

Eine vollständige Vorlage ohne Zugangsdaten liegt in
[`examples/config.example.json`](examples/config.example.json). Prüfung ohne Netzwerkzugriff:

```bash
DNSAPI_CONFIG_FILE=/etc/dnsapi/config.json ./dnsapi-interface.php --config-check
```

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
./dnsapi-interface.php -x example.com --action=set --name=mail --type=AAAA --value=2001:db8::1 --ttl=600 --dry-run
./dnsapi-interface.php -x example.com --set=@:TXT --value='v=spf1 include:example.net -all' --dry-run
```

- `-x` / `--domain`: Zone auswählen.
- `-s`: Setzen/Update (erstellt wenn nicht vorhanden).
- `-c`: Neu anlegen.
- `-d`: Löschen.
- `-p[TYPE]`: Listet Records (optional gefiltert).
- `-h`: Hilfe.
- `--dry-run`: Providerbestand lesen und Vorher-/Nachher-Plan zeigen, ohne DNS-Schreibzugriff.
- `--json`: JSON-Ausgabe; Fehler liefern Exitcode 1 und einen Fehlercode auf stderr.
- `--replace-rrset`: Den gesamten Recordsatz ausdrücklich ersetzen oder löschen.

`-c` ergänzt einen Wert; `-d name:TXT=wert` entfernt gezielt diesen Wert. Ein
mehrdeutiges Setzen/Ändern wird abgelehnt. Vor einem kompletten RRSet-Ersatz immer
mit `--dry-run` prüfen. Wiederholte Aktionen auf denselben Recordsatz müssen in
separaten Aufrufen erfolgen. Batch-Ausführungen sind nicht zonenübergreifend atomar.
Für IPv6 und komplexe TXT-Werte `--value` und `--ttl` getrennt verwenden. Die alte
TTL-Kurzschreibweise bleibt für einfache Werte erhalten. Netcup Legacy verwendet
Zonen-TTL: eine Record-TTL wird nicht zugesichert und ausdrücklich als unverändert gemeldet.

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

Standardmäßig gilt `certbot.propagation_mode: "strict"`. Für eingeschränkte
DNS-Netze kann ausdrücklich `"recursive"` gewählt werden: Dann prüfen die Hooks
den Providerbestand und die exakten Challengewerte bei allen konfigurierten
Resolvern ohne direkte Autoritätsabfragen. Es gibt keinen automatischen Fallback.

TLSA automatisch nach erfolgreicher Erneuerung:

```bash
certbot renew \
  --deploy-hook /var/lib/dnsapi/certbot-tlsa-generator.php
```

Die Hooks verwenden intern ein gemeinsames Journaling unter `/var/cache/dnsapi/journal.json`.

TLSA ist in `tlsa-records` nach `RENEWED_LINEAGE` organisiert. Der Platzhalter `%` gilt für alle Domains der aktiven Erneuerung.

TLSA veröffentlicht standardmäßig **zusätzliche** Schlüsselwerte und erhält alte
Werte. Wildcards werden beim `%`-Platzhalter übersprungen; konkrete Dienstnamen
explizit konfigurieren. Alte Schlüsselwerte nur nach kontrollierter Rotation
entfernen. [TLSA-Rotation und Wartezeiten](docs/tlsa.md).

## DynDNS HTTP-Endpunkt

`index.php` reagiert auf Requests wie:

```bash
curl "https://example.com/?host=mail&secret=...&domain=example.com&ip=203.0.113.10"
```

Die Domain muss als DynDNS-Host in der Konfiguration freigeschaltet sein.

IPv6 erzeugt AAAA statt A. Der Cache ist nach Provider, Zone, Host und Typ getrennt
und liegt atomar gespeichert in `dyn-cache-v2.json`. Leere oder abgewiesene Requests
verändern ihn nicht. DynDNS-Secrets in Webserver- und Proxy-Logs redigieren.

## Lesender Mail-DNS-Check

```bash
./dnsapi-check.php --domain example.com --dkim-selector default --mail-host mail.example.com
```

Prüft DNS-Bestand für MX, SPF, DKIM, DMARC, MTA-STS und TLSA sowie das DNSSEC-AD-Signal
des gewählten Resolvers. Kein Providerzugriff und keine Zugangsdaten erforderlich.
Exitcode 2 bedeutet fehlende, ungeprüfte oder auffällige Befunde. Das ist kein
vollständiger SPF-/DANE-/HTTPS-Validator. [Grenzen und Architektur](docs/architecture.md).

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

Alle Syntax-, Hook-, Produkt-, Paketformat- und lokalen HTTP-Vertragstests:

```bash
php8.4 tools/verify.php
php8.5 tools/verify.php
```

Tests verwenden ausschließlich synthetische Daten, temporäre Dateien und Loopback.
CI erstellt zusätzlich ein Archiv und prüft genau dieses unter beiden PHP-Versionen,
einschließlich Upgrade und Rollback mit v0.2.1. Nur diese geprüften Bytes werden
später veröffentlicht. Anleitung: [Release-Abnahme](docs/release-acceptance.md).

## Versionierung

`VERSION` verwendet `X.Y.Z` oder `X.Y.Z-rc.N`, beispielsweise `0.3.0-rc.1`.
Release-Tags folgen `v<version>`. RCs sind ausdrücklich als Prerelease markiert
und ersetzen nicht die neueste stabile Version. Vor `0.3.0` sind erfolgreiche
Hetzner-Cloud- und Netcup-Abnahmen erforderlich.
