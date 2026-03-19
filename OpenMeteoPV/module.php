<?php
declare(strict_types=1);

class OpenMeteoPV extends IPSModule
{
    /* ============================================================
     *  LIFECYCLE
     * ============================================================ */
    public function Create()
    {
        parent::Create();

        // Standort
        $this->RegisterPropertyFloat('Latitude',  52.8343);
        $this->RegisterPropertyFloat('Longitude', 8.1555);
        $this->RegisterPropertyString('Timezone', 'auto'); // oder z. B. 'Europe/Berlin'

        // Datenquellen / Optionen
        $this->RegisterPropertyBoolean('UseSatellite', true); // Satellite Radiation API (EU)
        $this->RegisterPropertyInteger('ForecastDays', 3);    // 1..16 (Forecast)
        $this->RegisterPropertyInteger('PastDays', 1);        // 0..7 (Forecast); Satellite nutzt hours
        $this->RegisterPropertyInteger('UpdateMinutes', 60);

        // Nowcasting (Methode 2)
        $this->RegisterPropertyBoolean('EnableNowcast', true);
        $this->RegisterPropertyFloat('NowcastHours', 4.0); // 0.5..6.0 h

        // Albedo
        $this->RegisterPropertyFloat('Albedo', 0.20);

        // Diagnose
        $this->RegisterPropertyBoolean('EnableDiagnostics', false);
        $this->RegisterPropertyInteger('DiagStartHour', 7);
        $this->RegisterPropertyInteger('DiagEndHour', 12);

        // Strings/Ausrichtungen als JSON
        $defaultArrays = json_encode([[
            'Name' => 'Ost',
            'kWp' => 2.7,
            'Tilt' => 10,
            'Azimuth' => -80, // 0°=Süd, -90°=Ost, +90°=West
            'LossFactor' => 0.90,
            'Gamma' => -0.004,
            'NOCT' => 45.0,
            'InverterLimit_kW' => 10.0,
            'HorizonMask' => [
                ['az' => -130, 'el' => 32],
                ['az' => -120, 'el' => 31],
                ['az' => -110, 'el' => 29],
                ['az' => -100, 'el' => 28],
                ['az' =>  -90, 'el' => 27],
                ['az' =>  -80, 'el' => 25],
                ['az' =>  -70, 'el' => 22],
                ['az' =>  -60, 'el' => 20],
                ['az' =>  -40, 'el' => 14],
                ['az' =>  -20, 'el' => 10],
                ['az' =>    0, 'el' =>  8],
                ['az' =>   60, 'el' => 28],
                ['az' =>   90, 'el' => 32],
                ['az' =>  120, 'el' => 36]
            ],
            'DiffuseObstruction' => 1.0
        ],[
            'Name' => 'West',
            'kWp' => 2.7,
            'Tilt' => 10,
            'Azimuth' => +100,
            'LossFactor' => 0.92,
            'Gamma' => -0.004,
            'NOCT' => 45.0,
            'InverterLimit_kW' => 10.0,
            'HorizonMask' => [
                ['az' => -60, 'el' => 8],
                ['az' =>   0, 'el' => 8],
                ['az' => +60, 'el' => 10]
            ],
            'DiffuseObstruction' => 1.0
        ]], JSON_UNESCAPED_SLASHES);

        $this->RegisterPropertyString('Arrays', $defaultArrays);

        // Variablen (gesamt)
        $this->RegisterVariableInteger('TotalPower_W', 'Leistung (gesamt) [W]', '~Watt', 10);
        $this->RegisterVariableFloat('Today_kWh', 'Energie heute [kWh]', '', 20);
        $this->RegisterVariableFloat('Tomorrow_kWh', 'Energie morgen [kWh]', '', 21);
        $this->RegisterVariableFloat('DayAfter_kWh', 'Energie übermorgen [kWh]', '', 22);
        $this->RegisterVariableString('ForecastJSON', 'Forecast JSON (gesamt)', '', 90);

        // Timer
        $this->RegisterTimer(
            'OMPV_Update',
            0,
            'IPS_RequestAction($_IPS["TARGET"], "Update", 0);'
        );
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        // String-Variablen je Array
        $arrs = $this->getArrays();
        $pos = 30; $i = 0;
        foreach ($arrs as $a) {
            $name = isset($a['Name']) ? (string)$a['Name'] : ("Array_".$i);
            $ident = $this->arrayIdent($name, $i);
            $this->RegisterVariableInteger($ident.'_Power_W', "Leistung {$name} [W]", '~Watt', $pos++);
            $this->RegisterVariableFloat($ident.'_Today_kWh', "Energie heute {$name} [kWh]", '', $pos++);
            $this->RegisterVariableString($ident.'_ForecastJSON', "Forecast JSON {$name}", '', $pos++);
            $this->RegisterVariableString($ident.'_HorizonJSON', "Horizon JSON {$name}", '', $pos++);
            $this->RegisterVariableString($ident.'_DiagJSON', "Diagnose JSON {$name}", '', $pos++);
            $i++;
        }

        $minutes = max(10, (int)$this->ReadPropertyInteger('UpdateMinutes'));
        $this->SetTimerInterval('OMPV_Update', $minutes * 60 * 1000);

        // Initiales Update
        $this->Update();
    }

