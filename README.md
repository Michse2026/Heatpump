# iGarden für IP-Symcon

Inoffizielles IP-Symcon-Modul für Fairland-iGarden-Poolwärmepumpen. Entwickelt für die **Pool-Systems IPS-270MAX** und kompatibel mit Geräten, die in der App **iGarden** erscheinen.

## Funktionen

- automatische Erkennung der iGarden-Cloud-Region
- automatische Erkennung der ersten Wärmepumpe im Konto
- Ein/Aus, Solltemperatur, Heizen/Kühlen/Auto und Leistungsmodus
- Wassertemperatur, Betriebsstatus, aktuelle Leistung und berechnete Energie
- dynamische Anzeige zusätzlicher Datenpunkte der jeweiligen Firmware
- zyklische Aktualisierung und Fehleranzeige

## Installation

### Über die Modulverwaltung

1. Dieses Verzeichnis in ein Git-Repository hochladen.
2. In IP-Symcon **Modulverwaltung → Modul hinzufügen** öffnen.
3. Die HTTPS-Adresse des Repositorys eintragen.
4. Unter **Instanz hinzufügen** nach `iGarden` oder `IPS-270MAX` suchen.

### Manuell im Docker-Container

Das Verzeichnis `iGardenIPS` nach `/var/lib/symcon/modules/iGardenIPS` kopieren. Bei manueller Installation muss das Zielverzeichnis ein gültiges Git-Repository sein; die Installation über die Modulverwaltung ist deshalb vorzuziehen.

## Konfiguration

1. In iGarden möglichst ein zweites Konto erstellen und die Wärmepumpe dorthin freigeben. Die Cloud erlaubt möglicherweise nur eine aktive Sitzung je Konto.
2. Benutzername und Passwort des zweiten Kontos in der Instanz eintragen.
3. Region auf **Automatisch erkennen** belassen.
4. **Verbindung testen und Gerät suchen** wählen.
5. Nach erfolgreicher Erkennung wird die Geräte-ID intern gespeichert. Alternativ kann sie fest eingetragen werden.

Zugangsdaten werden nur als IP-Symcon-Instanzeigenschaften gespeichert. Sie werden weder in Debug-Ausgaben noch in Variablen geschrieben.

## Wichtige Datenpunkte

| dpId | Funktion | Schreiben |
|---|---|---|
| 101 | Ein/Aus | ja |
| 102 | Leistungs-/Laufmodus | ja |
| 103 | aktuelle Wassertemperatur | nein |
| 106 | Auto/Heizen/Kühlen | ja |
| 107 | Solltemperatur | ja |
| 113 | Betriebsstatus | nein |

Weitere Datenpunkte werden dynamisch als `DP_<id>` angelegt. `Rohdaten` enthält die letzte vollständige Datenpunktantwort und hilft, die IPS-270MAX-spezifischen Bezeichnungen nach dem ersten Test präzise zuzuordnen.

## Skriptaufrufe

```php
IGDN_Update($InstanzID);
IGDN_Discover($InstanzID);
IGDN_RequestAction($InstanzID, 'Power', true);
IGDN_RequestAction($InstanzID, 'TargetTemperature', 27.0);
IGDN_RequestAction($InstanzID, 'OperatingMode', 1); // 0 Auto, 1 Heizen, 2 Kühlen
```

## Hinweis

Die iGarden-Cloud-API ist nicht offiziell öffentlich dokumentiert und kann durch den Anbieter geändert werden. Das Modul ist keine Herstellerintegration. Sicherheits- und Frostschutzfunktionen der Wärmepumpe dürfen nicht durch Automationen außer Kraft gesetzt werden.

## Grundlage

Die Endpunkte und bekannten Datenpunkte orientieren sich an der MIT-lizenzierten Home-Assistant-Integration `siedi/ha-fairland` und wurden für PHP/IP-Symcon neu implementiert.
