# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [2.5.0-beta2] — 2026-09-11

Zweite Testversion: beta1 plus das Deinstallieren und die nachgezogene
Dokumentation.

### Hinzugefügt

- `uninstall.php` — beim Deinstallieren werden die Wertetabellen und ihre
  YForm-Registrierung entfernt, wie es yrewrite und structure mit ihren
  Tabellen auch halten. Eine Tabelle wird nur angefasst, wenn sie `domain_id`
  und `clang_id` trägt; eine Projekttabelle mit gleichem Präfix bleibt stehen.
  Was gelöscht wurde, steht danach im `system.log`.

### Dokumentation

- README: Abschnitt **Schutz** — der abgefangene Table-Manager-Knopf, das
  entfernte Feld-Angebot für `domain_id`/`clang_id` und die Medienpool-Sperre
  standen bisher nur im Changelog
- README: Abschnitt **Deinstallieren**, Screenshot der Seite **Migration**

### Geändert

- `.phpstorm.meta.php` wird nicht mehr versioniert — die Datei listet die
  Feldnamen der Installation, die zuletzt ihren Cache geleert hat
- `TODO.md` wird nicht mehr mit ausgeliefert

## [2.5.0-beta1] — 2026-09-11

Testversion des kommenden 2.5.0: alles aus 2.5.0 (unten) und zusätzlich
der Import aus `global_settings`.

### Hinzugefügt

- **Import aus `global_settings`** auf der Seite Migration, sichtbar nur bei
  installiertem AddOn. Erst eine Vorschau (Feld, Zieltyp, was Handarbeit
  braucht), dann der schreibende Schritt — der Import ersetzt den Ziel-Tab.
  Automatisch übernommen werden einfache Felder, Medien- und Linklisten,
  Datum und Uhrzeit (Unix-Zeitstempel → SQL, `0` bleibt leer), die boolesche
  Checkbox (`|true|` → `1`), Mehrfachwerte (`|a|b|` → `a,b`) und
  Auswahllisten in Pipe-Form.

  Die Richtung der Auswahlliste ist dabei der Punkt, der stimmen muss:
  `global_settings` schreibt `wert:Label`, YForms `choice` erwartet
  `{"Label": "wert"}`. Vertauscht sähe im Backend alles richtig aus, während
  kein gespeicherter Wert mehr passt.

  Gemeldet statt geraten: Auswahllisten aus SQL-Abfragen, Farbwähler und
  selbst angelegte Feldtypen. **Callbacks werden nicht importiert** — ihr Code
  steht vollständig in der Vorschau.

  Datumsfelder bekommen einen Jahresbereich mit: YForm beginnt sonst bei
  „aktuelles Jahr minus 20", und ein älteres importiertes Datum wäre im
  Formular nicht wählbar.

## [2.5.0] — noch nicht veröffentlicht, ausgeliefert als 2.5.0-beta1

Die Oberfläche ist umgebaut: Domain, Tab und Sprache stehen jetzt gemeinsam auf
**einer** Seite statt verteilt über die Backend-Navigation. Datenstruktur, API
und REST-Routen bleiben unangetastet — gepflegte Werte, eigene Templates und
Tokens laufen unverändert weiter.

### Hinzugefügt

- **Tab-zu-Domain-Zuordnung**: Mehrfachauswahl in den Einstellungen und schon
  beim Anlegen eines Tabs, gespeichert in `rex_config` unter `section_domains`.
  Sie entscheidet, **wo ein Tab gilt** — er wird dort zur Bearbeitung
  angeboten und nur dort im Frontend ausgegeben (`DomainSettings::get()`,
  `getAll()`, `getSectionValues()`, `REX_DOMAIN_VALUE`). Die Zeilen bleiben
  unangetastet: Wird die Domain wieder zugeordnet, sind die Werte unverändert
  zurück. Das Speichern sagt es dazu, wenn einer abgewählten Domain Werte
  bleiben, die damit aus dem Frontend verschwinden. Nichts gewählt heißt alle
  Domains, alle gewählt wird als „keine Einschränkung" gespeichert — sonst
  verlöre ein Tab still jede später angelegte Domain.
  Drei Stellen folgen der Zuordnung bewusst **nicht**: die Prüfung „Datei in
  Verwendung?" für den Medienpool liest die Tabellen direkt, sonst ließe sich
  ein Logo löschen, das nach dem Wiederzuordnen gebraucht wird; das Kopieren
  auf der Seite Migration überspringt Tabs, die in der Zieldomain nicht gelten,
  statt dort unerreichbare Zeilen anzulegen; und `PATCH` über die REST-API darf
  weiter in eine noch nicht zugeordnete Domain schreiben — die Antwort weist
  das mit `meta.assigned` aus.
