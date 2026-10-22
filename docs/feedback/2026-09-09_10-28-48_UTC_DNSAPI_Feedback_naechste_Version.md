# DNSAPI: konkretes Feedback für die nächste Version

Grundlage: tatsächlich eingesetzte v0.1.1, Commit `8ab16c0179f3e331b606cd9e97a94e406ee74661`, geprüft im PHP-8.5-CLI-Container von CLINQUORe. Dies sind Umsetzungsvorschläge; der Connector wurde für dieses Feedback nicht verändert. Keine Zugangsdaten oder Challengewerte sind enthalten.

## Empfehlung zur Priorität

Für die nächste Version zuerst **aussagekräftige DNS-Diagnose, ein echtes Zeitbudget und robuste Journal-/Cleanup-Behandlung** umsetzen. Die bestehende schlanke PHP-/dig-Lösung kann bleiben. Dafür ist weder ein neues Framework noch eine zusätzliche dauerhafte Anwendung erforderlich.

Eine bloße Verlängerung des Wartefensters würde die Ursache unseres Problems verdecken und den Ablauf langsamer machen. Ebenso sollte eine fehlgeschlagene Autoritätsprüfung nicht automatisch durch einen anderen Prüfmodus ersetzt werden.

## 1. Tatsächlicher Befund und Grenzen der Diagnose

Die v0.1.1 verwendet folgende Reihenfolge:

1. `dig +short NS <Domain>` über den Standardresolver.
2. TXT-Abfrage an jeden ermittelten Nameserver über `dig … @<Nameserver>`.
3. TXT-Abfragen an `1.1.1.1`, `8.8.8.8` und `9.9.9.9`.

Bei der ersten fehlgeschlagenen Autoritätsabfrage wird bereits `false` zurückgegeben. Die öffentlichen Resolver werden in diesem Durchlauf deshalb nicht mehr geprüft.

Auf unserer VM sahen die öffentlichen Resolver den erwarteten Challengewert. Gezielte Abfragen an die Hetzner-Nameserver lieferten dagegen NXDOMAIN mit negativen Cachezeiten und rekursiven Flags `rd ra` ohne `aa`. Staging gelang nach Ablauf einer alten negativen Cacheantwort; anschließend gelang auch die echte Erstausstellung. Beim Renewal-Dry-Run trat das Problem erneut mit ungefähr einer Stunde negativer Cachezeit auf. Der Lauf wurde kontrolliert abgebrochen und seine Challenge bereinigt.

