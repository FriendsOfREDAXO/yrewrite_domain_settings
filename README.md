# YRewrite Domain Settings

Globale Werte je Domain pflegen — Kontaktdaten, Logo, Footer-Text, Profil-Links.
Die Felder werden mit YForm definiert, ausgegeben werden die Werte über eine
PHP-API oder eine Template-Variable. Werte lassen sich zusätzlich je Sprache
pflegen und in mehrere Tabs aufteilen.

## Umstieg von 2.3.0

Das Update ist ein Klick, anzupassen gibt es nichts.

**Neu sind zwei Dinge:** Werte können je **Sprache** gepflegt werden, und die
Felder lassen sich auf mehrere **Tabs** verteilen. Beides ist optional. Wer
nichts einrichtet, arbeitet weiter wie bisher: ein Tab, eine Sprache.

**Im Frontend ändert sich nichts.** `yrewrite_domain_settings::getValue()`,
`getAllowedDomains()` und `REX_DOMAIN_SETTING` verhalten sich wie vorher,
inklusive der rohen, nicht escapten Ausgabe von `REX_DOMAIN_SETTING`. Neu
hinzugekommen ist `REX_DOMAIN_VALUE`, das standardmäßig escapt — für neuen
Code die bessere Wahl, die alte Variable bleibt erhalten.

Die Felder und ihre Inhalte bleiben unverändert, ebenso die vergebenen
Domain-Rechte. Was das Update im Einzelnen tut, steht im
[CHANGELOG](CHANGELOG.md).

## Daten

Die Seite, auf der Werte gepflegt werden. Oben wird die Domain gewählt, rechts
daneben die Sprache, darunter liegen die Tabs.

