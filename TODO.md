# Offene Punkte

Stand: 2.5.0 (unveröffentlicht). Nach Wichtigkeit sortiert.

## Vor einem Release

### CI

GitHub Action für php-cs-fixer (`composer cs-dry`) und PHPStan. Das Repo hatte
bis 2.4.0 eine `.travis.yml` für PHP 7.2 — entfernt, weil sie eine Version
prüfte, die das Addon nicht mehr unterstützt. Der Publish-Workflow
(`.github/workflows/publish-to-redaxo.yml`) bleibt und schiebt jeden Release
zu redaxo.org.

### Altes globals-Repo abschließen

Erledigt am 2026-09-11: `marcohanke/globals` ist gelöscht, lokaler Checkout
und Symlink ebenso. 2.4.0 wurde nie veröffentlicht, das erste Release dieser
Reihe ist 2.5.0.

## Größere Themen, offen entschieden

### Import aus global_settings

Analysiert am 2026-09-11, Konzept steht, gebaut ist nichts. Eigener Branch
nach dem 2.5.0-Release.

**Korrektur zur früheren Einschätzung:** `global_settings` **ist**
mehrsprachig — eine Zeile je `clang` (`_install.sql:28-31`), also 1:1 auf
`clang_id` abbildbar. Genau die Sprachachse galt als Hauptgrund für „größerer
Umbau"; der Aufwand ist **mittel**. Ebenfalls korrigiert: Callbacks sind
migrierbar. YForm führt PHP-Code aus der Felddefinition selbst aus
(`yform/lib/Field/action/php.php`, `eval('?>' . $php . '<?php ')`), Actions
sind reguläre Tabellenfelder (`rex_yform_manager_field::$types`) und laufen
nach erfolgreichem Speichern (`yform/lib/yform.php:453`) — dasselbe Timing wie
`fireCallbacks()` drüben.

Automatisch: alle einwertigen Felder, Media- und Link-Listen (beide Seiten
komma-separiert), Datum/Zeit (Unix-Timestamp → SQL, `0` = NULL), boolesche
Checkbox (`|true|` → `1`), Mehrfachwerte (`|a|b|` → `a,b`), `glob_`-Prefix
strippen, Optionslisten in Pipe-Form.

**Falle:** `global_settings` schreibt `wert:Label`, YForms `choice` erwartet
JSON `{"Label":"wert"}` — vertauschte Reihenfolge. Wer sie dreht, macht alle
gespeicherten Werte tot.

Handarbeit bleibt: SQL-Optionslisten (YForm will benannte Spalten
`value`/`label`, global_settings liest Spalte 0/1 — automatisch nur bei
eindeutigen Queries, sonst melden), Colorpicker (Wert wandert als Text, das
Widget hat kein Gegenstück), feldweise Rechte (Ziel kennt sie nur je Tab),
selbst registrierte Feldtypen (`rex_global_settings_add_field_type()`).

**Geplante Oberfläche** auf der Seite Migration, sichtbar nur bei
installiertem `global_settings`: Ziel-Auswahl (Standardtabelle oder ein
vorhandener Tab oder ein neuer) mit Warnung „bestehende Daten in dieser
Tabelle werden gelöscht", Hauptdomain vorausgewählt, Sprachen 1:1. Erst eine
**Vorschau** (Feld, Quelltyp → Zieltyp, was Handarbeit braucht, Callbacks im
Klartext), dann der schreibende Schritt. Callbacks werden **aktiv**
importiert — sie laufen heute auch —, der Code landet als Datei unter
`redaxo/data/addons/yrewrite_domain_settings/callbacks/`, die Action trägt
Präambel (`$fieldName`, `$fieldValue`) und `require`. Aufrufe von
`rex_global_settings::` im Callback-Code meldet die Vorschau: Sie lesen nach
dem Import weiter die alten Daten und brechen, sobald das Addon deinstalliert
wird.

Testplatz: `trash.localhost`.

### Spezialfelder, die der template_manager hat

