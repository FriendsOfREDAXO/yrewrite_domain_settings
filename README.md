# YRewrite Domain-Einstellungen für REDAXO 5

REDAXO5-Addon zum Verwalten zusätzlicher Informationen je YRewrite-Domain.

## Features

- Einfache Verwaltung von Zusatz- und Metainformationen pro Domain
- Basiert auf YForm - Zugriff durch YOrm und eigene Erweiterung möglich

## Installation

1. Über Installer laden oder Zip-Datei im AddOn-Ordner entpacken, der Ordner muss „yrewrite_domain_settings“ heißen.
2. AddOn installieren und aktivieren.
3. Rechte für Rollen anpassen

## Konfiguration

Das Addon installiert die YFORM-Tabelle "yrewrite_domain_settings". In dieser Tabelle kann eine Domain verknüpft werden. Die Tabelle kann um beliebige Felder ergänzt werden.

## Beispiel-Code Frontend
Aufruf im Frontend: 

Per PHP

```php
yrewrite_domain_settings::getValue($key)
```
* Wird kein $key übergeben, gibt die Methode getValue() alle Werte zurück.
* $key  = Spaltenname in der Tabelle

Per REX_VAR

```html
REX_DOMAIN_SETTING[key]
```
* key  = Spaltenname in der Tabelle

## Rechte setzen / auslesen

In der Rollenverwaltung kann eine Beschränkung auf bestimmte Domains vorgenommen werden.

Auslesen kann man diese in Templates oder Modulen mit:

```php
yrewrite_domain_settings::getAllowedDomains();
```

Es wird ein Array mit den erlaubten Domains zurückgegeben.




## Bugtracker

Du hast einen Fehler gefunden oder ein nettes Feature parat? [Lege bitte ein Issue an](https://github.com/FriendsOfREDAXO/yrewrite_domain_settings/)

## Lizenz

siehe [LICENSE.md](https://github.com/FriendsOfREDAXO/quick_navigation/blob/master/LICENSE.md)


## Autor

**Friends Of REDAXO**

* http://www.redaxo.org
* https://github.com/FriendsOfREDAXO

**Projekt-Lead**

[Daniel Steffen](https://github.com/novinet-dsteffen)


**First Release**

[Daniel Steffen](https://github.com/novinet-dsteffen)


