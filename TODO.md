# Offene Punkte

Stand: 0.1.0-dev. Nach Wichtigkeit sortiert.

## Vor einem Release

### CI

GitHub Action für php-cs-fixer (`composer cs-dry`) und PHPStan. Beim Umzug
nach FriendsOfREDAXO ohnehin fällig.

## Größere Themen, offen entschieden

### Import aus Altsystemen

Analysen liegen vor: `docs/migration-global-settings.md` und
`docs/migration-yrewrite-domain-settings.md`. Kommt nur in Frage, wenn das
Addon als Nachfolger eines der beiden erscheint. `yrewrite_domain_settings`
ist der leichtere Pfad — schon eine YForm-Tabelle, es fehlt nur die
Sprachachse.

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
- **`Backend.php`** ist mit rund 500 Zeilen die größte Datei und macht
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

- Tests ins Repo holen — erledigt: `console globals:test`, 26 Prüfungen in
  vier Suiten unter `lib/Tests/`, Infrastruktur in `lib/Test/`. Nachgewiesen,
  dass sie echte Fehler fangen (Escaping testweise entfernt → zwei
  Fehlschläge) und redaktionelle Daten unberührt lassen.

- Zwei getrennte Tabellen für sprachneutrale und übersetzbare Werte —
  eingerissen, der Fallback leistet dasselbe.
- Eigener YForm-Value-Type für Feldgruppen — YForms `fieldset` plus CSS/JS
  reicht und hängt nicht an YForm-Internas.
- Rohes SQL auf den Schreibpfaden — ersetzt durch die Datensatz-Methoden.
