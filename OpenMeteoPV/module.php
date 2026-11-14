<?php
declare(strict_types=1);

/**
 * OpenMeteoPV – PV-Ertragsprognose für IP-Symcon
 *
 * Datenquellen (wählbar):
 *  - DWD MOSMIX_L  (Open Data, Quelle: Deutscher Wetterdienst – auch kommerziell nutzbar)
 *                  Globalstrahlung Rad1h + Temperatur; Aufteilung direkt/diffus über Erbs-Modell
 *  - Open-Meteo    (GHI + DHI; frei nur nicht-kommerziell, optional API-Key für customer-api)
 *
 * Konventionen:
 *  - String-Azimut ('Azimuth') und Horizontmaske ('az'): 0° = Süd, -90° = Ost, +90° = West, ±180° = Nord
 *  - Sonnenazimut intern: Kompass (0° = Nord, 90° = Ost, 180° = Süd)
 *  - Strahlungswerte beider Quellen = Mittelwert der VORANGEGANGENEN Stunde (Zeitstempel = Intervallende)
 *  - Jede Stunde wird in N Teilschritte zerlegt (Sonnenstand je Teilschritt). Direkt- und Diffusstrahlung
 *    werden energieerhaltend über cos(Zenit) auf die Teilschritte verteilt.
 *  - Nowcast: Verhältnis gemessene/prognostizierte Leistung im letzten Zeitfenster; die Korrektur läuft
 *    über die Wirkdauer mit einer Cosinus-Rampe auf 1 aus.
 */
class OpenMeteoPV extends IPSModule
{
    private const ARCHIVE_GUID   = '{43192F0B-135B-4CE7-A0A7-1475603F3060}';
    private const MOSMIX_URL     = 'https://opendata.dwd.de/weather/local_forecasts/mos/MOSMIX_L/single_stations/%1$s/kml/MOSMIX_L_LATEST_%1$s.kmz';
    private const MOSMIX_CATALOG = 'https://www.dwd.de/DE/leistungen/met_verfahren_mosmix/mosmix_stationskatalog.cfg?view=nasPublication';
    private const SOLAR_CONST    = 1361.0;
    private const K_MIN          = 0.1;
    private const K_MAX          = 2.0;

    private const GLOBAL_IDENTS = ['TotalPower_W', 'RestOfDay_kWh', 'Today_kWh', 'Tomorrow_kWh', 'DayAfter_kWh', 'NowcastFactor', 'ForecastJSON'];

