# Ablösung von yrewrite_domain_settings

Notiz zur Frage, ob `globals` als Update für `yrewrite_domain_settings`
erscheinen könnte. Analysiert an Version 2.3.0.

## Warum das der leichtere Pfad ist

Anders als global_settings ist yrewrite_domain_settings **schon eine
YForm-Tabelle** mit `domain_id`. Es fehlt im Wesentlichen nur die
Sprachachse — die Feldkonfiguration der Kunden bleibt unangetastet.

| | yrewrite_domain_settings | globals |
|---|---|---|
| Speicher | YForm-Tabelle | YForm-Tabelle |
| Zeile je | Domain | Domain × Sprache |
| `domain_id` | YForm-Feld `choice`, db_type **text** | reine Spalte, `int` |
| Sprachen | keine | ja |
| Bereiche | keine | ja |

## Migrationsschritte

1. `clang_id` ergänzen, bestehende Zeilen auf `rex_clang::getStartId()`.
2. `domain_id` von `text` auf `int(10) unsigned` ziehen.
3. Das YForm-Feld `domain_id` (`choice`) entfernen — die **Spalte bleibt**,
   YForm löscht beim Feld-Entfernen keine Spalte. Die Domain wählt künftig die
   Seite, nicht das Formular.
4. **Den `unique`-Validator auf `domain_id` entfernen.** Kritisch: Er würde
   die zweite Sprache derselben Domain blockieren.
5. Unique-Index auf `(domain_id, clang_id)` setzen.

## Verhalten bleibt gleich

Der entscheidende Punkt: Bestehende Werte landen in der Startsprache, alle
anderen Sprachen erben sie über die Fallback-Kette. Für einsprachige
Installationen ändert sich dadurch **nichts**, für mehrsprachige liefert
`getValue()` weiterhin denselben Wert wie vorher — nur eben mit der
Möglichkeit, ihn je Sprache zu übersteuern.

## Kompatibilitätsschicht

Anders als bei global_settings gibt es hier **keinen Namenskonflikt**: Übernimmt
`globals` den Paketnamen, gehören die Klassen ihm.

| alt | Abbildung |
|---|---|
| `yrewrite_domain_settings::getValue($key)` | `Globals::get($key)` |
| `yrewrite_domain_settings::getValue()` (leer) | `Globals::getAll()` |
| `yrewrite_domain_settings::getAllowedDomains()` | `Backend::getDomains()` |
| `REX_DOMAIN_SETTING[key=…]` | bleibt, delegiert an `Globals::get()` |
| ComplexPerm `yrewrite_domains` | statt `globals_domains` beibehalten |

## Was dagegen spricht

- **Der Name passt nicht mehr.** Das Addon läuft ohne yrewrite, kann Sprachen
  und Bereiche — `yrewrite_domain_settings` beschreibt das nicht mehr.
- **Angehobene Anforderungen**: 2.3.0 verlangt REDAXO ^5.5 und YForm >3,<6,
  `globals` braucht ^5.17 und YForm ^5. Alte Installationen bekämen das Update
  schlicht nicht angeboten — kein Bruch, aber auch kein Weg für sie.
- **Tabellennamen der Bereiche** würden `rex_yrewrite_domain_settings_footer`
  heißen. Funktioniert, liest sich aber schlecht.

## Empfehlung

Technisch sauber machbar und ohne Bruch für Anwender. Die Frage ist keine
technische, sondern eine des Namens: Ein Addon, das Domain, Sprache und
Bereiche kann, sollte nicht nach dem Ding heißen, aus dem es hervorging.

Naheliegender wäre, `globals` eigenständig zu lassen und einen **Import** aus
yrewrite_domain_settings mitzuliefern — die Schritte oben sind dieselben, nur
schreibt der Import in eine neue Tabelle statt die alte umzubauen. Das
Altaddon kann dann als deprecated markiert werden, statt seinen Namen
weiterzutragen.
