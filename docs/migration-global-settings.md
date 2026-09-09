# Migration aus global_settings

Notiz, kein Fahrplan: relevant nur, falls `globals` einmal als Nachfolger von
`global_settings` erscheint. Analysiert an global_settings 2.8.8.

## Was dort liegt

| Tabelle | Inhalt |
|---|---|
| `rex_global_settings_type` | 16 feste Feldtypen (id → label → dbtype) |
| `rex_global_settings_field` | Definitionen: `name` (mit `glob_`-Prefix), `title`, `type_id`, `priority`, `params`, `attributes`, `default`, `validate`, `callback` |
| `rex_global_settings` | Wide-Table, PK `clang`, **eine Spalte je Feld** |

Die Sprachzuordnung ist damit schon vorhanden: jede `clang`-Zeile wird eine
Zeile in `rex_globals`. Domains kennt global_settings nicht — alles landet auf
der ersten Domain bzw. auf 0.

## Typ-Mapping

| global_settings | globals (YForm) |
|---|---|
| text, textarea | `text`, `textarea` |
| REX_MEDIA_WIDGET / _MEDIALIST_ | `be_media` / `be_medialist` |
| REX_LINK_WIDGET / _LINKLIST_ | `be_link` |
| date, time, datetime | `date`, `time`, `datetime` |
| select, radio, checkbox | `choice` |
| legend, tab | `fieldset` |
| colorpicker, rgbacolorpicker | kein Gegenstück |

## Was Handarbeit bleibt

- **Options-Listen** von select/radio/checkbox stecken in `params`/`attributes`
  und müssen ins YForm-Format übersetzt werden.
- **Colorpicker** haben kein YForm-Äquivalent; `text` oder MForms
  `color_swatch` (neue Abhängigkeit).
- **Callbacks** liegen als PHP-Code-String in der Feldtabelle. Kein Gegenstück,
  muss jemand ansehen.

## Fallstricke

- Mehrfachwerte speichert global_settings **pipe-getrennt** (`|a|b|`, ältere
  Datensätze `|+|`). Beim Übernehmen nach `be_medialist` umsetzen.
- `glob_`-Prefix aus den Feldnamen entfernen. Achtung: Die Backend-Oberfläche
  von global_settings **verbietet** die Eingabe des Prefixes, die PHP-Funktion
  `rex_global_settings_add_field()` **verlangt** ihn — in der DB steht er immer.
- global_settings kennt bereits Rechte pro Feld und pro Tab über ein `perm` in
  den `attributes`, geprüft beim Rendern *und* beim Speichern
  (`lib/handler/handler.php`). Das entspricht hier den Bereichen.

## Kompatibilitätsschicht

`rex_global_settings::getValue()` und `REX_GLOBAL_VAR` ließen sich nachbilden,
aber die Klassennamen `rex_global_settings` und `rex_var_global_var` gehören
dem alten Addon. Solange beide installiert sind, entscheidet die
Autoload-Reihenfolge — keine Grundlage für eine Shim. Deshalb: nur greifen,
wenn global_settings **nicht** installiert ist, am besten als eigenes Plugin
`globals/compat`, das nach der Umstellung deaktiviert werden kann. Signaturen
sind abbildbar, inklusive `getString()`, das bei leerem Wert `{{ feldname }}`
liefert.

Aufwand grob: Import-Command 150–200 Zeilen, Shim 60–80.
