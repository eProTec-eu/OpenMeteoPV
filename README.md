# OpenMeteoPV (IP‑Symcon Modul)

**PV‑Ertragsprognose** für IP‑Symcon mit wählbarer Datenquelle (**DWD MOSMIX** oder **Open‑Meteo**), mehreren Strings/Ausrichtungen, **Horizontmaske**, **Himmelsabschattung**, **NOCT‑Temperaturmodell**, **Wechselrichter‑Begrenzung** (auch für mehrere Strings an einem WR) und **Nowcast über die gemessene Ist‑Leistung**.

## Features
- **Datenquellen**
  - **DWD MOSMIX_L** (Standard): Punktprognose des Deutschen Wetterdienstes, 10 Tage, stündlich. Open Data – auch **kommerziell** nutzbar (Quellenangabe erforderlich). Die Station wird automatisch als nächstgelegene Station mit Strahlungsprognose gewählt oder kann fest eingetragen werden.
  - **Open‑Meteo**: GHI + DHI. Die freie API ist nur **nicht‑kommerziell** nutzbar; optional kann ein API‑Key (customer‑api) hinterlegt werden.
- **Strings** mit kWp, Neigung, Azimut, Verlustfaktor, Temperaturkoeffizient γ, NOCT, Horizontmaske, Himmelsabschattung
- **Wechselrichter** als eigene Liste; mehrere Strings können sich ein AC‑Limit teilen
- **Teilschrittrechnung**: Jede Stunde wird in N Teilschritte zerlegt (Standard 12 = 5 min, mindestens 4). Je Teilschritt werden Sonnenstand, Horizont und Einfallswinkel berechnet. Die Strahlung wird dabei energieerhaltend auf die Teilschritte verteilt. Das sorgt für korrekte Werte in den Stunden mit Sonnenauf‑ und ‑untergang und für eine genaue „Rest heute“‑Berechnung.
- **Nowcast**: Das Modul vergleicht die gemessene PV‑Leistung im letzten Zeitfenster mit der Prognose. Der Korrekturfaktor gilt sofort und läuft über die eingestellte Wirkdauer weich auf 1 aus.

## Variablen
| Ident | Bedeutung |
|---|---|
| `TotalPower_W` | prognostizierte Leistung jetzt (gesamt, inkl. Nowcast) |
| `RestOfDay_kWh` | **prognostizierter Ertrag für den Rest des heutigen Tages** (inkl. Nowcast) |
| `Today_kWh` / `Tomorrow_kWh` / `DayAfter_kWh` | Tagesprognosen |
| `NowcastFactor` | aktueller Korrekturfaktor (1 = keine Korrektur) |
| `ForecastJSON` | Stundenwerte gesamt `[{t, p_w, e_kwh}]`, `t` = Intervallende |
| `Forecast_TS` | **Zeitreihe für das Energiemanagement** (siehe unten), heute 00:00 bis Ende übermorgen |
| `<STRING>_<n>_*` | je String: Leistung jetzt, Energie heute, Forecast‑, Horizont‑ und Diagnose‑JSON; optional `_TS` (Zeitreihe) |

Variablen umbenannter oder entfernter Strings werden **nicht** gelöscht. Das Modul meldet sie im Meldungsfenster, löschen kann man sie dann von Hand.

### Zeitreihe (`Forecast_TS`)
Gleichmäßiges Raster für Energiemanagement-Skripte, Format kompatibel zu `Lib\TimeSeries` (lib_core):

```json
{
  "version": 1,
  "source": "dwd-mosmix:10224",
  "created_ts": 1794790800,
  "issued_ts": 1794787500,
  "start_ts": 1794697200,
  "interval_s": 900,
  "unit": "W",
  "values": [0, 0, 382.7, 887.6, 1360.4]
}
```

| Feld | Bedeutung |
|---|---|
| `start_ts` | Beginn des ersten Intervalls, Unix-Zeit (UTC) = heute 00:00 Ortszeit |
| `interval_s` | Raster: 900, 1800 oder 3600 s (Einstellung „Zeitreihe“) |
| `values` | **mittlere Leistung [W]** im Intervall `start_ts + i·interval_s` (Beginn) – nicht Energie; inkl. Nowcast und WR-Begrenzung |
| `created_ts` | Zeitpunkt der Berechnung |
| `issued_ts` | Abruf der Wetterdaten (zur Prüfung, ob die Prognose veraltet ist) |

Die Werte entstehen energieerhaltend aus den Teilschritten. Die Summe der Intervallenergien eines Tages entspricht `Today_kWh` bzw. `Tomorrow_kWh`. Optional gibt es zusätzlich eine Zeitreihe je String (`<STRING>_<n>_TS`); bei Abwahl werden diese Variablen gelöscht.

## Konfiguration

### Wechselrichter (JSON)
```json
[{"Name":"WR1","ACLimit_kW":10.0},{"Name":"WR2","ACLimit_kW":5.0}]
```
`ACLimit_kW` = 0 bedeutet keine Begrenzung. Die Begrenzung wirkt auf die Summe aller zugeordneten Strings und wird anteilig auf sie verteilt.

