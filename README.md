# 📉 Aktien-Widget (Stock Widget)

[![Version](https://img.shields.io/badge/Symcon-PHP--Modul-red.svg?style=flat-square)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Product](https://img.shields.io/badge/Symcon%20Version-8.1-blue.svg?style=flat-square)](https://www.symcon.de/produkt/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.0.20250822-orange.svg?style=flat-square)](https://github.com/Wilkware/StockWidget)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg?style=flat-square)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Actions](https://img.shields.io/github/actions/workflow/status/wilkware/StockWidget/ci.yml?branch=main&label=CI&style=flat-square)](https://github.com/Wilkware/StockWidget/actions)

Dieses Modul dient zur Anzeige von Aktienkursen in der Kachelvisualisierung.  
Ideal für eine klare und kompakte Übersicht von Finanz- und Marktdaten auf Dashboards.

## Inhaltverzeichnis

1. [Funktionsumfang](#user-content-1-funktionsumfang)
2. [Voraussetzungen](#user-content-2-voraussetzungen)
3. [Installation](#user-content-3-installation)
4. [Einrichten der Instanzen in IP-Symcon](#user-content-4-einrichten-der-instanzen-in-ip-symcon)
5. [Statusvariablen und Profile](#user-content-5-statusvariablen-und-profile)
6. [Visualisierung](#user-content-6-visualisierung)
7. [PHP-Befehlsreferenz](#user-content-7-php-befehlsreferenz)
8. [Versionshistorie](#user-content-8-versionshistorie)

### 1. Funktionsumfang

Durch die Nutzung des HTML-SDKs kann dieses Widget den Aktienkurs einer WKN oder ISIN anschaulich und übersichtlich darstellen. Neben der Kursentwicklung für einen wählbaren Zeitraum (1 Tag, 1 Woche, 1 Monat, 1 Quartal, 1 Halbjahr oder 1 Jahr) werden auch der aktuelle Trend – farblich hervorgehoben (positiv/negativ) – sowie der aktuelle Preis angezeigt.

### 2. Voraussetzungen

* IP-Symcon ab Version 8.1

### 3. Installation

* Über den Module Store das 'Aktien-Widget'-Modul installieren.
* Alternativ über das Module Control folgende URL hinzufügen  
`https://github.com/Wilkware/StockWidget` oder `git://github.com/Wilkware/StockWidget.git`

### 4. Einrichten der Instanzen in IP-Symcon

* Unter "Instanz hinzufügen" ist das _'Aktien-Widget'_-Modul unter dem Hersteller _'Geräte'_ aufgeführt.
Weitere Informationen zum Hinzufügen von Instanzen in der [Dokumentation der Instanzen](https://www.symcon.de/service/dokumentation/konzepte/instanzen/#Instanz_hinzufügen)

__Konfigurationsseite__:

_Einstellungsbereich:_

> 🚀 WKN/ISIN ...

Name                                | Beschreibung
------------------------------------|--------------------------------------------
Beschriftung                        | Überschrift/Label, z.B. WKN oder ISIN 
Schriftgröße                        | zu verwendende Schriftgröße in Pixel

> 💸 Trend ...

Name| Beschreibung
------------------------------------|--------------------------------------------
Variable                            | Tagesveränderung in folgendem Format +/- Preiswert (prozenturaler Veränderung), z.B. '+0,60 (+10%)'
Schriftgröße                        | zu verwendende Schriftgröße in Pixel
Farbe(Positiv)                      | Farbwert für positiven Trend
Farbe(Negativ)                      | Farbwert für negativen Trend

> 📈 Diagramm ...

Name                                | Beschreibung
------------------------------------|--------------------------------------------
Daten                               | Auswahl der zu verwendenden Daten für die Kurslinie
Farbe(Linie)                        | Farbwert für Liniendarstellung
Glatt zeichnen                      | Auswahl ob die Linie weich oder kantig (Direktverbindung Punkte) gezeichnet werden soll.
Füllung zeichnen                    | Darstellung einer nach unten auslaufenden farblichen Flächenfüllung (Gradient)
Unterer Versatz                     | Wieviel Prozent vom unteren Kachelrand soll die Liniendarstellung Abstand halten, kann zur besseren Lesbarkeit des aktuellen Preises genutzt werden.

__HINWEIS ZU DATEN DER KURSLINIE__: die Kennlinie (außer bei laufender Tag) nutzt den letzten geloggten Wert an den entsprechenden vergangenen Tagen. Wochenenden und Tage ohne Werte/Handel werden ignoriert bzw. nicht in die Darstellung mit einbezogen.
Bei laufendem Tag werden die Daten des aktuellen Tages genutzt. Sollte an dem Tag kein Handel oder noch keine Daten eingelaufen sein, werden weiterhin die Tagesdaten des letzen Handelstages genommen, z.B. am Sonntag die Daten vom Freitag oder Dienstag früh vor 9 Uhr die Daten vom Montag.

> 💰 Preis ...

Name                               | Beschreibung
------------------------------------|--------------------------------------------
Variable                            | Geloggte Preisvariable des aktuellen Kurses (wird für Kennlinie genutzt)
Schriftgröße                        | zu verwendende Schriftgröße in Pixel

### 5. Statusvariablen und Profile

Es werden keine zusätzlichen Statusvariablen/Profile benötigt.

### 6. Visualisierung

Das Modul kann direkt als Link in die TileVisu eingebunden werden.  
Die Kachel zeigt ...
- oben links die WKN/ISIN Beschriftung und den farblichen Tagestrend an
- unten recht den aktuellen oder letzten Tageswert/-preis an
- unten links den ausgewählten Datenindikator (1T, 1W, 1M, 1Q, 1H, 1J) an
- die Kennlinie optimiert ihre Darstellung in Abhängigkeit von Platz und min/max Wert

### 7. PHP-Befehlsreferenz

Das Modul stellt keine direkten Funktionsaufrufe zur Verfügung.  

### 8. Versionshistorie

v1.0.20250822

* _NEU_: Initialversion

## Danksagung

Ich möchte mich für die Unterstützung bei der Entwicklung dieses Moduls bedanken bei ...

* _Smudo_ : für die Vorarbeit bzw. Umstellung auf Tagesschau-Werten 👍
* _ralf_ : für die rege Hilfe im Börsenticker Channel 🙏

## Entwickler

Seit nunmehr über 10 Jahren fasziniert mich das Thema Haussteuerung. In den letzten Jahren betätige ich mich auch intensiv in der IP-Symcon Community und steuere dort verschiedenste Skript und Module bei. Ihr findet mich dort unter dem Namen @pitti ;-)

[![GitHub](https://img.shields.io/badge/GitHub-@wilkware-181717.svg?style=for-the-badge&logo=github)](https://wilkware.github.io/)

## Spenden

Die Software ist für die nicht kommerzielle Nutzung kostenlos, über eine Spende bei Gefallen des Moduls würde ich mich freuen.

[![PayPal](https://img.shields.io/badge/PayPal-spenden-00457C.svg?style=for-the-badge&logo=paypal)](https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=8816166)

## Lizenz

Namensnennung - Nicht-kommerziell - Weitergabe unter gleichen Bedingungen 4.0 International

[![Licence](https://img.shields.io/badge/License-CC_BY--NC--SA_4.0-EF9421.svg?style=for-the-badge&logo=creativecommons)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
