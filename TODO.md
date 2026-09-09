# Offene Punkte

Stand: 2.4.0 (unveröffentlicht). Nach Wichtigkeit sortiert.

## Vor einem Release

### CI

GitHub Action für php-cs-fixer (`composer cs-dry`) und PHPStan. Das Repo hatte
bis 2.4.0 eine `.travis.yml` für PHP 7.2 — entfernt, weil sie eine Version
prüfte, die das Addon nicht mehr unterstützt. Der Publish-Workflow
(`.github/workflows/publish-to-redaxo.yml`) bleibt und schiebt jeden Release
zu redaxo.org.

### Altes globals-Repo abschließen

`marcohanke/globals` steht noch; archivieren, sobald 2.4.0 draußen ist und
stabil läuft.

## Größere Themen, offen entschieden

### Import aus global_settings

Analyse liegt vor: `docs/migration-global-settings.md`. Kommt nur in Frage,
wenn dieses Addon auch als Nachfolger von `global_settings` auftreten soll —
dort steckt ein eigenes Feldtypen-System samt PHP-Callbacks in der Datenbank,
das ist ein deutlich größerer Umbau als die Sprachachse hier.

### Spezialfelder, die der template_manager hat

`opening_hours`, `social_links`, `external_linklist`, gezielte
Kategorieauswahl. Falls gewünscht, gehören sie als **eigene
YForm-Value-Types** gebaut, nicht als globals-interne Feldtypen — dann stehen
sie in jeder YForm-Tabelle zur Verfügung. Das wäre ein eigenes kleines Addon.

### Fallback-Domain

Bewusst nicht gebaut. Eine zweite Vererbungsachse macht „woher kommt dieser
Wert?" schwer beantwortbar. Falls das Nachpflegen bei vielen Domains später
stört, wäre die schonendere Variante, einzelne **Bereiche** als
domainübergreifend zu markieren — dann bleibt die Herkunft am Bereich
ablesbar. Das Kopieren von Werten deckt den Bedarf vorerst ab.

## Kleinere Punkte

- **Cache bei Schemaänderungen**: Feld löschen im Table Manager verwirft den
  Wert-Cache nicht. Bis `cache:clear` liefert `get()` den alten Spaltensatz.
  YForm hat dafür keinen Extension Point (`YFORM_GENERATE` feuert bei jedem
  Formularaufbau und wäre das falsche Signal).
- **Verwaiste Berechtigungen** nach dem Löschen eines Bereichs aufräumen —
  bewusst offen gelassen, weil es Schreiben in fremde Rollen-Datensätze
  bedeutet.
- **`Backend.php`** ist mit rund 660 Zeilen die größte Datei und macht
  inzwischen Domains, Sprachen und Bereiche. Bei weiterem Zuwachs teilen.
- **Panel-Footer** auf der Einstellungsseite ist uneinheitlich:
  `rex_config_form` legt seinen Speichern-Button in einen `panel-footer`,
  eigene Formulare nicht.
- **Bereichserkennung** läuft über das Tabellenpräfix plus Prüfung auf
  `domain_id`/`clang_id`. Eine von Hand angelegte Tabelle mit diesem Muster
  würde als Bereich gelten.
- **Validatoren** laufen beim Speichern über den Datensatz — auf dem
  Lese-Cache-Pfad wird bewusst direkt per `rex_sql` gelesen.

## Erledigt, nicht weiterverfolgen

- Tests ins Repo holen — erledigt: `console domain-settings:test`, 34
  Prüfungen in fünf Suiten unter `lib/Tests/`, Infrastruktur in `lib/Test/`.
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
