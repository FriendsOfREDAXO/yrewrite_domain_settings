# Changelog

Alle nennenswerten Änderungen an diesem Addon.

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [Unveröffentlicht] — 0.1.0-dev

Erste Entwicklungsfassung. Kern und Oberfläche funktionieren und sind
getestet; die API kann sich bis 1.0 noch ändern.

### Hinzugefügt

- **Speicher**: eine YForm-Tabelle je Bereich, eine Zeile je Domain und
  Sprache. Felder werden im YForm Table Manager gepflegt, ohne
  Namenskonvention oder Präfix.
- **Lese-API** `Globals::get()` / `Globals::getAll()` mit Fallback auf die
  Fallback-Sprache. Funktioniert ohne aktuellen Artikel und ohne yrewrite,
  also auch in Cronjobs, Console-Commands und E-Mail-Templates.
- **Datei-Cache** über alle Domains und Sprachen, lazy beim ersten Zugriff
  geladen. Ein Request, der Werte liest, kostet keine Query.
- **`REX_GLOBALS[key="…"]`** für Templates und Module, standardmäßig
  escaped; `output="html"` als bewusster Opt-out.
- **Bereiche**: je Bereich eine eigene YForm-Tabelle und damit YForms eigene
  Tabellenberechtigung. Anlegen, umbenennen und löschen unter Einstellungen;
  jeder Bereich erscheint als Reiter in der Backend-Navigation.
- **Rechte**: Sprachen über den Core-ComplexPerm `clang`, Domains über einen
  eigenen ComplexPerm `globals_domains`, Bereiche über YForms
  `yform_manager_table_edit`.
- **Werte übertragen** zwischen Domains und Sprachen, wahlweise für einen
  Bereich oder alle.
- **REST-API** über das api-Addon, sofern installiert: `GET /api/globals`,
  `GET|PATCH /api/globals/<bereich>`, je Bereich eigene Scopes für Lesen und
  Schreiben. Liefert aufgelöste Werte statt roher Zeilen.
- **IDE-Unterstützung**: `console globals:ide-helper` schreibt eine
  `.phpstorm.meta.php` mit allen Feldnamen; wird bei `cache:clear`
  automatisch aufgefrischt.
- **Aufklappbare Feldgruppen** über YForms `fieldset`, Zustand pro Benutzer
  gespeichert.
- **Schutz vor Datenverlust**: Sprachwechsel mit ungespeicherten Änderungen
  fragt nach (eigener Dialog), `beforeunload` als Netz für Zurück-Button und
  Tab schließen.
- **Medienschutz**: ein verwendetes Bild lässt sich nicht aus dem Medienpool
  löschen (`MEDIA_IS_IN_USE`).
- **Debug-Platzhalter**: unbekannte Schlüssel liefern im Debug-Modus
  `{{ key }}` statt still leer zu bleiben.
- **Tests**: `console globals:test`, 26 Prüfungen in vier Suiten (Vererbung,
  Bereiche, Cache, Sicherheitsregressionen). Console-Command statt PHPUnit,
  dem Vorbild von YForm folgend. Testdaten in einem eigenen Bereich mit
  eigener Domain-ID; redaktionelle Inhalte werden nicht angefasst.

### Behoben

Während der Entwicklung gefunden, hier festgehalten, weil die Ursachen nicht
offensichtlich waren:

- Speichern in einer Sprache überschrieb eine andere. YForm postet auf ein
  blankes `index.php`; ohne `form_action_query_params` erreichten
  `domain_id`/`clang_id` den Request nicht und fielen auf ihre Standardwerte
  zurück.
- Ein Benutzer mit `globals[]`, aber ohne Domain-Berechtigung, fiel über
  `array_key_first([])` auf Domain 0 durch und hatte dort Schreibzugriff.
- `REX_GLOBALS` gab den rohen Datenbankwert aus — ein Redakteur konnte damit
  Markup auf jede Seite bringen, die den Wert liest.
- Sprach-Tabs blieben im Dark Mode weiß: be_style stylt native
  Bootstrap-Tabs bewusst nicht, nur die Variante mit `btn`-Klassen.
- Ein ungültiger CSRF-Token führte kommentarlos zu gar nichts.
- Das Umbenennen-Formular stand als `<form>` in einem `<tr>` — ungültiges
  Markup, das Browser entfernen; es hätte nie abgesendet.

### Bekannte Einschränkungen

- Wird im Table Manager ein Feld gelöscht oder umbenannt, fällt der
  Wert-Cache nicht automatisch — YForm bietet keinen Extension Point für
  Schemaänderungen. `cache:clear` räumt auf.
- Berechtigungen auf einen gelöschten Bereich bleiben als tote Einträge in
  den Rollen zurück. Folgenlos; der YForm Table Manager verhält sich genauso.
- Der Wortlaut der Browserwarnung beim Verlassen der Seite lässt sich nicht
  beeinflussen — Browser ignorieren eigenen Text seit etwa 2016.