    public function GetConfigurationForm()
    {
        $arraysJson = $this->ReadPropertyString('Arrays') ?: '[]';
        return json_encode([
            'elements' => [
                ['type' => 'NumberSpinner', 'name' => 'Latitude',  'caption' => 'Breite (°)'],
                ['type' => 'NumberSpinner', 'name' => 'Longitude', 'caption' => 'Länge (°)'],
                [
                    'type' => 'Select', 'name' => 'Timezone', 'caption' => 'Zeitzone',
                    'options' => [
                        ['caption' => 'Auto', 'value' => 'auto'],
                        ['caption' => date_default_timezone_get(), 'value' => date_default_timezone_get()]
                    ]
                ],
                ['type' => 'CheckBox', 'name' => 'UseSatellite', 'caption' => 'Satellite Radiation API (EU)'],
                ['type' => 'NumberSpinner', 'name' => 'ForecastDays', 'caption' => 'Forecast-Tage (1..16)'],
                ['type' => 'NumberSpinner', 'name' => 'PastDays', 'caption' => 'Vergangenheits-Tage (0..7)'],
                ['type' => 'NumberSpinner', 'name' => 'UpdateMinutes', 'caption' => 'Update-Intervall (min)'],

                ['type' => 'Label', 'caption' => 'Nowcasting (Methode 2)'],
                ['type' => 'CheckBox', 'name' => 'EnableNowcast', 'caption' => 'Nowcasting aktiv'],
                ['type' => 'NumberSpinner', 'name' => 'NowcastHours', 'caption' => 'Schiebezeit (0.5..6.0 h)', 'digits' => 1, 'minimum' => 0.5, 'maximum' => 6],

                ['type' => 'NumberSpinner', 'name' => 'Albedo', 'caption' => 'Albedo (0..1)', 'digits' => 2, 'minimum' => 0, 'maximum' => 1],

                ['type' => 'Label', 'caption' => 'Diagnose:'],
                ['type' => 'CheckBox', 'name' => 'EnableDiagnostics', 'caption' => 'Diagnose aktiv (Fenster)'],
                ['type' => 'NumberSpinner', 'name' => 'DiagStartHour', 'caption' => 'Diagnose Startstunde (0..23)'],
                ['type' => 'NumberSpinner', 'name' => 'DiagEndHour', 'caption' => 'Diagnose Endstunde (0..23)'],

                ['type' => 'Label', 'caption' => 'Strings/Ausrichtungen (JSON, eine Zeile):'],
                ['type' => 'ValidationTextBox', 'name' => 'Arrays', 'caption' => 'JSON', 'value' => $arraysJson],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt aktualisieren', 'onClick' => 'IPS_RequestAction($id, "Update", 0);']
            ]
        ]);
    }

    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Update') {
            $this->Update();
            return true;
        }
        throw new Exception("Invalid Ident: $Ident");
    }

    /* ============================================================
     *  UPDATE
     * ============================================================ */
    public function Update()
    {
        try {
            // 1) Daten holen
            $sat = $this->fetchSatelliteData();
            $fc  = $this->fetchForecastData();

            if (!$fc || empty($fc['hourly']['time'])) {
                $this->SendDebug('Update', 'Forecast leer/ungültig — Abbruch', 0);
                return;
            }

            // 2) computePV (Nowcasting + Hybrid)
            $r = $this->computePV($sat, $fc);

            // 3) Gesamt
            $this->SetValue('TotalPower_W', (int)round($r['total_power_now_w'] ?? 0));
            $this->SetValue('Today_kWh',    round($r['daily']['0'] ?? 0, 2));
            $this->SetValue('Tomorrow_kWh', round($r['daily']['1'] ?? 0, 2));
            $this->SetValue('DayAfter_kWh', round($r['daily']['2'] ?? 0, 2));
            $this->SetValue('ForecastJSON', json_encode($r['json_total'] ?? [], JSON_UNESCAPED_SLASHES));

            // 4) Strings
            foreach (($r['strings'] ?? []) as $ident => $d) {
                if (isset($d['now_w'])) $this->SetValue($ident.'_Power_W', (int)round($d['now_w']));
                if (isset($d['today_kwh'])) $this->SetValue($ident.'_Today_kWh', round($d['today_kwh'], 2));
                if (isset($d['json'])) $this->SetValue($ident.'_ForecastJSON', json_encode($d['json'], JSON_UNESCAPED_SLASHES));
                if (isset($d['horizon'])) $this->SetValue($ident.'_HorizonJSON', json_encode($d['horizon'], JSON_UNESCAPED_SLASHES));
                if (isset($d['diag'])) $this->SetValue($ident.'_DiagJSON', json_encode($d['diag'], JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Update ERROR', $e->getMessage(), 0);
        }
    }

    /* ============================================================
     *  FETCH: Satellite (Vergangenheit) + Forecast (Zukunft)
     * ============================================================ */

    private function fetchSatelliteData(): ?array
    {
        if (!(bool)$this->ReadPropertyBoolean('UseSatellite')) return null;

        $lat = (float)$this->ReadPropertyFloat('Latitude');
        $lon = (float)$this->ReadPropertyFloat('Longitude');
        $tz  = ($this->ReadPropertyString('Timezone') === 'auto') ? 'auto' : urlencode($this->ReadPropertyString('Timezone'));

        // Satellite: nur Radiation-Variablen (kein temp/cloud!)
        $hourly = 'shortwave_radiation,direct_radiation,diffuse_radiation,direct_normal_irradiance';

        // Wir brauchen nur Vergangenheit / Jetzt (Trend). 6..48h reichen.
        $pd_days = max(0, min(7, (int)$this->ReadPropertyInteger('PastDays')));
        $past_hours = max(6, min(48, $pd_days * 24));  // min. 6h, max. 48h
        $forecast_hours = 0; // Satellite liefert keine echte Zukunft

        $url = sprintf(
            'https://api.open-meteo.com/v1/satellite?latitude=%F&longitude=%F&hourly=%s&timezone=%s&past_hours=%d&forecast_hours=%d',
            $lat, $lon, $hourly, $tz, $past_hours, $forecast_hours
        );

        $this->SendDebug('OpenMeteo URL [satellite]', $url, 0);
        $sat = $this->fetchUrlJson($url, 'satellite');

        if (is_array($sat) && !empty($sat['hourly']['time'])) {
            $vars = implode(',', array_keys($sat['hourly'] ?? []));
            $cnt  = count($sat['hourly']['time'] ?? []);
            $this->SendDebug('OpenMeteo [satellite]', 'hourly='.$vars.' | Punkte='.$cnt, 0);
            return $sat;
        }
        $this->SendDebug('OpenMeteo [satellite]', 'leer/ungültig', 0);
        return null;
    }

    private function fetchForecastData(): ?array
    {
        $lat = (float)$this->ReadPropertyFloat('Latitude');
        $lon = (float)$this->ReadPropertyFloat('Longitude');
        $tz  = ($this->ReadPropertyString('Timezone') === 'auto') ? 'auto' : urlencode($this->ReadPropertyString('Timezone'));

        $fd_days = max(1, min(16, (int)$this->ReadPropertyInteger('ForecastDays')));
        $pd_days = max(0, min(7,  (int)$this->ReadPropertyInteger('PastDays')));

        // Forecast: Radiation + Temperatur + Cloud
        $hourly = 'shortwave_radiation,direct_radiation,diffuse_radiation,direct_normal_irradiance,temperature_2m,cloud_cover';

        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%F&longitude=%F&hourly=%s&timezone=%s&forecast_days=%d&past_days=%d',
            $lat, $lon, $hourly, $tz, $fd_days, $pd_days
        );

        $this->SendDebug('OpenMeteo URL [forecast]', $url, 0);
        $fc = $this->fetchUrlJson($url, 'forecast');

        if (is_array($fc) && !empty($fc['hourly']['time'])) {
            $vars = implode(',', array_keys($fc['hourly'] ?? []));
            $cnt  = count($fc['hourly']['time'] ?? []);
            $hasDNI = isset(($fc['hourly'] ?? [])['direct_normal_irradiance']);
            $this->SendDebug('OpenMeteo [forecast]', 'hourly='.$vars.' | Punkte='.$cnt, 0);
            $this->SendDebug('OpenMeteo [forecast]', 'has direct_normal_irradiance = '.($hasDNI ? 'yes' : 'no'), 0);
            return $fc;
        }
        $this->SendDebug('OpenMeteo [forecast]', 'leer/ungültig', 0);
        return null;
    }

    private function fetchUrlJson(string $url, string $tag): ?array
    {
        // bevorzugt Symcon-Funktion
        if (function_exists('Sys_GetURLContent')) {
            $body = @Sys_GetURLContent($url);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 20]]);
            $body = @file_get_contents($url, false, $ctx);
        }
        if ($body === false || $body === '') {
            $this->SendDebug('OpenMeteo ['.$tag.']', 'Abruf fehlgeschlagen/leer', 0);
            return null;
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            $this->SendDebug('OpenMeteo ['.$tag.']', 'JSON ungültig', 0);
            return null;
        }
        return $j;
    }

    /* ============================================================
     *  NOWCASTING (Methode 2): Satellite-Trend + Forecast-Blending
     * ============================================================ */

    private function applySatelliteNowcast(array $sat, array $fc): array
    {
        // Grundchecks
        if (empty($sat['hourly']['time']) ||
            empty($fc['hourly']['time']) ||
            empty($sat['hourly']['shortwave_radiation']) ||
            empty($sat['hourly']['direct_normal_irradiance'])) {
            return $fc;
        }

        $satT = $sat['hourly']['time'];
        $ghiS = $sat['hourly']['shortwave_radiation'];
        $dniS = $sat['hourly']['direct_normal_irradiance'];
        $nS = count($satT);

        if ($nS < 3)
            return $fc;

        $fcT  = $fc['hourly']['time'];
        $ghiF = $fc['hourly']['shortwave_radiation'] ?? [];
        $dniF = $fc['hourly']['direct_normal_irradiance'] ?? [];
        $nF = count($fcT);

        if ($nF < 4)
            return $fc;

        /*
            Open-Meteo liefert Wh/m² pro Stunde. Für Trend-Berechnung
            benötigen wir W/m², daher: Wh/h = W
        */

        // kleine Mittelung für stabileren Trend
        $ghi_now = (float)$ghiS[$nS - 1];
        $ghi_prev1 = (float)$ghiS[$nS - 2];
        $ghi_prev2 = (float)$ghiS[$nS - 3];

        $dni_now = (float)$dniS[$nS - 1];
        $dni_prev1 = (float)$dniS[$nS - 2];
        $dni_prev2 = (float)$dniS[$nS - 3];

        // Durchschnitt aus 2 Schritten
        $trend_ghi = (($ghi_now - $ghi_prev1) + ($ghi_prev1 - $ghi_prev2)) / 2.0;
        $trend_dni = (($dni_now - $dni_prev1) + ($dni_prev1 - $dni_prev2)) / 2.0;

        // Maximal erlaubte Trendstärke (Sicherheitslimit)
        $maxTrend = 400;  // Wh Änderung pro Stunde
        $trend_ghi = max(-$maxTrend, min($maxTrend, $trend_ghi));
        $trend_dni = max(-$maxTrend, min($maxTrend, $trend_dni));

        // Zukunftshorizont
        $nowH = max(0.5, min(6.0, (float)$this->ReadPropertyFloat('NowcastHours')));
        $hLimit = min((int)ceil($nowH), $nF - 1);

        // Zeitkonstante für blending
        $tau = 120.0; // Minuten

        for ($h = 1; $h <= $hLimit; $h++) {

            // Extrapolation: da Wh/h = W, zeitliche Skalierung entfällt
            // (keine /60 oder *60 Fehler!)
            $ghi_sat = max(0.0, $ghi_now + $trend_ghi * $h);
            $dni_sat = max(0.0, $dni_now + $trend_dni * $h);

            $ghi_fc = (float)($ghiF[$h] ?? $ghi_now);
            $dni_fc = (float)($dniF[$h] ?? $dni_now);

            // Blendinggewicht
            $t_minutes = $h * 60.0;
            $w = exp(-$t_minutes / $tau);

            // Nowcast mischen
            $ghi_new = $w * $ghi_sat + (1.0 - $w) * $ghi_fc;
            $dni_new = $w * $dni_sat + (1.0 - $w) * $dni_fc;

            // Limits gegen Overshooting
            $ghi_max = max($ghi_fc * 1.4, $ghi_fc + 100);
            $dni_max = max($dni_fc * 1.4, $dni_fc + 100);

            $ghi_new = min($ghi_new, $ghi_max);
            $dni_new = min($dni_new, $dni_max);

            // Negative Werte verhindern
            $ghi_new = max(0.0, $ghi_new);
            $dni_new = max(0.0, $dni_new);

            // speichern
            $ghiF[$h] = $ghi_new;
            $dniF[$h] = $dni_new;
        }

        $fc['hourly']['shortwave_radiation'] = $ghiF;
        $fc['hourly']['direct_normal_irradiance'] = $dniF;

        return $fc;
    }

    /* ============================================================
     *  PV-BERECHNUNG MIT NOWCAST-HYBRID
     * ============================================================ */
    private function computePV(?array $sat, array $fc): array
    {
        // Falls Satellite fehlt → Forecast-only
        if ($sat === null || empty($sat['hourly']['time'])) {
            $this->SendDebug('Nowcasting', 'Satellite-Daten fehlen → Forecast-only', 0);
            return $this->computePV_ForecastOnly($fc);
        }
    
        // Nowcasting aktiv?
        if ($sat !== null 
            && !empty($sat['hourly']['time'])
            && (bool)$this->ReadPropertyBoolean('EnableNowcast')) 
        {
            $fc = $this->applySatelliteNowcast($sat, $fc);
        }


        // Forecast-Zeitachsen
        if (empty($fc['hourly']['time'])) {
            return $this->computePV_Fallback([]);
        }

        $times = $fc['hourly']['time'];
        $ghi   = $fc['hourly']['shortwave_radiation'] ?? [];
        $dni   = $fc['hourly']['direct_normal_irradiance'] ?? [];
        $dhi   = $fc['hourly']['diffuse_radiation'] ?? [];
        $temp  = $fc['hourly']['temperature_2m'] ?? [];

        $n = min(count($times), count($ghi), count($dni));
        if ($n === 0) return $this->computePV_Fallback([]);

        // Sonnenposition (UTC-sicher)
        $latRad = deg2rad((float)$this->ReadPropertyFloat('Latitude'));
        $lonRad = deg2rad((float)$this->ReadPropertyFloat('Longitude'));

        $solar = [];
        for ($i = 0; $i < $n; $i++) {
            $dt = new DateTime($times[$i]);         // ISO mit Offset
            $dt->setTimezone(new DateTimeZone('UTC'));
            $solar[$i] = $this->solarPosApprox($dt->getTimestamp(), $latRad, $lonRad);
        }

        // Diagnose-Setup
        $diagEnabled = (bool)$this->ReadPropertyBoolean('EnableDiagnostics');
        $diagH0 = max(0, min(23, (int)$this->ReadPropertyInteger('DiagStartHour')));
        $diagH1 = max(0, min(23, (int)$this->ReadPropertyInteger('DiagEndHour')));

        // Strings & Albedo
        $arrays = $this->getArrays();
        $albedo = (float)$this->ReadPropertyFloat('Albedo');

        // „Jetzt“-Index
        $now = time();
        $nowIdx = 0; $best = PHP_INT_MAX;
        for ($i = 0; $i < $n; $i++) {
            $d = abs(strtotime($times[$i]) - $now);
            if ($d < $best) { $best = $d; $nowIdx = $i; }
        }

        $refDay = strtotime(substr($times[0], 0, 10));
        $stringsOut = [];
        $totalMap = [];
        $sumToday = $sumTomorrow = $sumAfter = 0.0;

        foreach ($arrays as $idx => $arr) {

            $name  = (string)($arr['Name'] ?? ("Array_".$idx));
            $ident = $this->arrayIdent($name, $idx);

            $tilt = deg2rad((float)($arr['Tilt'] ?? 30));
            $azM  = deg2rad(-(float)($arr['Azimuth'] ?? 0)); // 0=S, +90=W, -90=E → Maskenframe
            $kWp  = (float)($arr['kWp'] ?? 1.0);
            $loss = (float)($arr['LossFactor'] ?? 0.96);
            $gamma= (float)($arr['Gamma'] ?? -0.004);
            $NOCT = (float)($arr['NOCT'] ?? 45.0);
            $inv  = (float)($arr['InverterLimit_kW'] ?? 0.0);
            $mask = $this->parseHorizonMask($arr['HorizonMask'] ?? []);
            $diffOb = (float)($arr['DiffuseObstruction'] ?? 1.0);

            $series = [];
            $diagRows = [];
            $daily = [];
            $now_w = 0.0;

            for ($i = 0; $i < $n; $i++) {

                $zen = $solar[$i]['zenith'];
                $azs = $solar[$i]['azimuth'];

                // Einfallswinkel
                $cosT = $this->cosIncidence($tilt, $azM, $zen, $azs);
                if ($cosT < 0) $cosT = 0.0;

                // Maskenprüfung
                $elSun = 90 - rad2deg($zen);
                // Masken-Azimut: 0°=S, -90°=O, +90°=W (Vorzeichenwechsel!)
                $azMaskDeg = fmod(( -rad2deg($azs) + 540.0), 360.0) - 180.0;
                $hEl = $this->horizonElevation($mask, deg2rad($azMaskDeg));

                $dni_eff = ($elSun < $hEl) ? 0.0 : ($dni[$i] ?? 0.0);
                $dhi_eff = ($elSun < $hEl) ? (($dhi[$i] ?? 0.0) * $diffOb) : ($dhi[$i] ?? 0.0);

                // --- Strahlungsdaten korrekt als W/m² (Mittelwert) interpretieren ---
                $GHI = (float)($ghi[$i] ?? 0.0);        // Global Horizontal Irradiance
                $DNI = (float)($dni[$i] ?? 0.0);        // Direkt Normal
                $DHI = (float)($dhi[$i] ?? 0.0);        // Diffus

                // Maskenfilter
                $DNI_eff = $dni_eff; // bereits korrekt: 0 bei Schatten
                $DHI_eff = $dhi_eff;

                // --- POA (Plane-of-Array Irradiance) korrekt nach PVGIS/ISO ---
                $poa_beam    = $DNI_eff * $cosT;                        // Direkt zur Modulfläche
                $poa_diffuse = $DHI_eff * (1 + cos($tilt)) * 0.5;       // Himmel halbseitig
                $poa_ground  = $GHI * $albedo * (1 - cos($tilt)) * 0.5; // Bodenreflexion

                $poa = $poa_beam + $poa_diffuse + $poa_ground;

                if ($poa < 0) $poa = 0.0;

                // Zelltemperatur (NOCT, W/m²)
                $tcell = ($temp[$i] ?? 20.0) + ($NOCT - 20.0)/800.0 * $poa;

                // Energie (kWh) pro Stunde
                $e_kwh = $kWp * ($poa / 1000.0) * $loss * (1 + $gamma * ($tcell - 25.0));
                if ($e_kwh < 0) $e_kwh = 0.0;

                // Leistung (W)
                $pW = $e_kwh * 1000.0; // 1h Mittelwert
                if ($inv > 0 && $pW > $inv * 1000.0) {
                    $pW = $inv * 1000.0;
                    $e_kwh = $inv * 1.0; // 1h * inv[kW]
                }

                // Debug am Now-Index
                if ($i === $nowIdx) {
                    $this->SendDebug('SUN', sprintf('[%s] t=%s | elev=%.1f° | cosZ=%.3f', $name, $times[$i], 90 - rad2deg($zen), cos($zen)), 0);
                    $this->SendDebug('MASK', sprintf('[%s] t=%s | azMask=%.1f° | hEl=%.1f° | elSun=%.1f°', $name, $times[$i], $azMaskDeg, $hEl, $elSun), 0);
                }

                // „Jetzt“
                if ($i === $nowIdx) $now_w = $pW;

                // Diagnose-Logging im Fenster
                if ($diagEnabled) {
                    $dtLocal = new DateTime($times[$i]); // ISO enthält Offset
                    $hourLocal = (int)$dtLocal->format('G');
                    $todayLocal = (new DateTime('now', $dtLocal->getTimezone()))->format('Y-m-d');
                    $dateLocal  = $dtLocal->format('Y-m-d');
                    if ($dateLocal === $todayLocal && $hourLocal >= $diagH0 && $hourLocal <= $diagH1) {
                        $diagRows[] = [
                            't'         => $times[$i],
                            'azMask'    => round($azMaskDeg, 1),
                            'hEl'       => round($hEl, 1),
                            'elSun'     => round($elSun, 1),
                            'dni_eff_W' => round($dni_eff, 1),
                            'dhi_W'     => round($dhi_eff, 1),
                            'cosT'      => round($cosT, 3),
                            'poa_W'     => round($poa, 1),
                            'p_W'       => (int)round($pW),
                            'shaded'    => ($elSun < $hEl)
                        ];
                    }
                }

                // Tageskörbe
                $off = $this->dayIndexDSTSafe($times[$i], $times[0]);
                if (!isset($daily[$off])) $daily[$off] = 0.0;
                $daily[$off] += $e_kwh;

                // Serie
                $series[] = [
                    't'   => $times[$i],
                    'p_w' => (int)round($pW),
                    'e_kwh' => $e_kwh
                ];

                // Gesamt
                $totalMap[$times[$i]] = ($totalMap[$times[$i]] ?? 0) + (int)round($pW);
            }

            $stringsOut[$ident] = [
                'now_w'     => $now_w,
                'today_kwh' => $daily[0] ?? 0.0,
                'json'      => $series,
                'horizon'   => $mask,
                'diag'      => $diagRows
            ];

            $sumToday    += $daily[0] ?? 0.0;
            $sumTomorrow += $daily[1] ?? 0.0;
            $sumAfter    += $daily[2] ?? 0.0;
        }

        // Gesamtzeitreihe sortieren
        ksort($totalMap);
        $jsonTotal = [];
        foreach ($totalMap as $t => $p) {
            $jsonTotal[] = ['t' => $t, 'p_w' => $p];
        }

        // Rückgabe
        // total_power_now_w = Summe aller Strings „jetzt“
        $total_now_w = 0.0;
        foreach ($stringsOut as $s) $total_now_w += $s['now_w'];

        return [
            'total_power_now_w' => $total_now_w,
            'daily' => [
                '0' => $sumToday,
                '1' => $sumTomorrow,
                '2' => $sumAfter
            ],
            'strings'   => $stringsOut,
            'json_total'=> $jsonTotal
        ];
    }

    private function computePV_Fallback(array $fc): array
    {
        return [
            'total_power_now_w' => 0,
            'daily' => ['0'=>0.0, '1'=>0.0, '2'=>0.0],
            'strings' => [],
            'json_total' => []
        ];
    }

    private function computePV_ForecastOnly(array $fc): array
    {
        // identisch zu deinem POA/Strings/Masken-Teil,
        // aber OHNE Nowcasting
        return $this->computePV_FinalFromDataset($fc);
    }    

    private function computePV_FinalFromDataset(array $fc): array
    {
        if (empty($fc['hourly']['time'])) {
            return [
                'total_power_now_w' => 0,
                'daily' => ['0'=>0.0,'1'=>0.0,'2'=>0.0],
                'strings' => [],
                'json_total' => []
            ];
        }

        // Forecast-Daten
        $times = $fc['hourly']['time'];
        $ghi   = $fc['hourly']['shortwave_radiation'] ?? [];
        $dni   = $fc['hourly']['direct_normal_irradiance'] ?? [];
        $dhi   = $fc['hourly']['diffuse_radiation'] ?? [];
        $temp  = $fc['hourly']['temperature_2m'] ?? [];

        $n = count($times);
        if ($n === 0) {
            return [
                'total_power_now_w' => 0,
                'daily' => ['0'=>0.0,'1'=>0.0,'2'=>0.0],
                'strings' => [],
                'json_total' => []
            ];
        }

        // Sonnenposition vorberechnen
        $latRad = deg2rad((float)$this->ReadPropertyFloat('Latitude'));
        $lonRad = deg2rad((float)$this->ReadPropertyFloat('Longitude'));

        $solar = [];
        for ($i = 0; $i < $n; $i++) {
            $dt = new DateTime($times[$i]);
            $dt->setTimezone(new DateTimeZone('UTC'));
            $solar[$i] = $this->solarPosApprox($dt->getTimestamp(), $latRad, $lonRad);
        }

        // Arrays / Strings
        $arrays = $this->getArrays();
        $albedo = (float)$this->ReadPropertyFloat('Albedo');

        // Jetzt-Index (für Leistung "now")
        $now = time();
        $nowIdx = 0; $best = PHP_INT_MAX;
        for ($i = 0; $i < $n; $i++) {
            $d = abs(strtotime($times[$i]) - $now);
            if ($d < $best) { $best = $d; $nowIdx = $i; }
        }

        // Tag-Referenz
        $refDay = strtotime(substr($times[0], 0, 10));

        $stringsOut = [];
        $totalMap = [];
        $sumToday = $sumTomorrow = $sumAfter = 0.0;

        // --- PRO STRING ---
        foreach ($arrays as $idx => $arr) {

            $name = (string)($arr['Name'] ?? ("Array_".$idx));
            $ident = $this->arrayIdent($name, $idx);

            $tilt = deg2rad((float)($arr['Tilt'] ?? 30));
            $azM  = deg2rad(-(float)($arr['Azimuth'] ?? 0));
            $kWp  = (float)($arr['kWp'] ?? 1.0);
            $loss = (float)($arr['LossFactor'] ?? 0.96);
            $gamma= (float)($arr['Gamma'] ?? -0.004);
            $NOCT = (float)($arr['NOCT'] ?? 45.0);
            $inv  = (float)($arr['InverterLimit_kW'] ?? 0.0);
            $mask = $this->parseHorizonMask($arr['HorizonMask'] ?? []);
            $diffOb = (float)($arr['DiffuseObstruction'] ?? 1.0);

            $series = [];
            $daily = [];
            $now_w = 0.0;

            for ($i = 0; $i < $n; $i++) {

                $zen = $solar[$i]['zenith'];
                $azs = $solar[$i]['azimuth'];

                // Einfallswinkel
                $cosT = $this->cosIncidence($tilt, $azM, $zen, $azs);
                if ($cosT < 0) $cosT = 0.0;

                $elSun = 90 - rad2deg($zen);
                $azMaskDeg = fmod(( -rad2deg($azs) + 540.0 ), 360.0) - 180.0;
                $hEl = $this->horizonElevation($mask, deg2rad($azMaskDeg));

                $dni_eff = ($elSun < $hEl) ? 0.0 : ($dni[$i] ?? 0.0);
                $dhi_eff = ($elSun < $hEl) ? (($dhi[$i] ?? 0.0) * $diffOb) : ($dhi[$i] ?? 0.0);

                // POA
                $poa = $dni_eff * $cosT
                    + $dhi_eff * (1 + cos($tilt)) / 2
                    + ($ghi[$i] ?? 0.0) * $albedo * (1 - cos($tilt)) / 2;

                if ($poa < 0) $poa = 0.0;

                // Modultemperatur
                $tcell = ($temp[$i] ?? 20.0) + ($NOCT - 20.0)/800.0 * $poa;

                // Energie (kWh)
                $e_kwh = $kWp * ($poa / 1000.0) * $loss * (1 + $gamma * ($tcell - 25.0));
                if ($e_kwh < 0) $e_kwh = 0.0;

                // Leistung (W)
                $pW = $e_kwh * 1000.0;
                if ($inv > 0 && $pW > $inv * 1000.0) {
                    $pW = $inv * 1000.0;
                }

                // Jetzt
                if ($i === $nowIdx) $now_w = $pW;

                // Serienpunkt
                $series[] = [
                    't'   => $times[$i],
                    'p_w' => (int)round($pW),
                    'e_kwh' => $e_kwh
                ];

                // Tag summieren
                $off = $this->dayIndexDSTSafe($times[$i], $times[0]);
                if (!isset($daily[$off])) $daily[$off] = 0.0;
                $daily[$off] += $e_kwh;

                // Gesamt
                $totalMap[$times[$i]] = ($totalMap[$times[$i]] ?? 0) + (int)round($pW);
            }

            $stringsOut[$ident] = [
                'now_w'     => $now_w,
                'today_kwh' => $daily[0] ?? 0.0,
                'json'      => $series,
                'horizon'   => $mask
            ];

            $sumToday    += $daily[0] ?? 0.0;
            $sumTomorrow += $daily[1] ?? 0.0;
            $sumAfter    += $daily[2] ?? 0.0;
        }

        // Gesamtzeitreihe sortieren
        ksort($totalMap);
        $jsonTotal = [];
        foreach ($totalMap as $t => $p) {
            $jsonTotal[] = ['t' => $t, 'p_w' => $p];
        }

        $total_now_w = 0.0;
        foreach ($stringsOut as $s) $total_now_w += $s['now_w'];

        return [
            'total_power_now_w' => $total_now_w,
            'daily' => [
                '0' => $sumToday,
                '1' => $sumTomorrow,
                '2' => $sumAfter
            ],
            'strings' => $stringsOut,
            'json_total' => $jsonTotal
        ];
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */

    private function dayIndexDSTSafe(string $timestamp, string $firstTimestamp): int
    {
        // Datum extrahieren
        $dCur  = substr($timestamp, 0, 10);
        $dBase = substr($firstTimestamp, 0, 10);

        // DateTime-Objekte erstellen
        $dtCur  = new DateTime($dCur . " 00:00:00");
        $dtBase = new DateTime($dBase . " 00:00:00");

        // Differenz in Tagen (DST-sicher)
        $diff = (int)($dtBase->diff($dtCur)->days);

        // Richtung bestimmen
        if ($dtCur < $dtBase) {
            $diff = -$diff;
        }

        return $diff;
    }

    private function getArrays(): array
    {
        $a = json_decode($this->ReadPropertyString('Arrays'), true);
        return is_array($a) ? $a : [];
    }

    private function arrayIdent(string $name, int $idx): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        return strtoupper($base).'_'.$idx;
    }

    private function parseHorizonMask($maskField): array
    {
        if (is_string($maskField)) {
            $m = json_decode($maskField, true);
            return is_array($m) ? $m : [];
        }
        return is_array($maskField) ? $maskField : [];
    }

    private function horizonElevation(array $mask, float $azimuthRad): float
    {
        // 1) Fallback bei leerer Maske → 0°
        if (count($mask) < 2) {
            return 0.0;
        }

        // 2) Normalisieren + in lokales Array kopieren
        $m = [];
        foreach ($mask as $pt) {
            if (!isset($pt['az']) || !isset($pt['el']))
                continue;
            $az = fmod(($pt['az'] + 540.0), 360.0) - 180.0;
            $el = (float)$pt['el'];
            $m[] = ['az' => $az, 'el' => $el];
        }

        if (count($m) < 2)
            return 0.0;

        // 3) Sortieren
        usort($m, fn($a, $b) => $a['az'] <=> $b['az']);

        // 4) Sanfte Glättung (1-2 Nachbarn)
        //    → Übergänge weicher, realistische Schattenlinien
        $smooth = [];
        $N = count($m);
        for ($i = 0; $i < $N; $i++) {
            $prev = $m[($i - 1 + $N) % $N]['el'];
            $mid  = $m[$i]['el'];
            $next = $m[($i + 1) % $N]['el'];

            // Gewichtetes Mittel 20%-60%-20%
            $smooth[] = [
                'az' => $m[$i]['az'],
                'el' => ($prev * 0.2 + $mid * 0.6 + $next * 0.2)
            ];
        }

        // 5) Sonnenazimut normalisieren
        $az = rad2deg($azimuthRad);
        $az = fmod(($az + 540.0), 360.0) - 180.0;

        // 6) Interpolation (zyklisch)
        $prev = $smooth[$N - 1];
        foreach ($smooth as $pt) {
            if ($az <= $pt['az']) {
                $x0 = $prev['az'];
                $y0 = $prev['el'];
                $x1 = $pt['az'];
                $y1 = $pt['el'];

                if ($x1 == $x0)
                    return $y1;

                $t = ($az - $x0) / ($x1 - $x0);
                return $y0 + $t * ($y1 - $y0);
            }
            $prev = $pt;
        }

        // 7) Wrap-Around-interpolation (letzter → erster)
        $first = $smooth[0];
        $x0 = $prev['az'];
        $y0 = $prev['el'];
        $x1 = $first['az'] + 360.0;
        $y1 = $first['el'];

        $azw = ($az < $first['az']) ? $az + 360.0 : $az;
        $t = ($azw - $x0) / ($x1 - $x0);

        return $y0 + $t * ($y1 - $y0);
    }

    private function cosIncidence(float $tilt, float $aziM, float $zenith, float $aziSun): float
    {
        // Modulnormalenvektor
        $nx = sin($tilt) * cos($aziM);
        $ny = sin($tilt) * sin($aziM);
        $nz = cos($tilt);
        // Sonnenvektor
        $sx = sin($zenith) * cos($aziSun);
        $sy = sin($zenith) * sin($aziSun);
        $sz = cos($zenith);
        return $nx*$sx + $ny*$sy + $nz*$sz;
    }

    private function solarPosApprox(int $tsUTC, float $lat, float $lon): array
    {
        // Referenz J2000 12:00 UTC (946728000)
        $d = ($tsUTC - 946728000) / 86400.0;
        $L = deg2rad(fmod(280.46 + 0.9856474 * $d, 360.0));
        $g = deg2rad(fmod(357.528 + 0.9856003 * $d, 360.0));
        $lambda = $L + deg2rad(1.915) * sin($g) + deg2rad(0.020) * sin(2*$g);
        $epsilon = deg2rad(23.439 - 0.0000004 * $d);
        $RA = atan2(cos($epsilon)*sin($lambda), cos($lambda));
        $dec = asin(sin($epsilon)*sin($lambda));
        $GMST = fmod(18.697374558 + 24.06570982441908 * $d, 24.0);
        $LST = deg2rad(($GMST * 15.0)) + $lon;
        $HA = $LST - $RA;

        $x = cos($HA)*cos($dec);
        $y = sin($HA)*cos($dec);
        $z = sin($dec);

        $xhor = $x * sin($lat) - $z * cos($lat);
        $yhor = $y;
        $zhor = $x * cos($lat) + $z * sin($lat);

        $azimuth = atan2($yhor, $xhor) + M_PI; // 0..2π, 0=S
        $zenith  = acos($zhor);
        return ['zenith' => $zenith, 'azimuth' => $azimuth];
    }
}
?>