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

**Gebaut** am 2026-09-11, siehe CHANGELOG. Offen geblieben und bewusst so:

- **Callbacks** werden nicht importiert. YForms `action|php` wäre der
  naheliegende Weg, ist aber keiner: `rex_yform_base_abstract::getDefinitions()`
  liefert `[]` und `action/php` überschreibt das nicht, sodass
  `rex_yform_manager_dataset::createForm()` der Action nie Werte übergibt
  (`yform/lib/manager/dataset.php:701`). Ein Extension-Point-Listener wäre
  machbar, holt aber `eval` von importiertem Fremdcode ins AddOn — und ein
  Fehler darin blockiert künftig das Speichern der Domaineinstellungen. Der
  Code wird stattdessen in der Vorschau vollständig angezeigt.
- **SQL-Auswahllisten** werden unverändert übernommen und gemeldet. Automatisch
  umschreiben hieße SQL parsen; eine falsch umgeschriebene Abfrage fällt erst
  auf, wenn die Liste leer bleibt.
- **Feldweise Rechte** kennt das Ziel nicht, nur je Tab.
- **Tabs** aus `global_settings` werden übersprungen — hier ist ein Tab eine
  eigene Tabelle, das ist eine Strukturentscheidung des Nutzers.

Testplatz: `trash.localhost` (global_settings installiert, Testdatenset über
alle 16 Feldtypen).

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