**Das beweist weder einen lokalen Resolvercache als Ursache noch einen bestimmten Routerfehler.** Eine Umleitung oder ein zwischengeschalteter DNS-Dienst ist eine plausible Hypothese. `dig @1.1.1.1` adressiert normalerweise direkt diesen Resolver; eine Abfrage per `@Nameservername` benötigt zusätzlich dessen Adressauflösung. Der Connector sollte den beobachteten Fehler präzise beschreiben, ohne die Ursache zu behaupten. Die [BIND-Dokumentation zu dig](https://bind9.readthedocs.io/en/latest/manpages.html#dig-dns-lookup-utility) beschreibt Serverauswahl und Abfrageoptionen.

## 2. Priorität 1: DNS-Abfragen liefern strukturierte Ergebnisse

Betroffene Funktionen: `txtLookupContains()`, `getAuthoritativeNameservers()`, `txtRecordIsVisibleEverywhere()`.

**Änderungsvorschlag:** Nicht ausschließlich `+short` und ein boolesches Ergebnis verwenden. Einen kleinen gemeinsamen Query-Helper einführen, der mindestens Folgendes zurückgibt:

- gewählter Servername und tatsächlich adressierte IP, Transport UDP/TCP;
- Exitstatus des Prozesses, DNS-RCODE und relevante Flags;
- erwarteter TXT-Wert vorhanden: ja/nein;
- positive TTL bzw. vorhandene negative Cacheinformation;
- gemessene Dauer und eine unterscheidbare Fehlerklasse.

`dig` kann dafür bleiben. Seine Textausgabe muss stabil konfiguriert und mit Fixtures getestet werden; Locale festlegen und möglichst einen Prozessaufruf mit Argumentliste statt selbst zusammengesetzter Shellbefehle verwenden. **Exitcode 0 allein ist kein Nachweis einer positiven DNS-Antwort.** NXDOMAIN, NOERROR ohne passenden TXT, SERVFAIL, REFUSED, Timeout und ein falscher TXT-Wert sind unterschiedliche Ergebnisse.

Diagnosemodus: etwa `dnsapi doctor --domain example.test` als neuer, ausdrücklich vorgeschlagener CLI-Befehl. Er darf ohne DNS-Schreibzugriff Resolver-/Autoritätswege vergleichen. Nicht vor jedem neuen Challenge-Record unnötig den noch nicht existierenden TXT-Namen über rekursive Resolver abfragen: Das könnte gerade eine negative Cacheantwort erzeugen.

**Abnahme:** Ein Test mit „öffentliche Resolver sehen den Wert, Autoritätsabfrage liefert NXDOMAIN ohne AA“ muss genau diese Differenz melden und darf nicht nur „nicht rechtzeitig sichtbar“ ausgeben.

## 3. Priorität 1: Autoritäts- und Resolverprüfung sauber trennen

- Discovery-Resolver konfigurierbar machen und NS-Namen sowie ihre A-/AAAA-Adressen darüber bewusst auflösen. Keine versteckte Abhängigkeit vom Standardresolver, wenn ein expliziter Resolver konfiguriert wurde.
- Autoritätsabfragen ausdrücklich mit `+norecurse` an eine aufgelöste Server-IP richten. Zieladresse und Antwortflags auswerten. Ein nicht autoritatives Ergebnis nicht als autoritative Bestätigung zählen.
- Bei fehlendem AA unterscheiden: Referral, CNAME-Ziel, falsche Zone, REFUSED oder unerwartet rekursive Antwort. Nicht jedes fehlende AA als bewiesene Netzwerkumleitung behandeln. Auch ein gesetztes AA ist keine kryptografische Herkunftsbestätigung.
- Bei Transportproblemen einen begrenzten TCP-Versuch ermöglichen. TCP ist ein Diagnose-/Transportfallback und beseitigt nicht automatisch eine Umleitung oder einen Cache.
- Pro Prüfrunde alle benötigten Ergebnisse sammeln, bevor entschieden wird. Ein früher Fehler darf die Diagnose der übrigen Server nicht verhindern. Serielle Abfragen reichen zunächst; begrenzte Parallelität ist eine optionale Optimierung.
- Bei delegierten Challenge-Zonen oder CNAME-Verweisen entweder die tatsächliche Zuständigkeit sicher verfolgen oder diese Konfiguration vor einer Mutation ausdrücklich als nicht unterstützt ablehnen. Niemals stillschweigend in einer anderen Zone schreiben.

Die Optionen für Rekursion, TCP und Ausgabeformat sind in der [dig-Dokumentation](https://bind9.readthedocs.io/en/latest/manpages.html#dig-dns-lookup-utility) beschrieben.

**Prüfpolitik:** Für diese Fehlerkorrektur zunächst die bestehende Anforderung „Autoritäten und konfigurierte öffentliche Resolver erfolgreich“ beibehalten, aber die Gruppen getrennt sichtbar machen. Falls später ein Modus nur mit öffentlichen Resolvern oder ein Quorum gewünscht wird, muss er ausdrücklich konfiguriert und dokumentiert werden; kein automatischer Wechsel nach einem Autoritätsfehler. Weder ein Quorum noch die Prüfung aller konfigurierten Resolver garantiert die Sicht jedes ACME-Validierers.

**Abnahme:** Gezielt konfigurierte Discovery muss auch bei kaputtem Standardresolver funktionieren. Ein Abbruch muss erkennen lassen, welche Prüfgruppe tatsächlich blockiert.

## 4. Priorität 1: echtes Zeitbudget und verständliche Wartegründe

Betroffene Funktion: `waitForChallengeEntries()`.

Heute sind 120 Versuche mit jeweils 10 Sekunden Schlaf konfiguriert. **Das ist kein hartes 20-Minuten-Limit:** API-Aufrufe, DNS-Timeouts und weitere Verarbeitung kommen hinzu.

Vorschlag:

- Konfigurierbares Gesamtbudget, etwa `propagation_timeout_seconds`, mit monotoner Uhr (`hrtime`) messen.
- Restbudget vor jedem API-/DNS-Aufruf prüfen und dessen Timeout darauf begrenzen. Auch Sleep darf die Deadline nicht überschreiten.
- DNS-Timeout, Pollintervall und Gesamtbudget getrennt konfigurieren und plausible Grenzen validieren. Bestehende Konstanten können als abwärtskompatible Defaults dienen.
- Ein negativer Cache mit deutlich längerer Restlaufzeit als das verbleibende Budget soll früh klar diagnostiziert werden. Ein solcher Lauf darf mit aussagekräftigem Fehler und anschließender Bereinigung beendet werden, statt minutenlang denselben unveränderten Zustand zu melden.
- NXDOMAIN und NOERROR ohne passenden Datensatz unterscheiden. Negative Cachezeiten aus der tatsächlich vorliegenden SOA-Information ableiten; nicht mit der gewünschten TXT-TTL verwechseln. Eine TXT-TTL von 120 Sekunden begrenzt nicht automatisch das negative Caching. Hintergrund: [RFC 2308, negative DNS-Antworten und Cachezeiten](https://www.rfc-editor.org/rfc/rfc2308.html).
- Provider-Instanzen und Zonendaten innerhalb einer Runde sinnvoll wiederverwenden. Aktuell können Konstruktor, `loadAPI()` und `providerRecordIsActive()` wiederholt dieselben Records abfragen. Ratenlimits, HTTP 429 und Retry-After berücksichtigen, ohne die Deadline aufzuheben.

Synthetisches Beispiel für eine hilfreiche Fehlermeldung:

```text
PROPAGATION_BLOCKED group=authoritative server=ns1.example.test
rcode=NXDOMAIN aa=false ra=true negative_ttl_seconds=3400
remaining_budget_seconds=900 recursive_checks=3/3
Possible unexpected DNS path; cause not established. Cleanup scheduled.
```

Keine echten TXT-Werte, API-Antwortkörper oder Credentials in diese Meldung aufnehmen.

## 5. Priorität 1: Cleanup ist wiederholbar und nach Abbruch möglich

Betroffene Bereiche: Cleanup-Hook, `deleteRecord()` im Hetzner-Cloud-Adapter, Journal.

Bei unserem kontrollierten Certbot-Abbruch blieb ein Journaleintrag zurück. Die CLINQUORe-Integration hat dafür eine eigene Wiederherstellung ergänzt. Eine allgemeine Lösung gehört sinnvollerweise in DNSAPI.

- Einen ausdrücklich auf Journalbesitz begrenzten Recovery-Aufruf anbieten: zuerst lesende Übersicht/Plan, anschließend beispielsweise Cleanup eines bestimmten Laufs. Das sind neue CLI-Vorschläge, keine bereits vorhandenen Befehle.
- **Bereits fehlender Challengewert gilt nach erfolgreicher API-Abfrage als erfolgreich aufgeräumt.** API-Timeout, 401/403 oder unklarer Zustand dagegen nicht. Der aktuelle Cloud-Adapter behandelt einen nicht gefundenen Wert als Fehler.
- Aus dem Journal muss hervorgehen, ob dieser Lauf den Wert angelegt oder einen bereits vorhandenen Wert nur wiederverwendet hat. Wiederverwendung darf nicht versehentlich den Besitz eines anderen laufenden Jobs übernehmen.
- Nur den eigenen, exakt identifizierten Challengewert entfernen. Ein unvollständiger Journaleintrag ohne Wert darf im Cleanup niemals in eine Löschung des gesamten RRsets übergehen. Der allgemeine Adapter besitzt einen solchen Löschpfad, wenn kein Wert angegeben ist.
- Fehlerhafte Einträge erhalten bzw. kennzeichnen; nicht aus dem Journal streichen, bevor ihre Bereinigung geklärt ist.
- Cleanup bei normalem Fehler und behandelbaren Signalen versuchen. Für SIGKILL, VM-Absturz und Stromausfall braucht es trotzdem eine spätere Wiederherstellung. Eine zusätzliche PHP-Erweiterung für Signalbehandlung nur einführen, wenn sie bewusst als Abhängigkeit dokumentiert wird.
- Certbot-Hook-Stdout für die maschinenlesbare Rückgabe reservieren. Statusmeldungen nach stderr bzw. in ein geschütztes Log. Certbot übergibt Authenticator-Stdout an den Cleanup-Hook über `CERTBOT_AUTH_OUTPUT`; siehe [Certbot-Hooks](https://eff-certbot.readthedocs.io/en/stable/using.html#hooks).

**Abnahme:** Fremder TXT bleibt erhalten; zweimaliger Cleanup gelingt; ein bereits extern entfernter eigener Wert gilt als erledigt; ein API-Fehler lässt die Recoveryinformation stehen; ein Datensatz ohne Challengewert löst keine RRset-Löschung aus.

## 6. Priorität 1: Journal verliert bei Fehlern keine Information

Konkrete statische Codebefunde in `readJournal()` und `writeJournal()`:

- Nicht parsebares JSON wird derzeit wie ein leeres Journal behandelt. Ein späterer Schreibvorgang kann dadurch die bisherige Recoveryinformation überschreiben.
- Vor dem Schreiben wird die Datei gekürzt. Ein Absturz danach kann ein leeres oder unvollständiges Journal hinterlassen.
- `fwrite()` wird nur auf `false` geprüft; ein unvollständiger Schreibvorgang wird dadurch nicht sicher erkannt.
- Der DNS-Record wird vor seiner Journalisierung angelegt. Ein Abbruch dazwischen kann einen Record ohne Recoveryeintrag hinterlassen.

Vorschlag: Journalformat versionieren; beschädigte oder unbekannte Formate mit klarer Fehlermeldung erhalten. Atomar über eine temporäre Datei im gleichen Verzeichnis schreiben, Schreib-/Flushfehler prüfen und bei erforderlicher Absturzfestigkeit fsync berücksichtigen. **Die Sperre muss auf einer separaten stabilen Lockdatei liegen**, wenn die Journaldatei durch Rename ersetzt wird; andernfalls können Prozesse verschiedene Inodes sperren.

Vor der DNS-Mutation einen nachvollziehbaren Vorgangseintrag schreiben, danach das Providerergebnis ergänzen. Bei einem Absturz anhand des exakt vorgesehenen Werts abgleichen. Eine eigene Lauf-/Vorgangskennung verhindert, dass zwei Bestellungen mit denselben Domainnamen anhand von `IDENTIFIER_SET` allein vermischt werden. Entweder Parallelität sauber koordinieren oder sie für konkurrierende Vorgänge gezielt ablehnen; die Dateisperre allein serialisiert nicht die gesamte externe DNS-Operation.

**Abnahme:** Defektes Journal wird nicht still überschrieben; Schreibfehler und simulierte Abbrüche verlieren keine vorhandenen Einträge; zwei Läufe mit identischen Domains werden nicht verwechselt. Bereits existierende Journale müssen weiter gelesen oder ausdrücklich migriert werden können.

## 7. Priorität 2: Konfiguration, Datenschutz und kleine Wartbarkeitsverbesserungen

- Konfiguration vor jeder Mutation validieren: Providername/Dateiname, URL-Schema und passende Provider-Basis-URL, Domain-/Zonezuordnung, Resolveradressen und Zeitgrenzen. Für Hetzner Cloud lautet die hier erfolgreich verwendete Basis `https://api.hetzner.cloud/v1/zones`; das README-Beispiel und die Groß-/Kleinschreibung des Adapternamens sollten dazu passen.
- Config-/Journal-/Logpfade optional konfigurierbar machen, mit bisherigen Pfaden als Default. Das erleichtert Container, getrennte Instanzen und Tests, ohne Dateien im Quellcode umzuschreiben.
- Sichere Rechte beim Erstellen selbst setzen: restriktive umask, Verzeichnisse 0700, geheime Dateien 0600. Nicht allein auf die aufrufende Shell vertrauen. Leserechte/Ownership mit dem dokumentierten Betriebsbenutzer abstimmen; Container müssen nicht überall denselben Hostbenutzer voraussetzen.
- Rohe Providerantworten nicht in `lastError` weiterreichen. Der aktuelle Cloud-Adapter kann das tun. Standardfehler auf sicheren Fehlercode/HTTP-Status und ggf. bereinigte Korrelations-ID begrenzen. Auch Provider-Fehlermeldungen können Eingaben wiederholen.
- Diagnoselevel und Logbegrenzung vorsehen. API-Token, Authorization-Header, Challengewerte, vollständige Records und Konfigurationsinhalte bleiben aus normalen Logs und stdout heraus.
- Gemeinsame Funktionen für Konfiguration, DNS, Journal und Fehlerausgabe in kleine gemeinsame Dateien verschieben, soweit dies echte Doppelungen entfernt. Keine umfangreiche Architekturänderung allein für diesen Release.

## 8. Konkrete Testliste für PHP 8.4 und 8.5

Die vorhandene Syntaxprüfung beibehalten und kleine Verhaltenstests mit synthetischen Fixtures ergänzen. Ein injizierbarer Prozess-/API-Aufruf genügt; CI benötigt keine echten Tokens und darf keine echten DNS-Änderungen durchführen.

1. Erwarteter TXT unter mehreren fremden TXT-Werten; DNS-TXT mit mehreren korrekt zusammenzusetzenden Character-Strings innerhalb eines Records. Verschiedene Records nicht miteinander zusammenfügen.
2. NOERROR ohne Wert, falscher Wert, NXDOMAIN mit SOA, SERVFAIL, REFUSED, Timeout und abgeschnittene Antwort/geeigneter TCP-Fallback.
3. Öffentliche Resolver erfolgreich, Autoritätsantwort nicht autoritativ: verständliche Diagnose und kein stiller Erfolg.
4. Discovery über expliziten Resolver trotz defektem Standardresolver; unterschiedliche erreichbare A-/AAAA-Adressen.
5. Absolutes Zeitbudget einschließlich langsamer DNS- und API-Aufrufe.
6. Cleanup wiederholt, Wert schon weg, API nicht erreichbar, fremde Werte vorhanden, fehlender eigener Wert im Journal.
7. Journal beschädigt, kurzer/fehlgeschlagener Schreibvorgang, Abbruch zwischen Anlegen und Bestätigen, konkurrierende Läufe mit gleichen Domains.
8. Mehrere Challenges, insbesondere Apex und Wildcard mit gemeinsamem TXT-Namen. Nicht aus der Zahl eindeutiger Domainstrings allein auf die Anzahl der Challenges schließen.
9. Weder Secrets noch vollständige Providerantworten in Fehlerausgabe; unter E_ALL keine PHP-8.4-/8.5-Warnungen oder Deprecations.
10. Nach Release kontrollierter echter Staginglauf und Renewal-Dry-Run in der Zielumgebung. Mocktests können deren DNS-Pfad nicht belegen.

Als vorhandene Grundlage kann die Main-Session die synthetische Hetzner-API-Fixture in `/srv/clinquore/tests/dnsapi.py` und die Wiederherstellung in `/srv/clinquore/infra/certbot/cleanup.py` ansehen. Das sind Integrationshilfen und keine Verpflichtung, ihre konkrete Struktur in DNSAPI zu übernehmen.

## Sinnvoller Abschluss des Releases

Für jeden Punkt kenntlich machen, ob er umgesetzt, bewusst verschoben oder nicht unterstützt ist. Versionsnummer/Changelog aktualisieren und neue Konfigurationsoptionen mit Defaults dokumentieren. Die bestehenden Hook-Aufrufe möglichst kompatibel halten; notwendige Journalmigrationen oder veränderte Erfolgskriterien ausdrücklich nennen.

Nach dem neuen Release können wir in CLINQUORe Commit, Archivprüfsumme und Ops-Image aktualisieren und die Integration erneut prüfen. Die Untersuchung des auffälligen Netzwerk-DNS-Pfads bleibt unabhängig davon erforderlich; Connectorverbesserungen ersetzen diese Ursachenklärung nicht.