![Die Seite „Daten“: Domainauswahl, Sprachumschalter und die Felder eines Tabs](https://raw.githubusercontent.com/FriendsOfREDAXO/yrewrite_domain_settings/assets/data.png)

Domain- und Sprachauswahl erscheinen nur, wenn es etwas zu wählen gibt. Bei
einer Domain und einer Sprache zeigt die Seite direkt das Formular.

Ein leer gelassenes Feld wird aus der Fallback-Sprache derselben Domain
übernommen. Domains erben nicht voneinander — jede Domain steht für sich.

## Einstellungen

Ein **Tab** ist eine eigene YForm-Tabelle mit eigenen Feldern. Mehrere Tabs
trennen thematisch, was sonst in einem langen Formular stünde, und lassen sich
einzeln berechtigen: Wer eine Tabelle nicht bearbeiten darf, sieht den Tab
nicht.

![Die Seite „Einstellungen“: Tabs mit ihrer Domain-Zuordnung und das Fallback-Panel](https://raw.githubusercontent.com/FriendsOfREDAXO/yrewrite_domain_settings/assets/settings.png)

Jeder Tab kann **einer oder mehreren Domains zugeordnet** werden. Die Zuordnung
bestimmt, wo der Tab gilt: Dort wird er zur Bearbeitung angeboten, und nur dort
werden seine Werte im Frontend ausgegeben. Keine Auswahl bedeutet: alle
Domains. Nimmt man einem Tab eine Domain weg, bleiben die Werte gespeichert und
sind mit der Domain unverändert zurück.

Umbenennen ändert nur die Beschriftung, nicht den Tabellennamen — an ihm hängen
die Berechtigungen. Löschen entfernt die Tabelle samt Inhalt und erfolgt mit
Rückfrage; der Haupt-Tab lässt sich nicht löschen.

Im Panel **Fallback** wird die Sprache gewählt, aus der leere Werte bedient
werden. Führt eine Domain diese Sprache nicht, greift ihre Startsprache.

## Migration

Kopiert gepflegte Werte von einer Domain oder Sprache auf eine andere,
wahlweise für einen Tab oder für alle. Gedacht zum Aufsetzen einer neuen
Domain. Vorhandene Werte im Ziel werden überschrieben, deshalb mit Rückfrage.
Quelle und Ziel müssen Domain und Sprache sein, die der Benutzer ohnehin
bearbeiten darf.

## Verwendung im Frontend

```php
use FriendsOfRedaxo\DomainSettings\DomainSettings;

DomainSettings::get('footer_text');               // aktuelle Domain und Sprache
DomainSettings::get('footer_text', 'kein Text');  // mit Standardwert
DomainSettings::get('footer_text', null, 2, 1);   // explizit Domain 2, Sprache 1
DomainSettings::getAll();                         // alle Werte, für Fragmente
```

In Templates und Modulen als Variable:

```
REX_DOMAIN_VALUE[key="footer_text"]
REX_DOMAIN_VALUE[key="footer_html" output="html"]
```

`REX_DOMAIN_VALUE` escapt die Ausgabe standardmäßig, `output="html"` gibt sie
roh aus. `DomainSettings::get()` liefert immer den rohen Wert; dort setzt der
Aufrufer `rex_escape()` selbst.

Ist ein Schlüssel unbekannt, kommt `null` zurück beziehungsweise der übergebene
Standardwert. Im Debug-Modus erscheint stattdessen ein sichtbares `{{ key }}`.

Die API benötigt weder einen aktuellen Artikel noch yrewrite und funktioniert
deshalb auch in Cronjobs, Console-Commands und E-Mail-Templates.

## Rechte

- `yrewrite_domain_settings[]` — darf Werte bearbeiten
- Domains über `yrewrite_domains` im Benutzerprofil
- Sprachen über die REDAXO-Sprachrechte (`clang`)
- Tabs über YForms `yform_manager_table_edit`

Ohne Berechtigung für mindestens eine Domain und einen Tab bleibt die Seite
gesperrt. **Einstellungen**, **Migration** und **Hilfe** sind Administratoren
vorbehalten.

## REST-API (optional)

Mit installiertem [api-Addon](https://github.com/FriendsOfREDAXO/api) stehen die
Werte über HTTP bereit, mit aufgelöster Fallback-Kette:

```
GET   /api/domain-settings              alle Tabs
GET   /api/domain-settings/<tab>        ein Tab
PATCH /api/domain-settings/<tab>        Werte ändern
      ?domain_id=1&clang_id=2           beides optional
```

```json
{
  "data": { "company_slogan": "Wir bauen Sägen", "logo": "logo.svg" },
  "meta": { "domain_id": 1, "clang_id": 2 }
}
```

Ohne Parameter gelten die erste Domain und die Startsprache. Ein Tab, der der
angefragten Domain nicht zugeordnet ist, liefert nichts; `meta.assigned` sagt,
ob ein leeres `data` daran liegt.

## IDE-Unterstützung

`console domain-settings:ide-helper` schreibt eine `.phpstorm.meta.php` mit
allen Feldnamen, sodass `DomainSettings::get()` sie vervollständigt.

Im Debug-Modus geschieht das automatisch: beim Leeren des Caches und beim
Bearbeiten der Felder eines Tabs im YForm Table Manager. Ein neu angelegtes
Feld steht damit sofort zur Verfügung.

## Anforderungen

- REDAXO ^5.17
- PHP ^8.1
- YForm ^5.0
- yrewrite ^2.5

Ohne yrewrite oder ohne angelegte Domain läuft alles auf Domain 0 — das Addon
verhält sich dann wie eine Sammlung globaler Werte.

## Entwicklung

`console domain-settings:test` führt die Testsuiten aus. Offene Punkte stehen
in [TODO.md](TODO.md), Änderungen im [CHANGELOG](CHANGELOG.md).

## Bugtracker

[Issue anlegen](https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues)

## Autor

**Friends Of REDAXO**

* https://www.redaxo.org
* https://github.com/FriendsOfREDAXO

## Projekt-Lead

* [Marco Hanke](https://github.com/marcohanke)

## Credits

Danke an:

* [Daniel Steffen](https://github.com/novinet-dsteffen) // first release

## Lizenz

MIT — siehe [LICENSE](LICENSE)
