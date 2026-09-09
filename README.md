# YRewrite Domain Settings

Zusatz- und Metainformationen je Domain — Footer, Kontaktdaten, Logo,
Profil-Links. Auf Basis von YForm, ohne eigene Feldtypen-Welt.

Seit **2.4.0** sind die Werte zusätzlich **je Sprache** pflegbar und lassen
sich in **Tabs** aufteilen. Bestehender Code läuft unverändert weiter —
siehe [Umstieg von 2.3.0](#umstieg-von-230).

**2.5.0** baut die Oberfläche um: Domain, Tab und Sprache stehen jetzt
gemeinsam auf einer Seite, und ein Tab lässt sich einzelnen Domains zuordnen.
An den Daten und an der API ändert sich dabei nichts — nur die Backend-URLs
der Tabs, siehe [CHANGELOG](CHANGELOG.md).

## Idee

Eine ganz normale YForm-Tabelle mit **einer Zeile je Domain und Sprache**.
Felder legst du im YForm Table Manager an, mit dem vollen Funktionsumfang von
YForm. Es gibt keine Namenskonvention, kein Präfix und keine Suffixe: eine neue
Sprache ist ein `INSERT`, kein `ALTER TABLE`.

**Tabs** teilen die Daten auf — Header, Footer, Kontakt. Jeder Tab ist
eine eigene YForm-Tabelle (`rex_yrewrite_domain_settings`, `rex_yrewrite_domain_settings_footer`, …). Das ist
Absicht: Dadurch greift YForms eigene Tabellenberechtigung, und jeder neue
Tab erscheint von selbst im Rollen-Formular unter „YForm: Tabellen
bearbeiten". Wer den Footer nicht ändern darf, sieht den Tab nicht.

Die Tabs teilen sich **einen Schlüsselraum**: `DomainSettings::get('footer_text')`
findet den Wert, egal in welchem Tab das Feld liegt. Der Zugriff im
Template ändert sich also nicht, wenn du ein Feld später in einen anderen
Tab verschiebst. Der Preis: Ein Feldname darf nur einmal vergeben werden;
kommt er in zwei Tabs vor, landet eine Warnung im `system.log`. Das gilt nur
für Tabs, die sich eine Domain teilen — zwei Tabs, die auf verschiedenen
Domains angeboten werden, antworten nie für dieselbe Domain und dürfen
denselben Feldnamen führen.

**Welche Sprachen eine Domain hat, sagt yrewrite**, nicht der REDAXO-Kern:
Führt eine Domain nur Deutsch und Englisch und eine zweite zusätzlich
Französisch, bietet jede genau ihre eigenen an. Werte in einer Sprache zu
pflegen, die dort nie ausgeliefert wird, ist damit ausgeschlossen. Auch der
Fallback bleibt in der Domain: Ist die eingestellte Fallback-Sprache dort
nicht vorhanden, übernimmt die Startsprache der Domain.

Sprachneutrale Werte wie Logo oder Adresse brauchen keine Sonderbehandlung —
sie werden in der Fallback-Sprache gepflegt und von allen anderen geerbt. Das
ist derselbe Mechanismus, der auch „noch nicht übersetzt" abdeckt.

## Umstieg von 2.3.0

Das Update ist ein Klick. Es gibt **nichts anzupassen** — weder im Template
noch im Modul noch in eigenen Klassen.

### Was gleich bleibt

| | |
|---|---|
| `yrewrite_domain_settings::getValue($key)` | liefert denselben Wert wie vorher |
| `yrewrite_domain_settings::getValue()` | liefert weiterhin die ganze Zeile, `id` und `domain_id` inklusive |
| `yrewrite_domain_settings::getAllowedDomains()` | unverändert, gleiche Array-Form |
| `REX_DOMAIN_SETTING[key=…]` | unverändert, weiterhin **ohne** Escaping |
| Rolle → Domains | derselbe ComplexPerm `yrewrite_domains`, vergebene Rechte bleiben |
| Deine Felder | bleiben, wo sie sind, samt Inhalt und Datensatz-ID |

Alle sechs Punkte sind als Prüfungen hinterlegt und laufen bei jedem
`domain-settings:test` mit.

### Was das Update an der Tabelle tut

1. Spalte `clang_id` ergänzen; vorhandene Zeilen bekommen die Startsprache.
2. `domain_id` von `text` auf `int` ziehen.
3. Das Formularfeld `domain_id` entfernen — die Domain wählt ab jetzt die
   Seite, nicht das Formular. Die **Spalte bleibt**.
4. Den `unique`-Validator auf `domain_id` entfernen; er würde die zweite
   Sprache derselben Domain abweisen.
5. Unique-Index auf `(domain_id, clang_id)` setzen.

Datensatz-IDs bleiben dabei unangetastet — Fremdtabellen, die darauf
verweisen, funktionieren weiter. Gibt es mehrere Datensätze für dieselbe
Domain, bricht das Update ab und sagt welche; aufräumen und erneut starten.

### Was sich verhält wie vorher — nur richtig

Die Domain wird jetzt über `rex_yrewrite::getCurrentDomain()` ermittelt statt
über den aktuellen Artikel. Damit liefert das Addon endlich auch auf dem
**Startartikel** einer Domain Werte ([#35][i35], [#36][i36]) — die
Hilfskategorie „Startseite", die man sich dafür anlegen musste, kann weg. Und
ohne aktuellen Artikel — im Cronjob, in der Console — gibt es statt eines
Fatal Errors schlicht `null`.

### Einsprachige Installationen

Für sie ändert sich gar nichts: Alle Werte liegen in der Startsprache, und
genau die werden ausgeliefert.

### Mehrsprachige Installationen

Alle Sprachen erben zunächst die vorhandenen Werte. Wo etwas übersetzt werden
soll, trägt man es in der jeweiligen Sprache ein — der Rest bleibt vererbt.
Ein Feld, das in allen Sprachen gleich ist (Logo, Adresse), pflegt man
weiterhin nur einmal.

Angeboten werden je Domain nur die Sprachen, die in yrewrite für sie
hinterlegt sind.

Wer sich die Sprachachse bisher selbst gebaut hat — etwa eine zweite Tabelle,
die per Fremdschlüssel an der Datensatz-ID hängt — behält sie funktionsfähig:
Die IDs bleiben, der Verweis zeigt weiter auf die Zeile der Startsprache. Sie
wird durch dieses Update lediglich überflüssig.

### Escaping: die alte und die neue Variable

`REX_DOMAIN_SETTING` gibt den Wert **roh** aus, wie seit jeher — Templates
speichern dort Markup, etwa einen Adressblock mit `<br>`. Neu ist
`REX_DOMAIN_VALUE`, das standardmäßig escaped und mit `output="html"` bewusst
nicht. Für neuen Code ist die neue Variable die richtige Wahl; die alte bleibt
erhalten und wird nicht entfernt.

[i35]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues/35
[i36]: https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/pull/36

## Verwendung im Frontend

```php
use FriendsOfRedaxo\DomainSettings\DomainSettings;

DomainSettings::get('footer_text');               // aktuelle Domain und Sprache
DomainSettings::get('footer_text', 'kein Text');  // mit Standardwert
DomainSettings::get('footer_text', null, 2, 1);   // explizit Domain 2, Sprache 1
DomainSettings::getAll();                         // alles, für Fragmente
```

In Templates und Modulen auch als Variable:

```
REX_DOMAIN_VALUE[key="footer_text"]
REX_DOMAIN_VALUE[key="footer_html" output="html"]
```

`REX_DOMAIN_VALUE` **escapt die Ausgabe standardmäßig** — wie `REX_VALUE` im Core.
Wer bewusst HTML ausgeben will, setzt `output="html"`. Die PHP-API
`DomainSettings::get()` liefert dagegen den rohen Wert; dort muss der Aufrufer selbst
`rex_escape()` einsetzen.

**Fallback:** Ein leerer Wert wird aus der Fallback-Sprache derselben Domain
übernommen. Leer heißt `''` oder `null` — aber **nicht** `'0'`, damit sich eine
Checkbox in einer einzelnen Sprache abschalten lässt. Domains erben *nicht*
voneinander: jede Domain ist eigenständig.

Existiert der Schlüssel nirgends, kommt `null` zurück (bzw. der übergebene
Standardwert). Läuft REDAXO im Debug-Modus, stattdessen ein sichtbares
`{{ key }}` — so fallen Tippfehler beim Bauen auf, statt still eine leere
Stelle im Frontend zu hinterlassen.

Die API braucht weder einen aktuellen Artikel noch yrewrite und funktioniert
deshalb auch in Cronjobs, Console-Commands und E-Mail-Templates.

## Bedienung

Die Navigation hat vier feste Punkte: **Daten**, **Einstellungen**,
**Migration** und **Hilfe**. Gepflegt wird auf **Daten**; die drei anderen
sind Administratoren vorbehalten.

Oben auf der Datenseite steht die Kontextzeile — links die Domain (nur
sichtbar, wenn yrewrite mehr als eine kennt), rechts die Sprache, umgeschaltet
wie auf der Struktur-Seite und ab vier Sprachen als Dropdown. Darunter liegen
die Tabs als Reiter, darunter das Formular.

Domain und Sprache sind der Kontext und bleiben in der Session: Wer die Seite
verlässt und zurückkommt, ist wieder dort, wo er aufgehört hat. Der Tab steht
dagegen als `section=<slug>` in der URL — er ist, wo man auf der Seite ist,
nicht das, worum es geht. Welche Sprachen zur Wahl stehen, entscheidet
yrewrite je Domain.

Ist die gewählte Sprache nicht die Fallback-Sprache, steht über dem Formular,
dass leere Felder von dort erben. Der Hinweis nennt die Regel und nicht die
gerade betroffenen Felder: Er gilt auch dann, wenn zufällig kein Feld leer ist,
und ein Hinweis, der kommt und geht, ist einer, auf den sich niemand verlässt.

Ein `fieldset`-Feld wird im Formular zu einer aufklappbaren Gruppe; ihr Zustand
bleibt pro Benutzer gespeichert.

Sprachen sind getrennte Datensätze: Werte in einer Sprache zu ändern lässt die
anderen unberührt. Wer die Seite mit ungespeicherten Änderungen verlässt —
anderer Tab, andere Domain, Felder bearbeiten, Einstellungen, Hilfe —, bekommt
einen Dialog mit drei Optionen: speichern und weiter, verwerfen und weiter,
oder hier bleiben. Für die Wege, die keine Links sind (Zurück-Button, Tab
schließen), greift zusätzlich die Standardwarnung des Browsers — deren Wortlaut
lässt sich nicht beeinflussen, den geben die Browser seit Jahren fest vor.

### Einstellungen

Drei Panels: **Neuer Tab** zum Anlegen, **Vorhandene Tabs** zum Umbenennen,
Zuordnen und Löschen samt Sprung in den YForm Table Manager, und **Fallback**
für die Sprache, aus der leere Werte bedient werden.

Jeder Tab lässt sich **einer oder mehreren Domains zuordnen** — schon beim
Anlegen und später in der Liste. Das ist ein reiner Ansichtsfilter: Er
bestimmt, wo ein Tab zur Bearbeitung angeboten wird, und sonst nichts.
Bereits gepflegte Werte bleiben in der Tabelle und werden im Frontend weiter
ausgeliefert; nimmt man einem Tab eine Domain weg, auf der Werte stehen, sagt
das Speichern es dazu. Nichts gewählt heißt alle Domains, alle gewählt wird
als „keine Einschränkung" gespeichert — sonst verlöre ein Tab still jede
später angelegte Domain.

Ein Tab lässt sich jederzeit umbenennen — geändert wird nur die
Beschriftung. Die Tabelle behält ihren Namen, und das ist Absicht: An ihm
hängen die Berechtigungen und der Reiter-Link. Würde die Tabelle mitwandern,
verlöre jede Rolle ihre Zuweisung.

**Löschen** entfernt Felddefinitionen, YForm-Registrierung und die Tabelle
samt Inhalt — endgültig, mit Rückfrage. Der Haupt-Tab lässt sich nicht
löschen, das Addon bräuchte sonst seine Ablage neu. Bereits vergebene
Berechtigungen bleiben als tote Einträge in den Rollen zurück; das ist
folgenlos und passiert im YForm Table Manager genauso.

### Migration

**Daten übertragen** kopiert alles Gepflegte von einer Domain oder Sprache auf
eine andere — wahlweise für einen Tab oder für alle. Gedacht zum Aufsetzen
einer neuen Domain; vorhandene Werte im Ziel werden überschrieben, deshalb mit
Rückfrage. Quelle und Ziel müssen Domains und Sprachen sein, die der Benutzer
ohnehin bearbeiten darf.

Die Seite steht getrennt von den Einstellungen, weil sie keine ist: Die
Einstellungen beschreiben, wie sich das Addon von jetzt an verhält, diese
Seite ändert gespeicherte Daten in einem Zug.

### Felder und Table Manager

Aus dem Table Manager führt ein Link zurück — er erscheint dort, wo die
bearbeitete Tabelle zu diesem Addon gehört. Umgekehrt steht Administratoren
neben dem Speichern-Button der direkte Weg zu den Feldern.

Auf den Tabellen dieses Addons fehlen im Table Manager zwei Dinge, die YForm
sonst anbietet: das Angebot, `domain_id` und `clang_id` in Felder zu
verwandeln, und der Knopf „Tabelle aktualisieren mit Feldlöschung". Beides ist
ausgeblendet, weil beides dieselbe Struktur zerlegt: Die Feldlöschung entfernt
jede Spalte ohne Feld außer `id` — hier also genau die beiden Spalten, die
bestimmen, welche Zeile geschrieben wird.

## Rechte

- `yrewrite_domain_settings[]` — darf die Werte bearbeiten
- Sprachen über die REDAXO-eigene Sprachrechte-Verwaltung (`clang`); ein
  Redakteur sieht nur die Tabs seiner Sprachen
- Domains über `yrewrite_domains` im Benutzerprofil. Ohne Berechtigung für
  mindestens eine Domain bleibt die Seite gesperrt
- Tabs über YForms `yform_manager_table_edit` — dieselbe Berechtigung, die
  auch den Table Manager steuert. Ohne Zugriff auf mindestens einen Tab
  bleibt die Seite gesperrt

**Einstellungen**, **Migration** und **Hilfe** sind Administratoren
vorbehalten. Ein Redakteur mit `yrewrite_domain_settings[]` sieht nur
**Daten**.

## IDE-Unterstützung

```bash
php redaxo/bin/console domain-settings:ide-helper
```

Schreibt eine `.phpstorm.meta.php` mit allen Feldnamen. PhpStorm vervollständigt
danach den Schlüssel in `DomainSettings::get('…')`, statt ihn als blinden String zu
behandeln — dasselbe Verfahren, das der REDAXO-Core für seine eigenen APIs
nutzt.

Die Datei wird bei jedem `cache:clear` automatisch aufgefrischt; der Befehl ist
nur nötig, wenn es sofort passieren soll.

## REST-API (optional)

Ist das [api-Addon](https://github.com/FriendsOfREDAXO/api) installiert, stehen
die Werte über HTTP bereit — mit **aufgelöster Fallback-Kette**, also fertigen
Werten statt roher Zeilen:

```
GET   /api/domain-settings                      alle Tabs
GET   /api/domain-settings/<tab>            ein Tab
PATCH /api/domain-settings/<tab>            Werte ändern
      ?domain_id=1&clang_id=2           beides optional
```

```json
{
  "data": { "company_slogan": "Wir bauen Sägen", "logo": "logo.svg" },
  "meta": { "domain_id": 1, "clang_id": 2 }
}
```

Ohne Parameter gilt die erste Domain und die Startsprache.

Ein `PATCH` ändert nur die Felder, die im Body stehen — der Rest bleibt
unangetastet. Unbekannte Feldnamen werden ignoriert und mit einer Liste der
gültigen beantwortet. Gespeichert wird über den YForm-Datensatz, also laufen
die Validatoren der Tabelle; schlägt einer an, kommt `422` mit den Meldungen
zurück.

**Rechte pro Tab, getrennt nach Lesen und Schreiben:** Jeder Tab
bekommt eigene Scopes (`domain-settings/read/<tab>`, `domain-settings/write/<tab>`),
dazu `domain-settings/read` für den Sammel-Endpunkt. Ein Token zum Auslesen kann also
nichts verändern. Ein Token lässt sich also auf einzelne Tabs beschränken —
und es bleiben eine Handvoll Scopes, auch wenn es 150 Felder gibt. Die Vergabe
läuft im api-Addon unter **API → Token**; dieses Addon braucht dafür keine
eigenen Einstellungen.

Die Routen entstehen automatisch aus den vorhandenen Tabs. Legst du einen
Tab an oder benennst ihn um, ist die Route beim nächsten Aufruf da. Wird
das api-Addon erst später installiert, erscheinen die Routen ebenfalls von
selbst — das Addon muss dafür nicht neu installiert werden.

## Performance

Alle Werte über alle Domains und Sprachen liegen in einer Cache-Datei, die beim
ersten `get()` gelesen wird — nicht beim Booten. Requests, die keinen Wert
abfragen, kosten nichts; das Füllen des Caches braucht eine Query je Tab. Der Cache
wird über YForms eigene Datenereignisse verworfen, eine Änderung direkt im
Table Manager wirkt also genauso.

## Bekannte Einschränkung

Wird im Table Manager ein Feld gelöscht oder umbenannt, fällt der Wert-Cache
nicht automatisch — YForm bietet für Schemaänderungen keinen Extension Point,
`YFORM_GENERATE` feuert bei jedem Formularaufbau und wäre das falsche Signal.
Bis zum nächsten Speichern liefert `DomainSettings::get()` deshalb noch den alten
Spaltensatz. Ein `cache:clear` räumt das auf.

## Anforderungen

- REDAXO ^5.17
- PHP ^8.1
- YForm ^5.0
- yrewrite ^2.5

Ist yrewrite deaktiviert oder gibt es noch keine Domain, läuft alles auf
Domain 0 — das Addon funktioniert dann wie eine reine Sammlung globaler Werte.

## Weiterführend

| Datei | Inhalt |
|---|---|
| [CHANGELOG.md](CHANGELOG.md) | Was drin ist, was behoben wurde, bekannte Einschränkungen |
| [TODO.md](TODO.md) | Offene Punkte, nach Wichtigkeit sortiert |

## Entwicklung

```bash
composer install
composer cs-dry    # prüfen
composer cs-fix    # korrigieren
```

Nach Änderungen in `assets/`: `php redaxo/bin/console assets:sync`.

### Tests

```bash
php redaxo/bin/console domain-settings:test            # alles
php redaxo/bin/console domain-settings:test fallback   # eine Suite
```

Console-Command statt PHPUnit — wie YForm, dessen Test-README es so
formuliert: „Tests run as REDAXO console commands. PHPUnit is **not**
required." Die Prüfungen brauchen `rex_clang`, den Datei-Cache, YForm-Tabellen
und `rex_var::parse()`; das zu mocken wäre mehr Arbeit als Nutzen.

Die Testdaten liegen in einem eigenen Tab, der um den Lauf herum angelegt
und wieder gelöscht wird, und benutzen eine Domain-ID weit außerhalb dessen,
was yrewrite vergibt. Redaktionelle Inhalte werden nicht angefasst — geprüft
durch Vergleich der Tabellen vor und nach dem Lauf.

Sieben Suiten mit zusammen 58 Prüfungen: `fallback` (Vererbungskette),
`sections` (anlegen, umbenennen, löschen, reservierte Schlüssel), `cache`
(Invalidierung), `security` (Regressionen der im Review gefundenen Lücken),
`legacy` (die API von 2.3.0), `domain-languages` (welche Sprachen eine Domain
führt), `section-domains` (Tab-zu-Domain-Zuordnung — dass sie speichert, was
sie soll, die Navigation steuert, die Berechtigung nie erweitert und den
Lesepfad nicht anfasst).

Kann eine Prüfung auf dieser Instanz nicht laufen — weil es nur eine Sprache
gibt, keine fremde YForm-Tabelle oder bereits echte Werte auf der aktuellen
Domain —, wird sie als `skip` mit Begründung ausgewiesen und **nicht** als
bestanden gezählt.

## Bugtracker

[Issue anlegen](https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/issues)

## Autoren

**Friends Of REDAXO** — <https://github.com/FriendsOfREDAXO>

Projekt-Lead und First Release: [Daniel Steffen](https://github.com/novinet-dsteffen)

## Lizenz

MIT — siehe [LICENSE](LICENSE)
