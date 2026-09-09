# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [2.4.0] — unveröffentlicht

Werte sind ab jetzt je Sprache pflegbar und lassen sich in Bereiche aufteilen.
**Bestehender Code läuft unverändert weiter** — Details im
[README](README.md#umstieg-von-230).

### Hinzugefügt

- Eine Zeile je Domain **und** Sprache; nicht gefüllte Werte erben aus der
  Fallback-Sprache ([#30])
- Sprachen kommen aus yrewrite: eine Domain bietet nur die Sprachen an, die
  sie auch ausliefert ([#30])
- **Bereiche** — mehrere YForm-Tabellen, je Bereich ein Reiter und eine eigene
  YForm-Tabellenberechtigung
- Eigene Pflegeseite mit Domain- und Sprachumschaltung, Anzeige geerbter Werte
  und aufklappbaren Feldgruppen
- Domain-Rechte greifen jetzt auf die Daten, nicht nur auf die Oberfläche
  ([#28])
- `DomainSettings::get()` / `::getAll()` mit Standardwert, optionaler Domain
  und Sprache — funktioniert auch ohne aktuellen Artikel
- `REX_DOMAIN_VALUE[key="…"]` — escapt standardmäßig, `output="html"` als
  Opt-out
- Datei-Cache über alle Domains und Sprachen; ein Request, der Werte liest,
  kostet keine Query
- Werte zwischen Domains und Sprachen übertragen
- REST-API über das api-Addon, sofern installiert, mit eigenen Scopes je
  Bereich
- `console domain-settings:ide-helper` für Feldnamen-Autovervollständigung
- Medienschutz: ein verwendetes Bild lässt sich nicht mehr aus dem Medienpool
  löschen
- `console domain-settings:test` — 43 Prüfungen in sechs Suiten

### Behoben

- Auf dem Startartikel einer Domain kamen keine Werte an; die Hilfskategorie
  „Startseite" ist damit überflüssig ([#35], [#36])
- Fatal Error ohne aktuellen Artikel, etwa in Console und Cronjob
- Die Domain wurde je Request zwischengespeichert und konnte veralten ([#36])
- `getAllowedDomains()` lief ohne angemeldeten Benutzer in einen Fatal Error

### Geändert

- Anforderungen: REDAXO ^5.17, PHP ^8.1, YForm ^5.0. Ältere Installationen
  bekommen das Update nicht angeboten und bleiben auf 2.3.0.
- Gepflegt wird auf der Seite des Addons; die Tabelle ist in der
  YForm-Tabellenliste versteckt. Felder weiterhin im Table Manager.
- Feld `domain_id` und der `unique`-Validator darauf entfallen — die Domain
  wählt die Seite. Die Spalte bleibt.

### Migration

Läuft beim Update automatisch: `clang_id` ergänzen (vorhandene Zeilen auf die
Startsprache), `domain_id` auf `int`, alten Validator entfernen, Unique-Index
auf `(domain_id, clang_id)`.

Datensatz-IDs bleiben unverändert — eigene Tabellen, die darauf verweisen,
bleiben gültig und zeigen weiter auf die Zeile der Startsprache. Andere Felder
und Feldtypen werden nicht angefasst. Gibt es mehrere Datensätze für dieselbe
Domain, bricht das Update ab und nennt sie.

### Bekannte Einschränkungen

- Feld im Table Manager gelöscht oder umbenannt: der Wert-Cache fällt nicht
  automatisch, `cache:clear` räumt auf
- Berechtigungen auf einen gelöschten Bereich bleiben als tote Einträge in den
  Rollen zurück — wie im YForm Table Manager auch

## Ältere Versionen

Bis 2.3.0 nur in den [Releases][rel] festgehalten.

[#28]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/28
[#30]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/30
[#35]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/35
[#36]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/pull/36
[rel]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/releases