### Strings (JSON)
```json
[
  {"Name":"Ost","kWp":2.7,"Tilt":10,"Azimuth":-80,"LossFactor":0.90,"Gamma":-0.004,"NOCT":45,
   "Inverter":"WR1","DiffuseObstruction":1.0,
   "HorizonMask":[{"az":-90,"el":27},{"az":0,"el":8},{"az":90,"el":32}]},
  {"Name":"West","kWp":2.7,"Tilt":10,"Azimuth":100,"LossFactor":0.92,"Gamma":-0.004,"NOCT":45,
   "Inverter":"WR1","DiffuseObstruction":1.0,
   "HorizonMask":[{"az":-60,"el":8},{"az":60,"el":10}]}
]
```
| Feld | Bedeutung |
|---|---|
| `kWp` | DC‑Nennleistung |
| `Tilt` | Neigung in ° (0 = waagerecht) |
| `Azimuth` | 0 = Süd, −90 = Ost, +90 = West |
| `LossFactor` | Gesamtwirkungsgrad aller Verluste (Leitungen, Mismatch, WR‑Wirkungsgrad, Verschmutzung), z. B. 0,85–0,95 |
| `Gamma` | Temperaturkoeffizient der Leistung pro K (z. B. −0,004) |
| `NOCT` | Nominal Operating Cell Temperature in °C |
| `Inverter` | Name aus der Wechselrichter‑Liste |
| `HorizonMask` | Punkte `{az, el}` in ° (az: 0 = Süd, ±180 = Nord). Die Werte werden linear und zyklisch interpoliert, ohne Glättung. Liegt die Sonne unterhalb des Horizonts, entfällt die Direktstrahlung. |
| `DiffuseObstruction` | **Himmelsanteil** für die Diffusstrahlung; wirkt immer. 1,0 = freier Himmel, 0,85 = 15 % des Himmels verdeckt |

Das ältere Feld `InverterLimit_kW` je String wird weiterhin unterstützt, sofern kein `Inverter` angegeben ist (dann eigener WR je String).

### Nowcast
- **Ist‑Leistung**: Variable mit der aktuellen PV‑**Erzeugung** als Summe aller konfigurierten Strings, in W oder kW. Ist die Variable archiviert, bildet das Modul den zeitgewichteten Mittelwert über das Vergleichsfenster. Ohne Archivierung nimmt es den aktuellen Wert.
- Der Faktor wird nur bestimmt, wenn die Prognose im Fenster mindestens 3 % der Gesamt‑kWp (mindestens 50 W) beträgt. Er ist auf 0,1…2,0 begrenzt.
- Wurde die Messvariable länger als 15 min (bzw. länger als das Fenster) nicht aktualisiert, wird sie ignoriert.
- **Wichtig:** Der Messwert muss die Erzeugung sein, nicht die Einspeisung. Bei aktiver Abregelung (z. B. Nulleinspeisung) wird die Prognose sonst unterschätzt.

### Zeitverhalten
- **Abruf Wetterprognose** (Standard 60 min): MOSMIX wird vom DWD alle 6 h neu berechnet, ein stündlicher Abruf reicht also.
- **Neuberechnung** (Standard 5 min): Nowcast und „Rest heute“ werden aus dem Cache neu berechnet, ohne Abruf.
- Der Prognose‑Cache bleibt erhalten. So fehlen bei MOSMIX, dessen Prognose erst ab dem Ausgabezeitpunkt beginnt, die bereits vergangenen Stunden des Tages nicht.

## Modellbeschreibung
1. Stundenmittel der Globalstrahlung (MOSMIX: `Rad1h` in kJ/m² ÷ 3,6 = W/m²; Open‑Meteo: `shortwave_radiation`)
2. Aufteilung in Direkt‑ und Diffusstrahlung: Open‑Meteo liefert `diffuse_radiation`, bei MOSMIX schätzt das Modul sie mit dem **Erbs‑Modell** aus dem Klarheitsindex
3. Verteilung auf die Teilschritte proportional zu cos(Zenit). Die Direktnormalstrahlung wird auf 115 % der Klarhimmel‑DNI begrenzt; der abgeschnittene Teil geht in die Diffusstrahlung über.
4. Einstrahlung auf die Modulfläche (isotropes Himmelsmodell): direkt × cos θ (sofern über dem Horizont) + diffus × Himmelsanteil × (1+cos β)/2 + GHI × Albedo × (1−cos β)/2
5. Zelltemperatur nach NOCT, Leistung = kWp × POA × Verlustfaktor × (1 + γ·(T − 25 °C))
6. Nowcast‑Faktor, danach AC‑Begrenzung je Wechselrichter

## Installation
1. Repository in IP‑Symcon unter *Kerninstanzen → Module → Hinzufügen* eintragen
2. Instanz *OpenMeteoPV* anlegen
3. Standort, Datenquelle, Wechselrichter und Strings eintragen
4. Optional: Ist‑Leistung für den Nowcast auswählen
5. Mit „Nächste MOSMIX‑Stationen anzeigen“ prüfen, welche Station verwendet wird

## Update von 1.x
- Standard‑Datenquelle ist jetzt **DWD MOSMIX**. Wer weiter Open‑Meteo nutzen möchte, muss die Datenquelle umstellen.
- Der Satelliten‑Nowcast ist entfallen und wurde durch den Nowcast über die Messung ersetzt.
- Der Wolken‑Plausibilitätscheck ist jetzt optional (Standard: aus) und nutzt `cloud_cover_low`.
- Die Glättung der Horizontmaske ist entfallen. Die eingetragenen Punkte gelten jetzt exakt.
- `DiffuseObstruction` wirkt jetzt immer, nicht mehr nur bei Sonne hinter dem Horizont.

## Datenquellen und Lizenzen
- **DWD**: Datenbasis: Deutscher Wetterdienst, MOSMIX (Open Data, Nutzung gemäß GeoNutzV, Quellenangabe erforderlich) – https://opendata.dwd.de
- **Open‑Meteo**: Daten unter CC BY 4.0. Die freie API ist nur für nicht‑kommerzielle Zwecke frei; kommerziell nur mit Abo (API‑Key) – https://open-meteo.com/en/terms

## Lizenz
MIT (siehe `LICENSE`)