- Eigene Seite **Migration** für „Daten übertragen". Getrennt von den
  Einstellungen, weil sie keine ist: die Einstellungen beschreiben das
  Verhalten von jetzt an, diese Seite ändert gespeicherte Daten in einem Zug.
- Kontextzeile über den Tabs: Domain-Auswahl links (`selectpicker`),
  Sprachumschaltung rechts. Die Sprachumschaltung baut auf den Core-Fragmenten
  auf (`core/buttons/button_group.php`, ab vier Sprachen
  `core/dropdowns/dropdown.php`) und trägt `btn-clang` wie die Struktur-Seite.
- **Schutz vor YForm** auf den Tabellen dieses Addons: „Tabelle aktualisieren
  mit Feldlöschung" wird abgelehnt, bevor YForm die Aktion ausführt
  (`PAGE_CHECKED`), und der Versuch auf der Feldseite mit einem Hinweis
  quittiert. Der Knopf löscht (yform, `lib/manager/table/api.php`,
  `generateTableAndFields()` mit `$delete_old`) jede Spalte ohne Feld außer
  `id` — hier also genau die beiden Spalten, die bestimmen, welche Zeile
  gelesen und geschrieben wird. Zusätzlich nimmt ein `OUTPUT_FILTER` den Knopf
  und YForms Angebot, `domain_id` und `clang_id` in Felder zu verwandeln, von
  der Seite. Der Filter allein genügte nicht: YForm führt die Aktion schon
  beim Seitenaufbau aus, hinter nichts als einem CSRF-Token, das auf derselben
  Seite ohnehin an jedem anderen Link hängt.
- Neue Test-Suite `section-domains` (`lib/Tests/SectionDomainsSuite.php`) mit
  19 Prüfungen — darunter, dass ein nicht zugeordneter Tab im Frontend
  schweigt, dass seine Werte beim Wiederzuordnen zurückkommen, dass das
  Speichern der Zuordnung den Wert-Cache verwirft und dass eine nur dort
  benutzte Mediendatei weiter als „in Verwendung" gilt.

### Behoben

- Der Domainname in der Meldung „kein Tab für diese Domain" wird escapt.
  `rex_i18n::rawMsg()` tut das nicht selbst, und der Name ist freier Text aus
  yrewrite.
- `.phpstorm.meta.php` wird nicht mehr mit ausgeliefert. Die Datei entsteht aus
  den Feldnamen der Installation, auf der `domain-settings:ide-helper` lief,
  und hätte sonst fremde Vorschläge mitgebracht, bis sie neu erzeugt wird.
- Der Duplikat-Check für Feldnamen fragt erst, ob sich zwei Tabs überhaupt
  eine Domain teilen. Tabs auf verschiedenen Domains antworten nie für
  dieselbe Domain und dürfen denselben Feldnamen führen.
- Beim Zusammenführen mehrerer Tabs schlägt ein gefüllter Wert einen leeren
  aus einem anderen Tab. Vorher gewann der zuerst gelesene Tab, und eine
  einmal geöffnete, leere Zeile konnte echten Inhalt verdecken.