`opening_hours`, `social_links`, `external_linklist`, gezielte
Kategorieauswahl. Falls gewünscht, gehören sie als **eigene
YForm-Value-Types** gebaut, nicht als globals-interne Feldtypen — dann stehen
sie in jeder YForm-Tabelle zur Verfügung. Das wäre ein eigenes kleines Addon.

### Fallback-Domain

Bewusst nicht gebaut. Eine zweite Vererbungsachse macht „woher kommt dieser
Wert?" schwer beantwortbar. Falls das Nachpflegen bei vielen Domains später
stört, wäre die schonendere Variante, einzelne **Tabs** als
domainübergreifend zu markieren — dann bleibt die Herkunft am Tab
ablesbar. Das Kopieren von Werten deckt den Bedarf vorerst ab.

Die Tab-zu-Domain-Zuordnung aus 2.5.0 ist **nicht** dieser Schritt: Sie
entscheidet, wo ein Tab gilt — Bearbeitung und Ausgabe gleichermaßen —, aber
sie vererbt nichts zwischen Domains. Eine zweite Vererbungsachse bliebe ein
eigener Umbau.

## Kleinere Punkte

- **Verwaiste Berechtigungen** nach dem Löschen eines Tabs aufräumen —
  bewusst offen gelassen, weil es Schreiben in fremde Rollen-Datensätze
  bedeutet. Die Domain-Zuordnung des Tabs wird beim Löschen dagegen
  mitentfernt.
- **`Backend.php`** ist mit rund 1370 Zeilen mit Abstand die größte Datei und
  macht inzwischen Domains, Sprachen, Tabs, deren Domain-Zuordnung und das
  Rendern der Kontextzeile. Teilen ist überfällig — der Schnitt liegt nahe:
  Tabs und ihre Zuordnung auf der einen Seite, das Rendern der Oberfläche auf
  der anderen.
- **Tab-Erkennung** läuft über das Tabellenpräfix plus Prüfung auf
  `domain_id`/`clang_id`. Eine von Hand angelegte Tabelle mit diesem Muster
  würde als Tab gelten.
- **Validatoren** laufen beim Speichern über den Datensatz — auf dem
  Lese-Cache-Pfad wird bewusst direkt per `rex_sql` gelesen.
- **Alte Tab-Lesezeichen** werden umgeleitet: `PAGES_PREPARED` meldet jeden
  Slug als versteckte Seite an, `pages/redirect.php` schickt weiter auf
  `…/data&section=<slug>`. Kostet eine Seitenanmeldung pro Tab — ohne sie
  würde der Controller eine unbekannte Seite zur Startseite schicken.
- **Die Seite Migration ist leer**, wenn es nur eine Domain und eine Sprache
  gibt: Es gibt dann nichts zu kopieren. Sie erscheint trotzdem in der
  Navigation.

## Erledigt, nicht weiterverfolgen

- Tests ins Repo holen — erledigt: `console domain-settings:test`, 70
  Prüfungen in acht Suiten unter `lib/Tests/`, Infrastruktur in `lib/Test/`.
  Aufbau wie bei YForm: Console-Commands gegen die laufende Installation,
  ausgeliefert statt vom Release ausgeschlossen.
  Nachgewiesen, dass sie echte Fehler fangen: beim Schreiben der
  Legacy-Suite fielen zwei auf, die sonst erst im Feld aufgefallen wären.

- Als Update von `yrewrite_domain_settings` erscheinen statt als eigenes
  Addon — erledigt mit 2.4.0. Die Kompatibilitätsschicht liegt in
  `lib/class.yrewrite_domain_settings.php`, die Schemawandlung in
  `migrate.php`.

- Zwei getrennte Tabellen für sprachneutrale und übersetzbare Werte —
  eingerissen, der Fallback leistet dasselbe.
- Eigener YForm-Value-Type für Feldgruppen — YForms `fieldset` plus CSS/JS
  reicht und hängt nicht an YForm-Internas.
- Rohes SQL auf den Schreibpfaden — ersetzt durch die Datensatz-Methoden.
