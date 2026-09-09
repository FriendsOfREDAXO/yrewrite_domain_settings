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

`marcohanke/globals` steht noch; archivieren, sobald das erste Release aus
dieser Reihe draußen ist und stabil läuft. 2.4.0 wurde nie veröffentlicht, es
wird 2.5.0.

## Größere Themen, offen entschieden

### Import aus global_settings

Kommt nur in Frage, wenn dieses Addon auch als Nachfolger von
`global_settings` auftreten soll. Dort liegt ein eigenes Feldtypen-System
(`rex_global_settings_type`/`_field`), eine Wide-Table mit `clang` als
Primärschlüssel und PHP-Callbacks als Code-String in der Datenbank — ein
deutlich größerer Umbau als die Sprachachse hier. Handarbeit bliebe: Options-
Listen von select/radio/checkbox, Colorpicker (kein YForm-Gegenstück),
Callbacks. Pipe-getrennte Mehrfachwerte (`|a|b|`) müssten umgesetzt, der
`glob_`-Prefix aus den Feldnamen entfernt werden.

Der Platz dafür steht seit 2.5.0: Die Seite **Migration**
(`pages/migration.php`) gibt es, sie trägt bisher nur „Daten übertragen". Ein
Import gehörte dorthin — sie ist genau für das gemacht, was einmalig
gespeicherte Daten umschreibt, und hat CSRF-Schutz und Rechteprüfung schon
verdrahtet. Gebaut ist davon nichts.

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
entscheidet nur, wo ein Tab zur Bearbeitung angeboten wird, und rührt den
Lesepfad nicht an. Eine zweite Vererbungsachse bliebe ein eigener Umbau.

## Kleinere Punkte

- **Cache bei Schemaänderungen**: Feld löschen im Table Manager verwirft den
  Wert-Cache nicht. Bis `cache:clear` liefert `get()` den alten Spaltensatz.
  YForm hat dafür keinen Extension Point (`YFORM_GENERATE` feuert bei jedem
  Formularaufbau und wäre das falsche Signal).
- **Verwaiste Berechtigungen** nach dem Löschen eines Tabs aufräumen —
  bewusst offen gelassen, weil es Schreiben in fremde Rollen-Datensätze
  bedeutet. Die Domain-Zuordnung des Tabs wird beim Löschen dagegen
  mitentfernt.
- **`Backend.php`** ist mit rund 1400 Zeilen mit Abstand die größte Datei und
  macht inzwischen Domains, Sprachen, Tabs, deren Domain-Zuordnung und das
  Rendern der Kontextzeile. Teilen ist überfällig — der Schnitt liegt nahe:
  Tabs und ihre Zuordnung auf der einen Seite, das Rendern der Oberfläche auf
  der anderen.
- **Tab-Erkennung** läuft über das Tabellenpräfix plus Prüfung auf
  `domain_id`/`clang_id`. Eine von Hand angelegte Tabelle mit diesem Muster
  würde als Tab gelten.
- **Validatoren** laufen beim Speichern über den Datensatz — auf dem
  Lese-Cache-Pfad wird bewusst direkt per `rex_sql` gelesen.
- **`Backend::getInheritedKeys()`** hat seit 2.5.0 keinen Aufrufer mehr: Der
  Fallback-Hinweis nennt nur noch die Regel. Entweder wieder verwenden oder
  entfernen.
- **Alte Tab-Lesezeichen** laufen ins Leere und landen auf dem ersten Tab.
  Eine Umleitung von `page=yrewrite_domain_settings/<slug>` auf
  `…/data&section=<slug>` wäre machbar, hieße aber, jeden Slug wieder als
  Backend-Seite anzumelden — bewusst nicht gebaut.
- **Die Seite Migration ist leer**, wenn es nur eine Domain und eine Sprache
  gibt: Es gibt dann nichts zu kopieren. Sie erscheint trotzdem in der
  Navigation.

## Erledigt, nicht weiterverfolgen

- Tests ins Repo holen — erledigt: `console domain-settings:test`, 58
  Prüfungen in sieben Suiten unter `lib/Tests/`, Infrastruktur in `lib/Test/`.
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
