# yrewrite_domain_settings-AddOn für REDAXO 5

Redaxo 5 Addon zum Verwalten von zusätzlichen Informationen je Domain

Das Addon installiert die YFORM-Tabelle "yrewrite_domain_settings". 
In dieser Tabelle kann eine Domain verknüpft werden. 
Die Tabelle kann um beliebige Felder ergänzt werden.

Aufruf im Frontend: 

<code>
yrewrite_domain_settings::getValue($key)
</code>

$key = Spaltenname in der Tabelle
Wird kein $key übergeben, gibt die Methode getValue() alle Werte zurück.