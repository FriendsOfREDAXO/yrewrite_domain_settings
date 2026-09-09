# Changelog

Alle nennenswerten Änderungen an diesem Addon.

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [Semantic Versioning](https://semver.org/lang/de/).

## [2.4.0] — unveröffentlicht

Das größte Update des Addons: Werte sind ab jetzt **je Sprache** pflegbar und
lassen sich in **Bereiche** aufteilen, die Pflege bekommt eine eigene Seite
statt der rohen YForm-Datenansicht, und der Lesepfad läuft über einen Cache.

**Bestehender Code läuft unverändert weiter.** Die API von 2.3.0 ist
vollständig erhalten, inklusive Rückgabetypen und Null-Fällen; acht Prüfungen
in der Suite `legacy` halten das fest. Details im
[README](README.md#umstieg-von-230).

### Hinzugefügt

- **Sprachen**: eine Zeile je Domain **und** Sprache. Nicht gefüllte Werte
  erben aus der Fallback-Sprache — derselbe Mechanismus deckt „noch nicht
  übersetzt" und „in allen Sprachen gleich" ab. Löst [#30][i30].
- **Bereiche**: die Daten lassen sich auf mehrere YForm-Tabellen aufteilen
  (`rex_yrewrite_domain_settings_footer` …). Damit greift YForms eigene
  Tabellenberechtigung, und jeder Bereich erscheint als Reiter.
- **Eigene Pflegeseite** mit Domain- und Sprachumschaltung, Anzeige geerbter
  Werte und aufklappbaren Feldgruppen (YForms `fieldset`, Zustand je
  Benutzer gespeichert). Die YForm-Datenansicht der Tabelle wird versteckt.
- **Domain-Rechte greifen auf die Daten**, nicht nur auf die Oberfläche: der
  ComplexPerm `yrewrite_domains` filtert, was zu sehen und zu ändern ist.
  Löst [#28][i28].
- **`DomainSettings::get()` / `::getAll()`** als neue API mit Standardwert,
  optionaler Domain und Sprache. Funktioniert ohne aktuellen Artikel, also
  auch in Cronjobs, Console-Commands und E-Mail-Templates.
- **`REX_DOMAIN_VALUE[key="…"]`** — die escapende Variante von
  `REX_DOMAIN_SETTING`, mit `output="html"` als bewusstem Opt-out.
- **Datei-Cache** über alle Domains und Sprachen, lazy beim ersten Zugriff.
  Ein Request, der Werte liest, kostet keine Query.
- **Werte übertragen** zwischen Domains und Sprachen, wahlweise für einen
  Bereich oder alle.
- **REST-API** über das api-Addon, sofern installiert: `GET
  /api/domain-settings`, `GET|PATCH /api/domain-settings/<bereich>`, je
  Bereich eigene Scopes für Lesen und Schreiben.
- **IDE-Unterstützung**: `console domain-settings:ide-helper` schreibt eine
  `.phpstorm.meta.php` mit allen Feldnamen; wird bei `cache:clear`
  automatisch aufgefrischt.
- **Medienschutz**: ein verwendetes Bild lässt sich nicht mehr aus dem
  Medienpool löschen (`MEDIA_IS_IN_USE`).
- **Schutz vor Datenverlust**: Sprachwechsel mit ungespeicherten Änderungen
  fragt nach; `beforeunload` als Netz für Zurück-Button und Tab schließen.
- **Debug-Platzhalter**: unbekannte Schlüssel liefern über die neue API im
  Debug-Modus `{{ key }}` statt still leer zu bleiben. Die alte API gibt
  weiterhin `null` zurück.
- **Tests**: `console domain-settings:test`, 34 Prüfungen in fünf Suiten
  (Vererbung, Bereiche, Cache, Sicherheitsregressionen, alte API).
  Console-Command statt PHPUnit, dem Vorbild von YForm folgend. Testdaten in
  einem eigenen Bereich mit eigener Domain-ID; redaktionelle Inhalte werden
  nicht angefasst.

### Behoben

- **Auf dem Startartikel einer Domain kamen keine Werte an.** Die Domain
  wurde über den aktuellen Artikel ermittelt statt über
  `rex_yrewrite::getCurrentDomain()`. Die Hilfskategorie „Startseite", die
  man sich dafür anlegen musste, ist damit überflüssig. [#35][i35], [#36][i36]
- **Fatal Error ohne aktuellen Artikel**: `getValue()` rief `getId()` auf
  `null` auf, etwa in der Console oder einem Cronjob. Jetzt kommt `null`.
- **Die Domain wurde einmal je Request zwischengespeichert** und konnte
  veralten. Der Singleton hält keinen Domain-Zustand mehr. [#36][i36]
- `getAllowedDomains()` lief ohne angemeldeten Benutzer in einen Fatal Error.

### Geändert

- Anforderungen: REDAXO ^5.17, PHP ^8.1, YForm ^5.0 (vorher REDAXO ^5.5,
  PHP >= 5.6, YForm > 3 < 6). Installationen darunter bekommen das Update
  nicht angeboten und bleiben auf 2.3.0.
- Die Tabelle ist in der YForm-Tabellenliste versteckt; gepflegt wird auf der
  Seite des Addons. Felder werden weiterhin im Table Manager angelegt.
- Das Formularfeld `domain_id` und der `unique`-Validator darauf entfallen —
  die Domain wählt die Seite. Die Spalte bleibt erhalten.

### Migration

Das Update wandelt die Tabelle beim Einspielen um: `clang_id` ergänzen
(vorhandene Zeilen auf die Startsprache), `domain_id` auf `int`, alten
Validator entfernen, Unique-Index auf `(domain_id, clang_id)`.
Datensatz-IDs bleiben unverändert. Gibt es mehrere Datensätze für dieselbe
Domain, bricht das Update ab und nennt sie, statt auf halbem Weg zu scheitern.

### Bekannte Einschränkungen

- Wird im Table Manager ein Feld gelöscht oder umbenannt, fällt der
  Wert-Cache nicht automatisch — YForm bietet keinen Extension Point für
  Schemaänderungen. `cache:clear` räumt auf.
- Berechtigungen auf einen gelöschten Bereich bleiben als tote Einträge in
  den Rollen zurück. Folgenlos; der YForm Table Manager verhält sich genauso.
- Der Wortlaut der Browserwarnung beim Verlassen der Seite lässt sich nicht
  beeinflussen — Browser ignorieren eigenen Text seit etwa 2016.

## Ältere Versionen

Bis 2.3.0 wurden Änderungen nur in den
[Releases](https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/releases)
festgehalten.

[i28]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/28
[i30]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/30
[i35]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/35
[i36]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/pull/36