- Die Tab-Liste in den Einstellungen ist ein Raster statt einer Tabelle und
  bricht mit dem Fenster um: auf dem Telefon steht jede Spalte auf einer
  eigenen Zeile, ab 768 Pixeln stehen Name, Domains und Feldzahl nebeneinander
  mit den beiden Knöpfen darunter, ab 1400 Pixeln alles auf einer Zeile.
  Vorher lief die Tabellenzeile aus dem Panel heraus und der Löschen-Knopf war
  ab etwa 1000 Pixeln Fensterbreite abgeschnitten.

### Geändert

- Die Tab-Liste in den Einstellungen trägt **je Spalte eine Beschriftung**
  statt einer Kopfzeile über der Liste. Eine Kopfzeile fluchtet nur, solange
  die Spalten es tun, und musste deshalb auf dem Telefon verschwinden — dort,
  wo die Beschriftung am nötigsten ist.
- Der **IDE-Helper** wird auch beim Bearbeiten der Felder eines Tabs
  geschrieben, nicht mehr nur beim Leeren des Caches. Ein neu angelegtes Feld
  steht damit sofort in der Autovervollständigung. Wie bisher nur im
  Debug-Modus.
- Werte kommen über **einen** Lesepfad statt über zwei: `valuesFor()` las mit
  eigenem SQL, was `rowsByClang()` schon konnte. `getAll()` liest die
  Fallback-Sprache jetzt in derselben Abfrage mit und halbiert damit seine
  Queries.
- `Backend::resetCaches()` verwirft auch die aufgelöste Domain und Sprache.
  Sein Doc-Block sagt „alles, was diese Klasse für einen Request hält" — zwei
  Eigenschaften blieben bisher stehen.
- Die **Hilfeseite** ist von 2586 auf 736 Wörter gekürzt. Begründungen, warum
  etwas so gebaut ist, stehen im Changelog, nicht in der Hilfe.
- Entfernt, weil nichts sie erreicht: `Backend::getInheritedKeys()`, die
  lokale Variable `$clangIds` auf der Datenseite und die Sprachschlüssel
  `domain_settings_section_domains_notice` und `…_section_label_notice`.
- **Der Wert-Cache ist weg.** Werte kommen aus den Tabellen, wie YForm alles
  andere auch liest; gehalten werden sie nur innerhalb eines Requests, damit
  zwanzig `REX_DOMAIN_VALUE` in einem Template eine Abfrage bleiben. Damit
  entfällt die dokumentierte Einschränkung, dass eine Schemaänderung im Table
  Manager bis zum nächsten `cache:clear` alte Spalten lieferte — YForm bietet
  dafür keinen Extension Point, und ohne Datei braucht es keinen. Kostenpunkt
  0,11 ms statt 0,016 ms über vier Tabs; dafür wächst nichts mehr mit der
  Größe der Installation. `DomainSettings::deleteCache()` bleibt als Methode
  bestehen und verwirft jetzt das, was der Request gelesen hat. Das Update
  löscht die alte `values.json`.
- **Doppelte Feldnamen** meldet die Seite Einstellungen, statt sie beim
  Cache-Aufbau ins Log zu schreiben (`Backend::getDuplicateFieldNames()`).
- Die **Autovervollständigung** listet keine Felder mehr auf, die gar keine
  Spalte haben — etwa eine 1-n-Relation, deren Werte in der anderen Tabelle
  liegen und die `get()` nie beantworten kann.
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
- `console domain-settings:test` — 70 Prüfungen in acht Suiten.

### Kompatibilität

Kein Breaking Change: Datenstruktur, Legacy-Klassen (`yrewrite_domain_settings`,
`REX_DOMAIN_SETTING`) und die REST-Routen sind unangetastet,
`lib/Tests/LegacyApiSuite.php` bleibt grün. Es gibt keine Migration; die
Tab-zu-Domain-Zuordnung ist neu und leer, was „alle Domains" bedeutet und
damit dem Verhalten von 2.4.0 entspricht.

Einzige spürbare Änderung sind die Backend-URLs der Tabs, siehe „Geändert".

## [2.4.0] — nie veröffentlicht, vollständig in 2.5.0 enthalten

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
