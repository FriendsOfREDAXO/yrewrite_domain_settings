# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [2.5.0] — unveröffentlicht

Die Oberfläche ist umgebaut: Domain, Tab und Sprache stehen jetzt gemeinsam auf
**einer** Seite statt verteilt über die Backend-Navigation. Datenstruktur, API
und REST-Routen bleiben unangetastet — gepflegte Werte, eigene Templates und
Tokens laufen unverändert weiter.

### Hinzugefügt

- **Tab-zu-Domain-Zuordnung**: Mehrfachauswahl in den Einstellungen und schon
  beim Anlegen eines Tabs, gespeichert in `rex_config` unter `section_domains`.
  Reiner **Ansichtsfilter** — er bestimmt, wo ein Tab zur Bearbeitung
  angeboten wird, und sonst nichts. Der Lesepfad bleibt unberührt, bereits
  gepflegte Werte wirken im Frontend weiter; deshalb sagt das Speichern es
  dazu, wenn einer abgewählten Domain Werte bleiben. Nichts gewählt heißt
  alle Domains, alle gewählt wird als „keine Einschränkung" gespeichert —
  sonst verlöre ein Tab still jede später angelegte Domain.
- Eigene Seite **Migration** für „Daten übertragen". Getrennt von den
  Einstellungen, weil sie keine ist: die Einstellungen beschreiben das
  Verhalten von jetzt an, diese Seite ändert gespeicherte Daten in einem Zug.
- Kontextzeile über den Tabs: Domain-Auswahl links (`selectpicker`),
  Sprachumschaltung rechts. Die Sprachumschaltung baut auf den Core-Fragmenten
  auf (`core/buttons/button_group.php`, ab vier Sprachen
  `core/dropdowns/dropdown.php`) und trägt `btn-clang` wie die Struktur-Seite.
- **Schutz vor YForm** auf den Tabellen dieses Addons: ein `OUTPUT_FILTER`
  blendet YForms Angebot aus, `domain_id` und `clang_id` in Felder zu
  verwandeln, und den Knopf „Tabelle aktualisieren mit Feldlöschung". Der
  löscht (yform, `lib/manager/table/api.php`, `generateTableAndFields()` mit
  `$delete_old`) jede Spalte ohne Feld außer `id` — hier also genau die beiden
  Spalten, die bestimmen, welche Zeile geschrieben wird.
- Neue Test-Suite `section-domains` (`lib/Tests/SectionDomainsSuite.php`) mit
  15 Prüfungen.

### Behoben

- Der Duplikat-Check für Feldnamen fragt erst, ob sich zwei Tabs überhaupt
  eine Domain teilen. Tabs auf verschiedenen Domains antworten nie für
  dieselbe Domain und dürfen denselben Feldnamen führen.
- Beim Zusammenführen mehrerer Tabs schlägt ein gefüllter Wert einen leeren
  aus einem anderen Tab. Vorher gewann der zuerst gelesene Tab, und eine
  einmal geöffnete, leere Zeile konnte echten Inhalt verdecken.

### Geändert

- Die Navigation hat vier feste Punkte: **Daten**, **Einstellungen**,
  **Migration**, **Hilfe**. Vorher war jeder Tab eine eigene Backend-Seite.
  Alles außer „Daten" ist Administratoren vorbehalten (`perm: admin[]`).
- **Die Backend-URLs der Tabs ändern sich**: aus
  `page=yrewrite_domain_settings/<slug>` wird
  `page=yrewrite_domain_settings/data&section=<slug>`. Lesezeichen auf alte
  Tab-URLs landen künftig auf dem ersten Tab. Der Rücklink aus dem
  YForm-Table-Manager wurde mitgezogen. Kein API-Bruch — nur Adressen im
  Backend.
- Domain und Sprache liegen in der Session (Request > Session > erste
  erlaubte) und überdauern damit das Verlassen der Seite. Der Tab steht als
  `section=<slug>` in der URL: er ist, wo man auf der Seite ist, nicht der
  Kontext, um den es geht. Die Sprache wird je Domain aufgelöst.
- Der Dialog bei ungespeicherten Änderungen greift auf **jedem** Weg von der
  Seite — Reiterwechsel, Felder bearbeiten, Hilfe, Einstellungen,
  Domainwechsel —, nicht mehr nur beim Sprachwechsel. Das Ziel reist als
  relativer Backend-Link im POST mit und wird serverseitig gegen ein Muster
  geprüft, bevor umgeleitet wird.
- Die Einstellungen liegen in drei Panels: **Neuer Tab**, **Vorhandene Tabs**,
  **Fallback**. „Daten übertragen" ist auf die Seite **Migration** gewandert.
- Der Fallback-Hinweis auf der Datenseite nennt nur noch die Regel, ohne die
  Liste der betroffenen Felder, und steht auf jedem Tab jeder Sprache außer
  der Fallback-Sprache. Ein Hinweis, der kommt und geht, ist einer, auf den
  sich niemand verlässt.
- `console domain-settings:test` — 58 Prüfungen in sieben Suiten.

### Kompatibilität

Kein Breaking Change: Datenstruktur, Legacy-Klassen (`yrewrite_domain_settings`,
`REX_DOMAIN_SETTING`) und die REST-Routen sind unangetastet,
`lib/Tests/LegacyApiSuite.php` bleibt grün. Es gibt keine Migration; die
Tab-zu-Domain-Zuordnung ist neu und leer, was „alle Domains" bedeutet und
damit dem Verhalten von 2.4.0 entspricht.

Einzige spürbare Änderung sind die Backend-URLs der Tabs, siehe „Geändert".

## [2.4.0] — unveröffentlicht

Werte sind ab jetzt je Sprache pflegbar und lassen sich in Tabs aufteilen.
**Bestehender Code läuft unverändert weiter** — Details im
[README](README.md#umstieg-von-230).

### Hinzugefügt

- Eine Zeile je Domain **und** Sprache; nicht gefüllte Werte erben aus der
  Fallback-Sprache ([#30])
- Sprachen kommen aus yrewrite: eine Domain bietet nur die Sprachen an, die
  sie auch ausliefert ([#30])
- **Tabs** — mehrere YForm-Tabellen, je Tab ein Reiter und eine eigene
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
  Tab
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
- Berechtigungen auf einen gelöschten Tab bleiben als tote Einträge in den
  Rollen zurück — wie im YForm Table Manager auch

## Ältere Versionen

Bis 2.3.0 nur in den [Releases][rel] festgehalten.

[#28]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/28
[#30]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/30
[#35]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/35
[#36]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/pull/36
[rel]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/releases