    /* ============================================================
     *  LIFECYCLE
     * ============================================================ */
    public function Create()
    {
        parent::Create();

        // Standort
        $this->RegisterPropertyFloat('Latitude', 52.8343);
        $this->RegisterPropertyFloat('Longitude', 8.1555);

        // Datenquelle
        $this->RegisterPropertyString('DataSource', 'dwd');        // 'dwd' | 'openmeteo'
        $this->RegisterPropertyString('MosmixStation', '');        // leer = nächste Station automatisch
        $this->RegisterPropertyString('OpenMeteoApiKey', '');      // leer = freie API (nur nicht-kommerziell)
        $this->RegisterPropertyString('Timezone', 'auto');
        $this->RegisterPropertyInteger('ForecastDays', 3);         // Open-Meteo: 3..16
        $this->RegisterPropertyInteger('PastDays', 1);             // Open-Meteo: 0..7
        $this->RegisterPropertyBoolean('CloudSanityPatch', false); // Open-Meteo: Plausibilisierung über cloud_cover_low

        // Zeitverhalten / Rechnung
        $this->RegisterPropertyInteger('UpdateMinutes', 60);       // Abruf der Wetterprognose
        $this->RegisterPropertyInteger('RecalcMinutes', 5);        // Neuberechnung inkl. Nowcast
        $this->RegisterPropertyInteger('SubSteps', 12);            // Teilschritte pro Stunde (min. 4)

        // Nowcast über Messwert
        $this->RegisterPropertyBoolean('EnableNowcast', true);
        $this->RegisterPropertyInteger('MeasuredPowerVarID', 0);
        $this->RegisterPropertyInteger('MeasuredPowerUnit', 0);    // 0 = W, 1 = kW
        $this->RegisterPropertyInteger('NowcastWindowMin', 30);
        $this->RegisterPropertyFloat('NowcastHours', 3.0);

        // Albedo
        $this->RegisterPropertyFloat('Albedo', 0.20);

        // Diagnose
        $this->RegisterPropertyBoolean('EnableDiagnostics', false);
        $this->RegisterPropertyInteger('DiagStartHour', 7);
        $this->RegisterPropertyInteger('DiagEndHour', 12);

        // Wechselrichter
        $this->RegisterPropertyString('Inverters', json_encode([
            ['Name' => 'WR1', 'ACLimit_kW' => 10.0]
        ], JSON_UNESCAPED_SLASHES));

        // Strings
        $this->RegisterPropertyString('Arrays', json_encode([[
            'Name' => 'Ost', 'kWp' => 2.7, 'Tilt' => 10, 'Azimuth' => -80,
            'LossFactor' => 0.90, 'Gamma' => -0.004, 'NOCT' => 45.0, 'Inverter' => 'WR1',
            'HorizonMask' => [
                ['az' => -130, 'el' => 32], ['az' => -120, 'el' => 31], ['az' => -110, 'el' => 29],
                ['az' => -100, 'el' => 28], ['az' =>  -90, 'el' => 27], ['az' =>  -80, 'el' => 25],
                ['az' =>  -70, 'el' => 22], ['az' =>  -60, 'el' => 20], ['az' =>  -40, 'el' => 14],
                ['az' =>  -20, 'el' => 10], ['az' =>    0, 'el' =>  8], ['az' =>   60, 'el' => 28],
                ['az' =>   90, 'el' => 32], ['az' =>  120, 'el' => 36]
            ],
            'DiffuseObstruction' => 1.0
        ], [
            'Name' => 'West', 'kWp' => 2.7, 'Tilt' => 10, 'Azimuth' => 100,
            'LossFactor' => 0.92, 'Gamma' => -0.004, 'NOCT' => 45.0, 'Inverter' => 'WR1',
            'HorizonMask' => [['az' => -60, 'el' => 8], ['az' => 0, 'el' => 8], ['az' => 60, 'el' => 10]],
            'DiffuseObstruction' => 1.0
        ]], JSON_UNESCAPED_SLASHES));

        // Interner Zustand
        $this->RegisterAttributeString('ForecastCache', '');
        $this->RegisterAttributeString('MosmixAuto', '');
        $this->RegisterAttributeString('NowcastState', '');

        // Variablen (gesamt)
        $this->RegisterVariableInteger('TotalPower_W', 'Leistung (gesamt) [W]', '~Watt', 10);
        $this->RegisterVariableFloat('RestOfDay_kWh', 'Energie Rest heute [kWh]', '~Electricity', 15);
        $this->RegisterVariableFloat('Today_kWh', 'Energie heute [kWh]', '~Electricity', 20);
        $this->RegisterVariableFloat('Tomorrow_kWh', 'Energie morgen [kWh]', '~Electricity', 21);
        $this->RegisterVariableFloat('DayAfter_kWh', 'Energie übermorgen [kWh]', '~Electricity', 22);
        $this->RegisterVariableFloat('NowcastFactor', 'Nowcast-Faktor', '', 80);
        $this->RegisterVariableString('ForecastJSON', 'Forecast JSON (gesamt)', '', 90);

        // Timer (Name 'OMPV_Update' aus v1.x beibehalten)
        $this->RegisterTimer('OMPV_Update', 0, 'IPS_RequestAction($_IPS["TARGET"], "Update", 0);');
        $this->RegisterTimer('OMPV_Recalc', 0, 'IPS_RequestAction($_IPS["TARGET"], "Recalc", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $arrays = $this->getArrays();
        $valid  = self::GLOBAL_IDENTS;
        $pos    = 30;
        foreach ($arrays as $i => $a) {
            $name  = (string)($a['Name'] ?? ('Array_' . $i));
            $ident = $this->arrayIdent($name, (int)$i);
            $this->RegisterVariableInteger($ident . '_Power_W', "Leistung {$name} [W]", '~Watt', $pos++);
            $this->RegisterVariableFloat($ident . '_Today_kWh', "Energie heute {$name} [kWh]", '~Electricity', $pos++);
            $this->RegisterVariableString($ident . '_ForecastJSON', "Forecast JSON {$name}", '', $pos++);
            $this->RegisterVariableString($ident . '_HorizonJSON', "Horizon JSON {$name}", '', $pos++);
            $this->RegisterVariableString($ident . '_DiagJSON', "Diagnose JSON {$name}", '', $pos++);
            array_push($valid, $ident . '_Power_W', $ident . '_Today_kWh', $ident . '_ForecastJSON', $ident . '_HorizonJSON', $ident . '_DiagJSON');
        }
        $this->reportOrphans($valid);   // nur melden – Löschen bleibt dem Anwender überlassen

        // Cache verwerfen, wenn Quelle oder Standort geändert wurden
        $cache = $this->loadCache();
        if (($cache['key'] ?? '') !== $this->sourceKey()) {
            $this->WriteAttributeString('ForecastCache', '');
        }

        $problems = $this->validateConfig($arrays);
        foreach ($problems as $p) {
            $this->SendDebug('Konfiguration', $p, 0);
            $this->LogMessage('OpenMeteoPV: ' . $p, KL_WARNING);
        }
        $this->SetStatus(empty($problems) ? 102 : 202);

        $this->SetTimerInterval('OMPV_Recalc', max(1, $this->ReadPropertyInteger('RecalcMinutes')) * 60 * 1000);
        // Erstabruf entkoppelt, damit das Speichern der Konfiguration nicht auf HTTP wartet
        $this->SetTimerInterval('OMPV_Update', (IPS_GetKernelRunlevel() === KR_READY) ? 2000 : 0);
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->SetTimerInterval('OMPV_Update', 5000);
        }
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                ['type' => 'ExpansionPanel', 'caption' => 'Standort & Datenquelle', 'expanded' => true, 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'Latitude',  'caption' => 'Breite (°)', 'digits' => 4],
                    ['type' => 'NumberSpinner', 'name' => 'Longitude', 'caption' => 'Länge (°)',  'digits' => 4],
                    ['type' => 'Select', 'name' => 'DataSource', 'caption' => 'Datenquelle', 'options' => [
                        ['caption' => 'DWD MOSMIX (Open Data, auch kommerziell)', 'value' => 'dwd'],
                        ['caption' => 'Open-Meteo (frei nur nicht-kommerziell)',   'value' => 'openmeteo']
                    ]],
                    ['type' => 'ValidationTextBox', 'name' => 'MosmixStation', 'caption' => 'MOSMIX-Station (leer = automatisch)'],
                    ['type' => 'PasswordTextBox', 'name' => 'OpenMeteoApiKey', 'caption' => 'Open-Meteo API-Key (optional)'],
                    ['type' => 'Select', 'name' => 'Timezone', 'caption' => 'Zeitzone (Open-Meteo)', 'options' => [
                        ['caption' => 'Auto', 'value' => 'auto'],
                        ['caption' => date_default_timezone_get(), 'value' => date_default_timezone_get()]
                    ]],
                    ['type' => 'NumberSpinner', 'name' => 'ForecastDays', 'caption' => 'Forecast-Tage Open-Meteo (3..16)', 'minimum' => 3, 'maximum' => 16],
                    ['type' => 'NumberSpinner', 'name' => 'PastDays', 'caption' => 'Vergangenheits-Tage Open-Meteo (0..7)', 'minimum' => 0, 'maximum' => 7],
                    ['type' => 'CheckBox', 'name' => 'CloudSanityPatch', 'caption' => 'Open-Meteo: GHI bei ≥90 % tiefer Bewölkung begrenzen'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Berechnung', 'items' => [
                    ['type' => 'NumberSpinner', 'name' => 'UpdateMinutes', 'caption' => 'Abruf Wetterprognose (min)', 'minimum' => 10],
                    ['type' => 'NumberSpinner', 'name' => 'RecalcMinutes', 'caption' => 'Neuberechnung inkl. Nowcast (min)', 'minimum' => 1, 'maximum' => 60],
                    ['type' => 'NumberSpinner', 'name' => 'SubSteps', 'caption' => 'Teilschritte pro Stunde (4..60)', 'minimum' => 4, 'maximum' => 60],
                    ['type' => 'NumberSpinner', 'name' => 'Albedo', 'caption' => 'Albedo (0..1)', 'digits' => 2, 'minimum' => 0, 'maximum' => 1],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Nowcast (Korrektur über Messwert)', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'EnableNowcast', 'caption' => 'Nowcast aktiv'],
                    ['type' => 'SelectVariable', 'name' => 'MeasuredPowerVarID', 'caption' => 'Ist-Leistung PV (Summe aller Strings)'],
                    ['type' => 'Select', 'name' => 'MeasuredPowerUnit', 'caption' => 'Einheit Ist-Leistung', 'options' => [
                        ['caption' => 'W', 'value' => 0], ['caption' => 'kW', 'value' => 1]
                    ]],
                    ['type' => 'NumberSpinner', 'name' => 'NowcastWindowMin', 'caption' => 'Vergleichsfenster (min)', 'minimum' => 5, 'maximum' => 120],
                    ['type' => 'NumberSpinner', 'name' => 'NowcastHours', 'caption' => 'Wirkdauer (0.5..6.0 h)', 'digits' => 1, 'minimum' => 0.5, 'maximum' => 6],
                    ['type' => 'Label', 'caption' => 'Hinweis: Messwert = PV-Erzeugung, nicht Einspeisung. Bei aktiver Abregelung wird die Prognose unterschätzt.'],
                ]],
                ['type' => 'ExpansionPanel', 'caption' => 'Diagnose', 'items' => [
                    ['type' => 'CheckBox', 'name' => 'EnableDiagnostics', 'caption' => 'Diagnose aktiv (heutige Stunden → DiagJSON)'],
                    ['type' => 'NumberSpinner', 'name' => 'DiagStartHour', 'caption' => 'Startstunde (0..23)', 'minimum' => 0, 'maximum' => 23],
                    ['type' => 'NumberSpinner', 'name' => 'DiagEndHour', 'caption' => 'Endstunde (0..23)', 'minimum' => 0, 'maximum' => 23],
                ]],
                ['type' => 'Label', 'caption' => 'Wechselrichter (JSON): [{"Name":"WR1","ACLimit_kW":10.0}] – 0 = keine Begrenzung'],
                ['type' => 'ValidationTextBox', 'name' => 'Inverters', 'caption' => 'Wechselrichter JSON'],
                ['type' => 'Label', 'caption' => 'Strings (JSON). Azimuth/Maske: 0=Süd, -90=Ost, +90=West. "Inverter" verweist auf einen Wechselrichter-Namen.'],
                ['type' => 'ValidationTextBox', 'name' => 'Arrays', 'caption' => 'Strings JSON'],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt aktualisieren', 'onClick' => 'IPS_RequestAction($id, "Update", 0);'],
                ['type' => 'Button', 'caption' => 'Nur neu berechnen (Nowcast)', 'onClick' => 'IPS_RequestAction($id, "Recalc", 0);'],
                ['type' => 'Button', 'caption' => 'Nächste MOSMIX-Stationen anzeigen', 'onClick' => 'echo OMPV_FindMosmixStation($id);'],
            ],
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Aktiv'],
                ['code' => 201, 'icon' => 'error',  'caption' => 'Wetterprognose konnte nicht geladen werden (Cache wird weiter verwendet)'],
                ['code' => 202, 'icon' => 'error',  'caption' => 'Konfigurationsfehler – siehe Meldungen/Debug'],
            ]
        ]);
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'Update':
                $this->Update();
                return;
            case 'Recalc':
                $this->Recalc();
                return;
        }
        throw new Exception("Invalid Ident: $Ident");
    }

    /* ============================================================
     *  ÖFFENTLICHE FUNKTIONEN (OMPV_*)
     * ============================================================ */

    /** Wetterprognose abrufen, in den Cache übernehmen und neu berechnen */
    public function Update()
    {
        $this->SetTimerInterval('OMPV_Update', max(10, $this->ReadPropertyInteger('UpdateMinutes')) * 60 * 1000);
        try {
            $new = ($this->ReadPropertyString('DataSource') === 'openmeteo') ? $this->fetchOpenMeteo() : $this->fetchMosmix();
            if ($new === null || count($new) === 0) {
                $this->SendDebug('Update', 'Keine Prognosedaten erhalten – verwende Cache', 0);
                if ($this->GetStatus() !== 202) {
                    $this->SetStatus(201);
                }
            } else {
                $this->mergeCache($new);
                if ($this->GetStatus() === 201) {
                    $this->SetStatus(102);
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Update ERROR', $e->getMessage() . ' @' . $e->getLine(), 0);
        }
        $this->Recalc();
    }

    /** Neuberechnung aus dem Cache inkl. Nowcast (ohne Abruf) */
    public function Recalc()
    {
        try {
            $data = $this->loadCache()['data'] ?? [];
            if (count($data) < 2) {
                $this->SendDebug('Recalc', 'Kein Prognose-Cache vorhanden', 0);
                return;
            }
            $arrays = $this->getArrays();
            if (empty($arrays)) {
                return;
            }
            $strings = $this->prepareStrings($arrays);
            $invs    = $this->prepareInverters($arrays);
            $ctx     = $this->prepareIrradiance($this->cacheToSeries($data));
            $now     = time();

            $nc = $this->nowcast($ctx, $strings, $invs, $now);
            $r  = $this->simulate($ctx, $strings, $invs, $now, $nc);

            $this->SetValue('TotalPower_W', (int)round($r['now_w']));
            $this->SetValue('RestOfDay_kWh', round($r['rest_kwh'], 2));
            $this->SetValue('Today_kWh', round($r['daily'][0], 2));
            $this->SetValue('Tomorrow_kWh', round($r['daily'][1], 2));
            $this->SetValue('DayAfter_kWh', round($r['daily'][2], 2));
            $this->SetValue('ForecastJSON', json_encode($r['json_total'], JSON_UNESCAPED_SLASHES));

            foreach ($r['strings'] as $ident => $d) {
                if (@$this->GetIDForIdent($ident . '_Power_W') === false) {
                    continue;
                }
                $this->SetValue($ident . '_Power_W', (int)round($d['now_w']));
                $this->SetValue($ident . '_Today_kWh', round($d['today_kwh'], 2));
                $this->SetValue($ident . '_ForecastJSON', json_encode($d['json'], JSON_UNESCAPED_SLASHES));
                $this->SetValue($ident . '_HorizonJSON', json_encode($d['horizon'], JSON_UNESCAPED_SLASHES));
                $this->SetValue($ident . '_DiagJSON', json_encode($d['diag'], JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Recalc ERROR', $e->getMessage() . ' @' . $e->getLine(), 0);
        }
    }

    /** Liste der nächstgelegenen MOSMIX-Stationen (für die Konfiguration) */
    public function FindMosmixStation(): string
    {
        $list = $this->nearestMosmixStations(8);
        if (empty($list)) {
            return 'Stationskatalog des DWD nicht erreichbar.';
        }
        $out = "Nächste MOSMIX-Stationen:\n";
        foreach ($list as $s) {
            $out .= sprintf("%-6s %-22s %6.1f km\n", $s['id'], $s['name'], $s['dist']);
        }
        $auto = $this->autoMosmixStation();
        $out .= "\nAutomatisch gewählt (mit Strahlungsdaten): " . ($auto ?? '—');
        return $out;
    }

    /* ============================================================
     *  DATENQUELLE: DWD MOSMIX_L
     * ============================================================ */

    /** @return array<int, array{0:float,1:?float,2:?float,3:?float}>|null  ts => [ghi, dhi, temp, cloudLow] */
    private function fetchMosmix(): ?array
    {
        $station = strtoupper(trim($this->ReadPropertyString('MosmixStation')));
        if ($station === '') {
            $station = $this->autoMosmixStation();
            if ($station === null) {
                $this->SendDebug('MOSMIX', 'Keine Station mit Strahlungsdaten gefunden', 0);
                return null;
            }
        }
        $p = $this->loadMosmix($station);
        if ($p === null) {
            return null;
        }

        $out = [];
        foreach ($p['ts'] as $i => $ts) {
            $rad = $p['Rad1h'][$i] ?? null;
            if ($rad === null) {
                continue;
            }
            $temp = isset($p['TTT'][$i]) ? $p['TTT'][$i] - 273.15 : null;
            $out[$ts] = [max(0.0, $rad / 3.6), null, $temp, null];   // kJ/m² pro Stunde → W/m² Mittel
        }
        $this->SendDebug('MOSMIX', "Station $station, Punkte=" . count($out) . (count($out) ? ', erster=' . date('c', (int)array_key_first($out)) : ''), 0);
        return $out;
    }

    /** Lädt und parst die KMZ einer Station; null bei Fehler oder fehlender Globalstrahlung */
    private function loadMosmix(string $station): ?array
    {
        $url = sprintf(self::MOSMIX_URL, rawurlencode($station));
        $this->SendDebug('URL [MOSMIX]', $url, 0);
        $kmz = $this->httpGet($url, 30);
        if ($kmz === null) {
            return null;
        }
        $kml = $this->unzipFirst($kmz);
        if ($kml === null) {
            $this->SendDebug('MOSMIX', 'KMZ konnte nicht entpackt werden', 0);
            return null;
        }
        return $this->parseMosmixKml($kml);
    }

    private function parseMosmixKml(string $kml): ?array
    {
        if (!preg_match_all('#<dwd:TimeStep>\s*([^<\s]+)\s*</dwd:TimeStep>#', $kml, $m)) {
            return null;
        }
        $ts = [];
        foreach ($m[1] as $s) {
            $t = strtotime($s);
            if ($t === false) {
                return null;
            }
            $ts[] = $t;
        }

        $res = ['ts' => $ts];
        foreach (['Rad1h', 'TTT'] as $el) {
            $res[$el] = [];
            if (preg_match('#<dwd:Forecast\s+dwd:elementName="' . $el . '"\s*>\s*<dwd:value>([^<]*)</dwd:value>#', $kml, $mm)) {
                foreach (preg_split('/\s+/', trim($mm[1])) as $i => $v) {
                    $res[$el][$i] = is_numeric($v) ? (float)$v : null;
                }
            }
        }
        $valid = count(array_filter($res['Rad1h'], fn($v) => $v !== null));
        if ($valid < 24) {
            return null;    // Station ohne (ausreichende) Strahlungsprognose
        }
        if (preg_match('#<kml:coordinates>\s*([-\d.]+),([-\d.]+)#', $kml, $c)) {
            $res['lon'] = (float)$c[1];
            $res['lat'] = (float)$c[2];
        }
        return $res;
    }

    /** Nächste Station mit Rad1h ermitteln (Ergebnis wird je Standort gecacht) */
    private function autoMosmixStation(): ?string
    {
        $key  = sprintf('%.4f|%.4f', $this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'));
        $attr = json_decode($this->ReadAttributeString('MosmixAuto'), true);
        if (is_array($attr) && ($attr['key'] ?? '') === $key && !empty($attr['id'])) {
            return (string)$attr['id'];
        }
        foreach ($this->nearestMosmixStations(10) as $s) {
            if ($this->loadMosmix($s['id']) !== null) {
                $this->WriteAttributeString('MosmixAuto', json_encode(['key' => $key, 'id' => $s['id'], 'name' => $s['name'], 'dist' => $s['dist']]));
                $this->SendDebug('MOSMIX', sprintf('Automatisch gewählt: %s %s (%.1f km)', $s['id'], $s['name'], $s['dist']), 0);
                return $s['id'];
            }
        }
        return null;
    }

    /** Stationskatalog lesen (Koordinaten im Format Grad.Minuten) und nach Entfernung sortieren */
    private function nearestMosmixStations(int $n): array
    {
        $txt = $this->httpGet(self::MOSMIX_CATALOG, 30);
        if ($txt === null) {
            return [];
        }
        $lat0 = $this->ReadPropertyFloat('Latitude');
        $lon0 = $this->ReadPropertyFloat('Longitude');
        $list = [];
        foreach ($this->parseMosmixCatalog($txt) as $s) {
            $s['dist'] = $this->haversineKm($lat0, $lon0, $s['lat'], $s['lon']);
            $list[] = $s;
        }
        usort($list, fn($a, $b) => $a['dist'] <=> $b['dist']);
        return array_slice($list, 0, $n);
    }

    private function parseMosmixCatalog(string $txt): array
    {
        $out = [];
        foreach (preg_split('/\r?\n/', $txt) as $line) {
            // Aktuelles Format: "ID    ICAO NAME                 LAT    LON     ELEV"  (Koordinaten Grad.Minuten)
            // Älteres Format:  "clu CofX id ICAO name nb. el. elev Hmod-H type"
            if (!preg_match('/^\s*(?:\d+\s+\d+\s+)?([A-Z0-9]{4,5})\s+(\S{4})\s+(.*?)\s+(-?\d+\.\d+)\s+(-?\d+\.\d+)\s+(-?\d+)(?:\s+-?\d+\s+\S+)?\s*$/', $line, $m)) {
                continue;
            }
            $out[] = [
                'id'   => $m[1],
                'name' => trim($m[3]),
                'lat'  => $this->degMinToDec($m[4]),
                'lon'  => $this->degMinToDec($m[5]),
            ];
        }
        return $out;
    }

    private function degMinToDec(string $v): float
    {
        $neg   = str_starts_with(trim($v), '-');
        $parts = explode('.', ltrim(trim($v), '-'));
        $deg   = (float)$parts[0];
        $min   = isset($parts[1]) ? (float)str_pad(substr($parts[1], 0, 2), 2, '0') : 0.0;
        $dec   = $deg + $min / 60.0;
        return $neg ? -$dec : $dec;
    }

    /** Erste Datei eines ZIP/KMZ entpacken (ZipArchive, sonst eigener Parser über das Central Directory) */
    private function unzipFirst(string $data): ?string
    {
        if (class_exists('ZipArchive')) {
            $tmp = tempnam(sys_get_temp_dir(), 'ompv');
            if ($tmp !== false && file_put_contents($tmp, $data) !== false) {
                $zip = new ZipArchive();
                $content = null;
                if ($zip->open($tmp) === true) {
                    $c = $zip->getFromIndex(0);
                    $content = ($c === false) ? null : $c;
                    $zip->close();
                }
                @unlink($tmp);
                if ($content !== null) {
                    return $content;
                }
            }
        }

        $eocd = strrpos($data, "PK\x05\x06");
        if ($eocd === false || strlen($data) < $eocd + 22) {
            return null;
        }
        $cdOffset = unpack('V', substr($data, $eocd + 16, 4))[1];
        if (substr($data, $cdOffset, 4) !== "PK\x01\x02") {
            return null;
        }
        $method   = unpack('v', substr($data, $cdOffset + 10, 2))[1];
        $compSize = unpack('V', substr($data, $cdOffset + 20, 4))[1];
        $locOff   = unpack('V', substr($data, $cdOffset + 42, 4))[1];
        if (substr($data, $locOff, 4) !== "PK\x03\x04") {
            return null;
        }
        $nameLen  = unpack('v', substr($data, $locOff + 26, 2))[1];
        $extraLen = unpack('v', substr($data, $locOff + 28, 2))[1];
        $raw      = substr($data, $locOff + 30 + $nameLen + $extraLen, $compSize);
        if ($method === 0) {
            return $raw;
        }
        if ($method === 8) {
            $out = @gzinflate($raw);
            return ($out === false) ? null : $out;
        }
        return null;
    }

    /* ============================================================
     *  DATENQUELLE: OPEN-METEO
     * ============================================================ */
    private function fetchOpenMeteo(): ?array
    {
        $tzProp = $this->ReadPropertyString('Timezone');
        $tz     = ($tzProp === 'auto') ? 'auto' : urlencode($tzProp);
        $fd     = max(3, min(16, $this->ReadPropertyInteger('ForecastDays')));
        $pd     = max(0, min(7, $this->ReadPropertyInteger('PastDays')));
        $key    = trim($this->ReadPropertyString('OpenMeteoApiKey'));
        $base   = ($key !== '') ? 'https://customer-api.open-meteo.com/v1/forecast' : 'https://api.open-meteo.com/v1/forecast';

        $url = sprintf(
            '%s?latitude=%F&longitude=%F'
            . '&hourly=shortwave_radiation,diffuse_radiation,temperature_2m,cloud_cover_low'
            . '&timezone=%s&forecast_days=%d&past_days=%d&timeformat=unixtime',
            $base, $this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'), $tz, $fd, $pd
        );
        $this->SendDebug('URL [Open-Meteo]', $url . ($key !== '' ? '&apikey=***' : ''), 0);
        if ($key !== '') {
            $url .= '&apikey=' . rawurlencode($key);
        }

        $body = $this->httpGet($url, 20);
        $j = ($body === null) ? null : json_decode($body, true);
        if (!is_array($j)) {
            return null;
        }
        if (!empty($j['error'])) {
            $this->SendDebug('API-Fehler', (string)($j['reason'] ?? 'unbekannt'), 0);
            return null;
        }
        $h = $j['hourly'] ?? null;
        if (!is_array($h) || empty($h['time'])) {
            return null;
        }

        $out = [];
        foreach ($h['time'] as $i => $t) {
            $g = $h['shortwave_radiation'][$i] ?? null;
            if ($g === null) {
                continue;
            }
            $d  = $h['diffuse_radiation'][$i] ?? null;
            $tt = $h['temperature_2m'][$i] ?? null;
            $cl = $h['cloud_cover_low'][$i] ?? null;
            $out[(int)$t] = [(float)$g, $d === null ? null : (float)$d, $tt === null ? null : (float)$tt, $cl === null ? null : (float)$cl];
        }
        $this->SendDebug('Open-Meteo', 'Punkte=' . count($out), 0);
        return $out;
    }

    private function httpGet(string $url, int $timeoutS): ?string
    {
        if (function_exists('Sys_GetURLContentEx')) {
            $body = @Sys_GetURLContentEx($url, ['Timeout' => $timeoutS * 1000]);
        } elseif (function_exists('Sys_GetURLContent')) {
            $body = @Sys_GetURLContent($url);
        } else {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => $timeoutS]]));
        }
        return (!is_string($body) || $body === '') ? null : $body;
    }

    /* ============================================================
     *  CACHE
     *  Die Prognose wird stündlich gemergt; so bleiben bei MOSMIX (Start ab Ausgabezeitpunkt)
     *  die bereits vergangenen Stunden des Tages erhalten.
     * ============================================================ */
    private function sourceKey(): string
    {
        $src = $this->ReadPropertyString('DataSource');
        return sprintf('%s|%s|%.4f|%.4f', $src, $src === 'dwd' ? strtoupper(trim($this->ReadPropertyString('MosmixStation'))) : '',
            $this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'));
    }

    private function loadCache(): array
    {
        $c = json_decode($this->ReadAttributeString('ForecastCache'), true);
        return is_array($c) ? $c : [];
    }

    private function mergeCache(array $new): void
    {
        $cache = $this->loadCache();
        $data  = (($cache['key'] ?? '') === $this->sourceKey()) ? ($cache['data'] ?? []) : [];
        foreach ($new as $ts => $row) {
            $data[(int)$ts] = $row;
        }
        $minTs = strtotime('yesterday');
        foreach (array_keys($data) as $ts) {
            if ((int)$ts < $minTs) {
                unset($data[$ts]);
            }
        }
        ksort($data);
        $this->WriteAttributeString('ForecastCache', json_encode(['key' => $this->sourceKey(), 'fetched' => time(), 'data' => $data]));
    }

    private function cacheToSeries(array $data): array
    {
        ksort($data);
        $patch = $this->ReadPropertyBoolean('CloudSanityPatch');
        $out   = [];
        $lastT = 10.0;
        foreach ($data as $ts => $row) {
            $ghi = (float)($row[0] ?? 0.0);
            $dhi = isset($row[1]) ? (float)$row[1] : null;
            $tmp = isset($row[2]) ? (float)$row[2] : $lastT;
            $cl  = isset($row[3]) ? (float)$row[3] : null;
            $lastT = $tmp;
            if ($patch && $cl !== null && $cl >= 90.0 && $ghi > 250.0) {
                $this->SendDebug('CloudSanityPatch', date('c', (int)$ts) . " cloud_low=$cl ghi_alt=$ghi", 0);
                $ghi = 120.0;
                $dhi = 120.0;
            }
            $out[] = ['ts' => (int)$ts, 'ghi' => $ghi, 'dhi' => $dhi, 'temp' => $tmp];
        }
        return $out;
    }

    /* ============================================================
     *  STRAHLUNG JE TEILSCHRITT
     * ============================================================ */
    private function prepareIrradiance(array $series): array
    {
        $S   = max(4, min(60, $this->ReadPropertyInteger('SubSteps')));
        $dt  = 3600.0 / $S;
        $lat = deg2rad($this->ReadPropertyFloat('Latitude'));
        $lon = deg2rad($this->ReadPropertyFloat('Longitude'));

        $hours = [];
        foreach ($series as $h) {
            $start = $h['ts'] - 3600;
            $subs  = [];
            $sumCos = 0.0;
            for ($s = 0; $s < $S; $s++) {
                $t   = (int)round($start + ($s + 0.5) * $dt);
                $pos = $this->solarPosApprox($t, $lat, $lon);
                $cz  = max(0.0, cos($pos['zenith']));
                $subs[] = ['t' => $t, 'zen' => $pos['zenith'], 'az' => $pos['azimuth'], 'el' => 90.0 - rad2deg($pos['zenith']),
                           'cosZ' => $cz, 'dni' => 0.0, 'dif' => 0.0, 'ghi' => 0.0];
                $sumCos += $cz;
            }
            $meanCos = $sumCos / $S;
            $ghi     = max(0.0, $h['ghi']);

            if ($ghi > 0.0 && $meanCos > 1e-4) {
                $doy = (int)date('z', $h['ts'] - 1800) + 1;
                $e0  = 1.0 + 0.033 * cos(2.0 * M_PI * $doy / 365.0);

                if ($h['dhi'] !== null) {
                    $dhi = $h['dhi'];
                } else {
                    $kt  = min(1.0, $ghi / (self::SOLAR_CONST * $e0 * $meanCos));
                    $dhi = $ghi * $this->erbsDiffuseFraction($kt);
                }
                if ($meanCos < 0.02) {
                    $dhi = $ghi;            // Sonne nur knapp über dem Horizont: alles diffus
                }
                $dhi = max(0.0, min($ghi, $dhi));
                $bhi = $ghi - $dhi;

                // DNI konstant über die Stunde (energieerhaltend), begrenzt auf Klarhimmel-DNI
                $dniRaw = $bhi / $meanCos;
                $lost   = 0.0;
                foreach ($subs as &$sub) {
                    if ($sub['cosZ'] <= 0.0) {
                        continue;
                    }
                    $cap = 1.15 * $this->clearSkyDni($sub['zen'], $e0);
                    $sub['dni'] = min($dniRaw, $cap);
                    $lost += ($dniRaw - $sub['dni']) * $sub['cosZ'];
                }
                unset($sub);
                $difHour = $dhi + $lost / $S;          // abgeschnittene Direktstrahlung → diffus

                foreach ($subs as &$sub) {
                    $w = $sub['cosZ'] / $meanCos;
                    $sub['dif'] = $difHour * $w;
                    $sub['ghi'] = $ghi * $w;
                }
                unset($sub);
            }
            $hours[] = ['ts' => $h['ts'], 'temp' => $h['temp'], 'ghi' => $ghi, 'sub' => $subs];
        }
        return ['S' => $S, 'dt' => $dt, 'hours' => $hours];
    }

    /** Erbs et al. (1982): Diffusanteil aus Klarheitsindex kt */
    private function erbsDiffuseFraction(float $kt): float
    {
        if ($kt <= 0.22) {
            return 1.0 - 0.09 * $kt;
        }
        if ($kt <= 0.80) {
            return 0.9511 - 0.1604 * $kt + 4.388 * $kt ** 2 - 16.638 * $kt ** 3 + 12.336 * $kt ** 4;
        }
        return 0.165;
    }

    /** Klarhimmel-DNI nach Meinel, Luftmasse nach Kasten/Young */
    private function clearSkyDni(float $zen, float $e0): float
    {
        $zd = rad2deg($zen);
        if ($zd >= 90.0) {
            return 0.0;
        }
        $am = 1.0 / (cos($zen) + 0.50572 * pow(96.07995 - $zd, -1.6364));
        return self::SOLAR_CONST * $e0 * pow(0.7, pow($am, 0.678));
    }

    /* ============================================================
     *  STRINGS / WECHSELRICHTER
     * ============================================================ */
    private function prepareStrings(array $arrays): array
    {
        $out = [];
        foreach ($arrays as $idx => $a) {
            $name = (string)($a['Name'] ?? ('Array_' . $idx));
            $tilt = deg2rad((float)($a['Tilt'] ?? 30));
            $raw  = $this->parseHorizonMask($a['HorizonMask'] ?? []);
            $out[] = [
                'idx'   => (int)$idx,
                'ident' => $this->arrayIdent($name, (int)$idx),
                'tilt'  => $tilt,
                'azM'   => deg2rad(180.0 + (float)($a['Azimuth'] ?? 0)),   // Süd-Bezug → Kompass
                'kWp'   => (float)($a['kWp'] ?? 1.0),
                'loss'  => (float)($a['LossFactor'] ?? 0.96),
                'gamma' => (float)($a['Gamma'] ?? -0.004),
                'noct'  => (float)($a['NOCT'] ?? 45.0),
                'svf'   => max(0.0, min(1.0, (float)($a['DiffuseObstruction'] ?? 1.0))),
                'fDif'  => (1.0 + cos($tilt)) / 2.0,
                'fAlb'  => (1.0 - cos($tilt)) / 2.0,
                'mask'  => $this->normalizeMask($raw),
                'maskRaw' => $raw,
            ];
        }
        return $out;
    }

    /**
     * Wechselrichter-Gruppen: ['limit' => W, 'members' => [Position in $strings]]
     * Strings ohne 'Inverter' mit altem Feld 'InverterLimit_kW' erhalten einen eigenen, impliziten WR.
     */
    private function prepareInverters(array $arrays): array
    {
        $defs = json_decode($this->ReadPropertyString('Inverters'), true);
        $invs = [];
        if (is_array($defs)) {
            foreach ($defs as $d) {
                if (!isset($d['Name'])) {
                    continue;
                }
                $invs[(string)$d['Name']] = ['limit' => max(0.0, (float)($d['ACLimit_kW'] ?? 0)) * 1000.0, 'members' => []];
            }
        }
        $pos = 0;
        foreach ($arrays as $idx => $a) {
            $inv = isset($a['Inverter']) ? (string)$a['Inverter'] : '';
            if ($inv !== '' && isset($invs[$inv])) {
                $invs[$inv]['members'][] = $pos;
            } elseif ($inv === '' && (float)($a['InverterLimit_kW'] ?? 0) > 0) {
                $invs['#' . $idx] = ['limit' => (float)$a['InverterLimit_kW'] * 1000.0, 'members' => [$pos]];
            }
            $pos++;
        }
        return array_filter($invs, fn($i) => $i['limit'] > 0 && !empty($i['members']));
    }

    /* ============================================================
     *  SIMULATION
     * ============================================================ */
    /**
     * @param array|null $nc  ['k' => Faktor, 't' => Zeitpunkt der Messung] oder null
     */
    private function simulate(array $ctx, array $strings, array $invs, int $now, ?array $nc): array
    {
        $dt     = $ctx['dt'];
        $S      = $ctx['S'];
        $albedo = $this->ReadPropertyFloat('Albedo');
        $H      = max(0.5, min(6.0, $this->ReadPropertyFloat('NowcastHours'))) * 3600.0;
        $t0     = strtotime('today', $now);
        $days   = [date('Y-m-d', $t0), date('Y-m-d', strtotime('+1 day', $t0)), date('Y-m-d', strtotime('+2 days', $t0))];
        $today  = $days[0];

        $diagOn = $this->ReadPropertyBoolean('EnableDiagnostics');
        $dS = $this->ReadPropertyInteger('DiagStartHour');
        $dE = $this->ReadPropertyInteger('DiagEndHour');

        $nS = count($strings);
        $st = [];
        foreach ($strings as $j => $s) {
            $st[$j] = ['series' => [], 'daily' => [], 'now_w' => 0.0, 'diag' => []];
        }
        $jsonTotal = [];
        $daily     = [];
        $rest      = 0.0;
        $nowW      = 0.0;
        $subTotals = [];        // [t, W] der letzten 2 h vor jetzt (für Nowcast)
        $mid       = intdiv($S, 2);

        foreach ($ctx['hours'] as $h) {
            $hourW = array_fill(0, $nS, 0.0);
            $hourP = array_fill(0, $nS, 0.0);
            $midInfo = [];

            foreach ($h['sub'] as $si => $sub) {
                $t = $sub['t'];
                $f = 1.0;
                if ($nc !== null && $t >= $nc['t']) {
                    $d = $t - $nc['t'];
                    if ($d < $H) {
                        $f = 1.0 + ($nc['k'] - 1.0) * 0.5 * (1.0 + cos(M_PI * $d / $H));
                    }
                }

                $p = array_fill(0, $nS, 0.0);
                if ($sub['cosZ'] > 0.0) {
                    $azSouth = rad2deg($sub['az']) - 180.0;
                    foreach ($strings as $j => $s) {
                        $cosT = max(0.0, $this->cosIncidence($s['tilt'], $s['azM'], $sub['zen'], $sub['az']));
                        $hEl  = $this->horizonElevation($s['mask'], $azSouth);
                        $beam = ($sub['el'] > $hEl) ? $sub['dni'] * $cosT : 0.0;
                        $poa  = $beam + $sub['dif'] * $s['svf'] * $s['fDif'] + $sub['ghi'] * $albedo * $s['fAlb'];
                        $tc   = $h['temp'] + ($s['noct'] - 20.0) / 800.0 * $poa;
                        $p[$j] = max(0.0, $s['kWp'] * $poa * $s['loss'] * (1.0 + $s['gamma'] * ($tc - 25.0))) * $f;
                        $hourP[$j] += $poa;
                        if ($si === $mid) {
                            $midInfo[$j] = ['el' => $sub['el'], 'az' => $azSouth, 'hor' => $hEl, 'cosT' => $cosT];
                        }
                    }
                }

                // Wechselrichter-Begrenzung (AC) auf die Summe der zugeordneten Strings
                foreach ($invs as $inv) {
                    $sum = 0.0;
                    foreach ($inv['members'] as $j) {
                        $sum += $p[$j];
                    }
                    if ($sum > $inv['limit']) {
                        $scale = $inv['limit'] / $sum;
                        foreach ($inv['members'] as $j) {
                            $p[$j] *= $scale;
                        }
                    }
                }

                $day   = date('Y-m-d', $t);
                $total = 0.0;
                $isNow = ($now >= $t - $dt / 2 && $now < $t + $dt / 2);
                $restFrac = 0.0;
                if ($day === $today && $t + $dt / 2 > $now) {
                    $restFrac = min(1.0, ($t + $dt / 2 - $now) / $dt);
                }
                foreach ($p as $j => $w) {
                    $e = $w * $dt / 3600.0 / 1000.0;
                    $st[$j]['daily'][$day] = ($st[$j]['daily'][$day] ?? 0.0) + $e;
                    $hourW[$j] += $w;
                    $total += $w;
                    if ($isNow) {
                        $st[$j]['now_w'] = $w;
                    }
                    $rest += $e * $restFrac;
                }
                $daily[$day] = ($daily[$day] ?? 0.0) + $total * $dt / 3600.0 / 1000.0;
                if ($isNow) {
                    $nowW = $total;
                }
                if ($t <= $now && $t > $now - 7200) {
                    $subTotals[] = [$t, $total];
                }
            }

            // Stundenwerte (Mittel über die Teilschritte = kWh dieser Stunde)
            $tsIso  = date('c', $h['ts']);
            $hTotal = 0.0;
            $hDay   = date('Y-m-d', $h['ts'] - 1800);
            $hr     = (int)date('G', $h['ts'] - 1800);
            foreach ($strings as $j => $s) {
                $pw = $hourW[$j] / $S;
                $hTotal += $pw;
                $st[$j]['series'][] = ['t' => $tsIso, 'p_w' => (int)round($pw), 'e_kwh' => round($pw / 1000.0, 4)];
                if ($diagOn && $hDay === $today && $hr >= $dS && $hr <= $dE) {
                    $mi = $midInfo[$j] ?? ['el' => $h['sub'][$mid]['el'], 'az' => rad2deg($h['sub'][$mid]['az']) - 180.0, 'hor' => null, 'cosT' => 0.0];
                    $st[$j]['diag'][] = [
                        't' => $tsIso, 'el' => round($mi['el'], 1), 'az_sued' => round($mi['az'], 1),
                        'horizont' => $mi['hor'] === null ? null : round($mi['hor'], 1), 'cosT' => round($mi['cosT'], 3),
                        'ghi' => round($h['ghi'], 1), 'poa' => round($hourP[$j] / $S, 1), 'p_w' => (int)round($pw)
                    ];
                }
            }
            $jsonTotal[] = ['t' => $tsIso, 'p_w' => (int)round($hTotal), 'e_kwh' => round($hTotal / 1000.0, 4)];
        }

        $stringsOut = [];
        foreach ($strings as $j => $s) {
            $stringsOut[$s['ident']] = [
                'now_w'     => $st[$j]['now_w'],
                'today_kwh' => $st[$j]['daily'][$today] ?? 0.0,
                'json'      => $st[$j]['series'],
                'horizon'   => $s['maskRaw'],
                'diag'      => $st[$j]['diag'],
            ];
        }

        return [
            'now_w'      => $nowW,
            'rest_kwh'   => $rest,
            'daily'      => [$daily[$days[0]] ?? 0.0, $daily[$days[1]] ?? 0.0, $daily[$days[2]] ?? 0.0],
            'strings'    => $stringsOut,
            'json_total' => $jsonTotal,
            'sub_totals' => $subTotals,
        ];
    }

    /* ============================================================
     *  NOWCAST
     * ============================================================ */
    private function nowcast(array $ctx, array $strings, array $invs, int $now): ?array
    {
        if (!$this->ReadPropertyBoolean('EnableNowcast')) {
            $this->SetValue('NowcastFactor', 1.0);
            return null;
        }
        $H     = max(0.5, min(6.0, $this->ReadPropertyFloat('NowcastHours'))) * 3600.0;
        $state = json_decode($this->ReadAttributeString('NowcastState'), true);
        $varID = $this->ReadPropertyInteger('MeasuredPowerVarID');
        $k     = null;

        if ($varID > 0 && IPS_VariableExists($varID)) {
            $win  = max(5, $this->ReadPropertyInteger('NowcastWindowMin')) * 60;
            $from = $now - $win;

            $base = $this->simulate($ctx, $strings, $invs, $now, null);
            $sum = 0.0;
            $cnt = 0;
            foreach ($base['sub_totals'] as [$t, $w]) {
                if ($t >= $from) {
                    $sum += $w;
                    $cnt++;
                }
            }
            $fc = $cnt > 0 ? $sum / $cnt : 0.0;

            $kwpTotal = array_sum(array_column($strings, 'kWp'));
            $thr = max(50.0, 0.03 * $kwpTotal * 1000.0);
            if ($fc < $thr) {
                $this->SendDebug('Nowcast', sprintf('Prognose im Fenster zu klein (%.0f W < %.0f W)', $fc, $thr), 0);
            } else {
                $meas = $this->measuredMean($varID, $from, $now);
                if ($meas === null) {
                    $this->SendDebug('Nowcast', 'Kein gültiger Messwert (veraltet oder nicht lesbar)', 0);
                } else {
                    $k = max(self::K_MIN, min(self::K_MAX, $meas / $fc));
                    $this->SendDebug('Nowcast', sprintf('Ist=%.0f W, Prognose=%.0f W, k=%.3f', $meas, $fc, $k), 0);
                }
            }
        }

        if ($k !== null) {
            $state = ['k' => $k, 't' => $now];
            $this->WriteAttributeString('NowcastState', json_encode($state));
            $this->SetValue('NowcastFactor', round($k, 3));
            return $state;
        }
        // Letzte Korrektur weiterverwenden, solange sie wirkt (z. B. am Abend unter der Schwelle)
        if (is_array($state) && isset($state['k'], $state['t']) && $now - (int)$state['t'] < $H) {
            $this->SetValue('NowcastFactor', round((float)$state['k'], 3));
            return ['k' => (float)$state['k'], 't' => (int)$state['t']];
        }
        $this->SetValue('NowcastFactor', 1.0);
        return null;
    }

    /** Zeitgewichteter Mittelwert der Ist-Leistung [W] im Intervall; null wenn veraltet/nicht verfügbar */
    private function measuredMean(int $varID, int $from, int $to): ?float
    {
        $factor = ($this->ReadPropertyInteger('MeasuredPowerUnit') === 1) ? 1000.0 : 1.0;
        $v = IPS_GetVariable($varID);
        if ($to - (int)$v['VariableUpdated'] > max(900, $to - $from)) {
            return null;    // Messwert hängt (Kommunikationsausfall o. ä.)
        }

        $arch = IPS_GetInstanceListByModuleID(self::ARCHIVE_GUID)[0] ?? 0;
        if ($arch > 0 && @AC_GetLoggingStatus($arch, $varID)) {
            try {
                $vals = @AC_GetLoggedValues($arch, $varID, $from - 3600, $to, 0);
            } catch (\Throwable $e) {
                $vals = false;
            }
            if (is_array($vals) && count($vals) > 0) {
                usort($vals, fn($a, $b) => $a['TimeStamp'] <=> $b['TimeStamp']);
                $cur  = null;
                $last = $from;
                $acc  = 0.0;
                foreach ($vals as $row) {
                    $ts  = (int)$row['TimeStamp'];
                    $val = (float)$row['Value'];
                    if ($ts <= $from) {
                        $cur = $val;
                        continue;
                    }
                    if ($cur === null) {
                        $cur = $val;
                    }
                    $acc += $cur * ($ts - $last);
                    $last = $ts;
                    $cur  = $val;
                }
                $acc += (float)$cur * ($to - $last);
                return max(0.0, $acc / max(1, $to - $from) * $factor);
            }
        }
        // ohne Archiv: aktueller Wert
        return max(0.0, (float)GetValue($varID) * $factor);
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */
    private function getArrays(): array
    {
        $a = json_decode($this->ReadPropertyString('Arrays'), true);
        return is_array($a) ? array_values($a) : [];
    }

    private function arrayIdent(string $name, int $idx): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $name)) . '_' . $idx;
    }

    private function validateConfig(array $arrays): array
    {
        $p = [];
        if (!is_array(json_decode($this->ReadPropertyString('Arrays'), true))) {
            $p[] = 'Strings-JSON ist ungültig';
        } elseif (empty($arrays)) {
            $p[] = 'Keine Strings konfiguriert';
        }
        $inv = json_decode($this->ReadPropertyString('Inverters'), true);
        if (!is_array($inv)) {
            $p[] = 'Wechselrichter-JSON ist ungültig';
            $inv = [];
        }
        $names = array_map(fn($i) => (string)($i['Name'] ?? ''), $inv);
        foreach ($arrays as $i => $a) {
            $n = (string)($a['Name'] ?? ('Array_' . $i));
            if (isset($a['Inverter']) && !in_array((string)$a['Inverter'], $names, true)) {
                $p[] = "String '$n': Wechselrichter '{$a['Inverter']}' nicht definiert";
            }
            if ((float)($a['kWp'] ?? 0) <= 0) {
                $p[] = "String '$n': kWp fehlt oder ≤ 0";
            }
            $loss = (float)($a['LossFactor'] ?? 0.96);
            if ($loss <= 0 || $loss > 1.0) {
                $p[] = "String '$n': LossFactor muss zwischen 0 und 1 liegen";
            }
        }
        $vid = $this->ReadPropertyInteger('MeasuredPowerVarID');
        if ($this->ReadPropertyBoolean('EnableNowcast') && $vid > 0 && !IPS_VariableExists($vid)) {
            $p[] = 'Nowcast: Messvariable existiert nicht';
        }
        return $p;
    }

    /** Variablen alter/umbenannter Strings nur melden, nicht löschen */
    private function reportOrphans(array $valid): void
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $cid) {
            $o = IPS_GetObject($cid);
            if ($o['ObjectType'] === 2 && $o['ObjectIdent'] !== '' && !in_array($o['ObjectIdent'], $valid, true)) {
                $this->LogMessage(sprintf('OpenMeteoPV: Variable "%s" (#%d) gehört zu keinem konfigurierten String mehr und kann manuell gelöscht werden',
                    $o['ObjectName'], $cid), KL_NOTIFY);
            }
        }
    }

    private function parseHorizonMask($maskField): array
    {
        if (is_string($maskField)) {
            $m = json_decode($maskField, true);
            return is_array($m) ? $m : [];
        }
        return is_array($maskField) ? $maskField : [];
    }

    /** Maske normalisieren (az → (-180, 180]) und nach Azimut sortieren – ohne Glättung */
    private function normalizeMask(array $mask): array
    {
        $m = [];
        foreach ($mask as $pt) {
            if (!isset($pt['az'], $pt['el'])) {
                continue;
            }
            $m[] = [fmod((float)$pt['az'] + 540.0, 360.0) - 180.0, (float)$pt['el']];
        }
        usort($m, fn($a, $b) => $a[0] <=> $b[0]);
        return $m;
    }

    /** Horizonthöhe [°] bei Azimut (Süd-Bezug, Grad) – lineare, zyklische Interpolation */
    private function horizonElevation(array $m, float $azDeg): float
    {
        $N = count($m);
        if ($N === 0) {
            return 0.0;
        }
        if ($N === 1) {
            return $m[0][1];
        }
        $az = fmod($azDeg + 540.0, 360.0) - 180.0;

        for ($i = 0; $i < $N; $i++) {
            if ($az <= $m[$i][0]) {
                if ($i === 0) {
                    break;                          // vor dem ersten Punkt → Wrap-Around
                }
                [$a0, $e0] = $m[$i - 1];
                [$a1, $e1] = $m[$i];
                if ($a1 == $a0) {
                    return $e1;
                }
                return $e0 + ($az - $a0) / ($a1 - $a0) * ($e1 - $e0);
            }
        }

        // Wrap-Around zwischen letztem und erstem Punkt (über Nord)
        [$aL, $eL] = $m[$N - 1];
        [$aF, $eF] = $m[0];
        $span = $aF + 360.0 - $aL;
        if ($span <= 0.0) {
            return $eF;
        }
        $azw = ($az < $aL) ? $az + 360.0 : $az;
        return $eL + ($azw - $aL) / $span * ($eF - $eL);
    }

    /** cos Einfallswinkel; Modul- und Sonnenazimut beide im Kompass-Bezug */
    private function cosIncidence(float $tilt, float $aziM, float $zenith, float $aziSun): float
    {
        return sin($tilt) * sin($zenith) * cos($aziM - $aziSun) + cos($tilt) * cos($zenith);
    }

    /** Sonnenstand: zenith [rad], azimuth [rad, Kompass 0=N, 90°=O, 180°=S] */
    private function solarPosApprox(int $tsUTC, float $lat, float $lon): array
    {
        $d = ($tsUTC - 946728000) / 86400.0;
        $L = deg2rad(fmod(280.46 + 0.9856474 * $d, 360.0));
        $g = deg2rad(fmod(357.528 + 0.9856003 * $d, 360.0));
        $lambda  = $L + deg2rad(1.915) * sin($g) + deg2rad(0.020) * sin(2 * $g);
        $epsilon = deg2rad(23.439 - 0.0000004 * $d);
        $RA  = atan2(cos($epsilon) * sin($lambda), cos($lambda));
        $dec = asin(sin($epsilon) * sin($lambda));
        $GMST = fmod(18.697374558 + 24.06570982441908 * $d, 24.0);
        $HA = deg2rad($GMST * 15.0) + $lon - $RA;

        $x = cos($HA) * cos($dec);
        $y = sin($HA) * cos($dec);
        $z = sin($dec);

        $xhor = $x * sin($lat) - $z * cos($lat);
        $zhor = $x * cos($lat) + $z * sin($lat);

        return ['zenith' => acos(max(-1.0, min(1.0, $zhor))), 'azimuth' => atan2($y, $xhor) + M_PI];
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 6371.0 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
