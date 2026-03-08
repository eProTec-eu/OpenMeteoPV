# OpenMeteoPV (IP‑Symcon Modul)

**PV‑Ertragsprognose** auf Basis von **Open‑Meteo** Strahlungsdaten (GHI/DNI/DHI), optional **GTI**. Mehrere Strings/Ausrichtungen, **Horizon‑Maske** (geometrische Teilverschattung), **NOCT‑Temperaturmodell**, **Inverter‑Clipping**. Zusätzlich ein **Highcharts‑Dashboard** (HTMLBox) für Ist vs. Prognose.

## Features
- Mehrere **Strings/Ausrichtungen** (jeweils: kWp, Tilt, Azimut, Loss, γ, NOCT, WR‑Limit)
- **Horizon‑Maske** (Azimut→Horizont‑Elevation) blockiert **DNI** unterhalb des lokalen Horizonts; **DHI** optional gedämpft
- Datenquellen über **Open‑Meteo**:
  - Weather Forecast API (stündlich; optional 15‑min via DWD ICON in Mitteleuropa)  
  - Satellite Radiation API (Europa: **10‑min** GHI/DNI/DHI/GTI via EUMETSAT MTG)  
- **Highcharts‑Ansicht**: Ist‑Leistung (Archiv) + Prognose gesamt + je String, optional Polar‑Chart für Horizon‑Maske & Sonnenbahn

> Open‑Meteo Dokumentation: Weather & Radiation Endpunkte (GHI/DNI/DHI/GTI), sowie DWD ICON & Satellite MTG.  
> https://open-meteo.com/en/docs  
> https://open-meteo.com/en/docs/dwd-api  
> https://open-meteo.com/en/docs/satellite-radiation-api

## Installation
1. Dieses Repository in IP‑Symcon **als Modul** hinzufügen (Kerninstanzen → *Module* → *Hinzufügen* → Pfad/URL).  
2. **Instanz** *OpenMeteoPV* anlegen.  
3. In der Konfiguration Standort & Strings pflegen (Neigung, Azimut, kWp, Verluste, NOCT, WR‑Limit).  
4. Optional: Horizon‑Maske pro String als JSON hinterlegen (Azimut in Grad, 0°=Süd, −90°=Ost, +90°=West, ±180°=Nord).  
5. **Update‑Intervall** (z. B. 60 min) setzen und speichern.

## Highcharts‑Dashboard (HTMLBox)
- Skript: `scripts/HC_OpenMeteoPV_HTMLBox.php` in IP‑Symcon importieren.  
- Eine **String‑Variable** mit Profil `~HTMLBox` anlegen.  
- IDs im Skript anpassen: Modul‑Instanz, Archiv‑Instanz, PV‑Leistungs‑Variable (Ist), HTML‑Box‑Variable.  
- Skript zyklisch alle 15–60 min ausführen oder im Modul‑Update triggern.

## Hinweise
- Open‑Meteo Strahlungswerte sind stündliche **Energie‑Mittel** (Wh/m² über die vergangene Stunde) – so lässt sich direkt kWh/h integrieren.  
- In Europa ermöglicht die Satellite Radiation API **10‑min**‑Daten (EUMETSAT MTG). Der DWD‑ICON‑Zweig liefert **15‑min** in Mitteleuropa.  
- Lizenz: Open‑Meteo **nicht‑kommerziell** frei nutzbar; bitte die Terms befolgen.

## Lizenz
MIT (siehe `LICENSE`)
