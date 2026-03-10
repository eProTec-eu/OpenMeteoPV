<?php
declare(strict_types=1);

class OpenMeteoPV extends IPSModule
{
    /* =========================
     * Lifecycle
     * ========================= */
    public function Create()
    {
        parent::Create();
        // Basis-Properties
        $this->RegisterPropertyFloat('Latitude', 52.8343);
        $this->RegisterPropertyFloat('Longitude', 8.1555);
        $this->RegisterPropertyString('Timezone', 'auto');
        // Open-Meteo Optionen
        $this->RegisterPropertyBoolean('UseSatellite', true); // Satellite Radiation API (EU 10-min)
        $this->RegisterPropertyBoolean('UseGTI', false);      // GTI direkt vom API je String (optional)
        $this->RegisterPropertyInteger('ForecastDays', 3);    // 1..16 (Open-Meteo)
        $this->RegisterPropertyInteger('PastDays', 1);        // 0..7
        $this->RegisterPropertyInteger('ResolutionMinutes', 60);// 60/15/10 (Info; Abruf hier stündlich)
        $this->RegisterPropertyInteger('UpdateMinutes', 60);
        $this->RegisterPropertyFloat('Albedo', 0.20);
        $this->RegisterPropertyFloat('NowcastHours', 4.0);   // variable Schiebezeit 0..6h

        // Diagnose-Optionen
        $this->RegisterPropertyBoolean('EnableDiagnostics', false);
        $this->RegisterPropertyInteger('DiagStartHour', 8);   // lokale Stunde (0..23)
        $this->RegisterPropertyInteger('DiagEndHour', 11);    // lokale Stunde (0..23)

        // Strings/Ausrichtungen als JSON in einer Zeile (kompatible Eingabe über ValidationTextBox)
        $defaultArrays = json_encode([[
            'Name' => 'Sued',
            'kWp' => 7.0,
            'Tilt' => 30.0,
            'Azimuth' => 0.0, // 0°=Süd, -90°=Ost, +90°=West, ±180°=Nord
            'LossFactor' => 0.90,
            'Gamma' => -0.0040,
            'NOCT' => 45.0,
            'InverterLimit_kW' => 6.0,
            'HorizonMask' => [
                ['az' => -60, 'el' => 5],
                ['az' =>   0, 'el' => 8],
                ['az' =>  60, 'el' => 6]
            ],
            'DiffuseObstruction' => 1.00
        ]]);
        $this->RegisterPropertyString('Arrays', $defaultArrays);

        // Variablen (Gesamt)
        $this->RegisterVariableInteger('TotalPower_W', 'Leistung (gesamt) [W]', '~Watt', 10);
        $this->RegisterVariableFloat ('Today_kWh', 'Energie heute [kWh]', '', 20);
        $this->RegisterVariableFloat ('Tomorrow_kWh', 'Energie morgen [kWh]', '', 21);
        $this->RegisterVariableFloat ('DayAfter_kWh', 'Energie übermorgen [kWh]', '', 22);
        $this->RegisterVariableString('ForecastJSON', 'Forecast JSON (gesamt)', '', 90);

        // Timer → ruft RequestAction('Update') auf (keine globale Funktion nötig)
        $this->RegisterTimer(
            'OMPV_Update',
            0,
            'IPS_RequestAction($_IPS["TARGET"], "Update", 0);'
        );
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Per-String Variablen (Power/Today/ForecastJSON/HorizonJSON/DiagJSON)
        $arrays = $this->getArrays();
        $pos = 30;
        $i = 0;
        foreach ($arrays as $a) {
            $name = isset($a['Name']) ? (string)$a['Name'] : ('Array_'.$i);
            $ident = $this->arrayIdent($name, $i);
            $this->RegisterVariableInteger($ident.'_Power_W', "Leistung {$name} [W]", '~Watt', $pos++);
            $this->RegisterVariableFloat ($ident.'_Today_kWh', "Energie heute {$name} [kWh]", '', $pos++);
            $this->RegisterVariableString($ident.'_ForecastJSON', "Forecast JSON {$name}", '', $pos++);
            $this->RegisterVariableString($ident.'_HorizonJSON', "Horizon JSON {$name}", '', $pos++);
            $this->RegisterVariableString($ident.'_DiagJSON', "Diagnose JSON {$name}", '', $pos++);
            $i++;
        }

        // Timerintervall
        $minutes = max(10, (int)$this->ReadPropertyInteger('UpdateMinutes'));
        $this->SetTimerInterval('OMPV_Update', $minutes * 60 * 1000);

        // Initial-Update
        $this->Update();
    }

    /* =========================
     * Konfigurationsformular (kompatibel)
     * ========================= */
    public function GetConfigurationForm()
    {
        $arraysJson = $this->ReadPropertyString('Arrays');
        if ($arraysJson === '' || $arraysJson === null) {
            $arraysJson = '[]';
        }
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
                ['type' => 'CheckBox', 'name' => 'UseSatellite', 'caption' => 'Satellite Radiation API (EU, 10‑min)'],
                ['type' => 'CheckBox', 'name' => 'UseGTI', 'caption' => 'GTI direkt (je String)'],
                ['type' => 'NumberSpinner', 'name' => 'ResolutionMinutes', 'caption' => 'Auflösung (min; 10/15/60)'],
                ['type' => 'NumberSpinner', 'name' => 'ForecastDays', 'caption' => 'Prognose‑Tage (1..16)'],
                ['type' => 'NumberSpinner', 'name' => 'PastDays', 'caption' => 'Vergangenheits‑Tage (0..7)'],
                ['type' => 'NumberSpinner', 'name' => 'UpdateMinutes', 'caption' => 'Update‑Intervall (min)'],
                ['type' => 'NumberSpinner', 'name' => 'Albedo', 'caption' => 'Albedo (0..1)', 'digits' => 2, 'minimum' => 0, 'maximum' => 1],
                ['type' => 'Label', 'caption' => 'Diagnose (optional):'],
                ['type' => 'CheckBox', 'name' => 'EnableDiagnostics', 'caption' => 'Diagnose aktiv (08–11 Uhr loggen)'],
                ['type' => 'NumberSpinner', 'name' => 'DiagStartHour', 'caption' => 'Diagnose Startstunde (0..23)'],
                ['type' => 'NumberSpinner', 'name' => 'DiagEndHour', 'caption' => 'Diagnose Endstunde (0..23)'],
                ['type' => 'Label', 'caption' => 'Strings/Ausrichtungen als JSON (eine Zeile, siehe README):'],
                ['type' => 'ValidationTextBox', 'name' => 'Arrays', 'caption' => 'JSON', 'value' => $arraysJson],
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt aktualisieren', 'onClick' => 'IPS_RequestAction($id, "Update", 0);']
            ]
        ]);
    }

    /* =========================
     * RequestAction → Update
     * ========================= */
    public function RequestAction($Ident, $Value)
    {
        if ($Ident === 'Update') {
            $this->Update();
            return true;
        }
        throw new Exception("Invalid Ident: $Ident");
    }

    /* =========================
     * Update / Logik
     * ========================= */
    public function Update()
    {
        try {
            $raw = $this->fetchOpenMeteo();
            if (!$raw) {
                $this->SendDebug('Update', 'Keine Daten empfangen', 0);
                return;
            }
            $r = $this->computePV($raw);
            // Gesamt
            $this->SetValue('TotalPower_W', (int)round($r['total_power_now_w']));
            $this->SetValue('Today_kWh',    round($r['daily']['0'] ?? 0.0, 2));
            $this->SetValue('Tomorrow_kWh', round($r['daily']['1'] ?? 0.0, 2));
            $this->SetValue('DayAfter_kWh', round($r['daily']['2'] ?? 0.0, 2));
            $this->SetValue('ForecastJSON', json_encode($r['json_total'], JSON_UNESCAPED_SLASHES));

            // Strings
            foreach ($r['strings'] as $ident => $d) {
                $this->SetValue($ident.'_Power_W',   (int)round($d['now_w']));
                $this->SetValue($ident.'_Today_kWh', round($d['today_kwh'], 2));
                $this->SetValue($ident.'_ForecastJSON', json_encode($d['json'], JSON_UNESCAPED_SLASHES));
                if (!empty($d['horizon'])) {
                    $this->SetValue($ident.'_HorizonJSON', json_encode($d['horizon'], JSON_UNESCAPED_SLASHES));
                }
                if (!empty($d['diag'])) {
                    $this->SetValue($ident.'_DiagJSON', json_encode($d['diag'], JSON_UNESCAPED_SLASHES));
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Update ERROR', $e->getMessage(), 0);
        }
    }

    /* =========================
     * Internals / Helpers
     * ========================= */
    private function getArrays(): array
    {
        $a = json_decode($this->ReadPropertyString('Arrays'), true);
        return is_array($a) ? $a : [];
    }

    private function arrayIdent(string $name, int $idx): string
    {
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        return strtoupper($base) . '_' . $idx;
    }

    /*private function fetchOpenMeteo(): ?array
    {
        $lat = (float)$this->ReadPropertyFloat('Latitude');
        $lon = (float)$this->ReadPropertyFloat('Longitude');
        $tz  = $this->ReadPropertyString('Timezone') === 'auto' ? 'auto' : urlencode($this->ReadPropertyString('Timezone'));
        $fd  = max(1, min(16, (int)$this->ReadPropertyInteger('ForecastDays')));
        $pd  = max(0, min(7, (int)$this->ReadPropertyInteger('PastDays')));
        $useSat = (bool)$this->ReadPropertyBoolean('UseSatellite');

        $hourly = 'shortwave_radiation,direct_radiation,diffuse_radiation,direct_normal_irradiance,temperature_2m,cloud_cover';

        // 1) Primär: Satellite-API oder Forecast-API (je nach UseSatellite)
        $base = $useSat ? 'https://api.open-meteo.com/v1/satellite'
                        : 'https://api.open-meteo.com/v1/forecast';
        $url1 = sprintf('%s?latitude=%F&longitude=%F&hourly=%s&timezone=%s&forecast_days=%d&past_days=%d',
                        $base, $lat, $lon, $hourly, $tz, $fd, $pd);
        $raw1 = $this->fetchUrlJson($url1, 'primary');

        // 2) Fallback: Forecast-API, falls hourly leer/fehlt
        if (!is_array($raw1) || empty($raw1['hourly']['time'])) {
            $url2 = sprintf('%s?latitude=%F&longitude=%F&hourly=%s&timezone=%s&forecast_days=%d&past_days=%d',
                            'https://api.open-meteo.com/v1/forecast', $lat, $lon, $hourly, $tz, $fd, $pd);
            $raw2 = $this->fetchUrlJson($url2, 'fallback-forecast');
            return (is_array($raw2) && !empty($raw2['hourly']['time'])) ? $raw2 : null;
        }
        return $raw1;
    }*/
    private function fetchOpenMeteo(): ?array
    {
        $lat = (float)$this->ReadPropertyFloat('Latitude');
        $lon = (float)$this->ReadPropertyFloat('Longitude');
        $tz  = ($this->ReadPropertyString('Timezone') === 'auto')
            ? 'auto'
            : urlencode($this->ReadPropertyString('Timezone'));

        // Formularwerte (Tage) sicher einlesen
        $pd_days = max(0, min(7,  (int)$this->ReadPropertyInteger('PastDays')));     // 0..7
        $fd_days = max(1, min(16, (int)$this->ReadPropertyInteger('ForecastDays'))); // 1..16

        // Satellite braucht Stunden, nicht Tage (Doku: forecast_hours / past_hours)
        $past_hours     = $pd_days * 24;
        $forecast_hours = $fd_days * 24;

        $useSat = (bool)$this->ReadPropertyBoolean('UseSatellite');

        // ===== Variablenlisten =====
        // Satellite: NUR Strahlungs-Variablen; Temperatur/Cloud NICHT erlaubt! (sonst leere Response)
        // Quelle: Satellite Radiation API
        $hourly_sat = 'shortwave_radiation,direct_radiation,diffuse_radiation,direct_normal_irradiance';

        // Forecast: was wir zum Mergen brauchen
        $hourly_fc  = 'temperature_2m,cloud_cover';

        // -------- 1) SATELLITE-PRIME (EU 10‑min nativ, per API 1‑stündig) --------
        if ($useSat) {
            $urlSat = sprintf(
                'https://api.open-meteo.com/v1/satellite?' .
                'latitude=%F&longitude=%F&hourly=%s&timezone=%s&forecast_hours=%d&past_hours=%d',
                $lat, $lon, $hourly_sat, $tz, $forecast_hours, $past_hours
            );
            $sat = $this->fetchUrlJson($urlSat, 'primary');

            // Wenn Satellite Daten liefert, aber ohne Temperatur → Forecast nachladen & MERGEN
            if (is_array($sat) && !empty($sat['hourly']['time'])) {

                // 1b) Minimaler Forecast-Call NUR für Temperatur/Cloud (Days-Parameter!)
                $urlFc = sprintf(
                    'https://api.open-meteo.com/v1/forecast?' .
                    'latitude=%F&longitude=%F&hourly=%s&timezone=%s&forecast_days=%d&past_days=%d',
                    $lat, $lon, $hourly_fc, $tz, $fd_days, $pd_days
                );
                $fc = $this->fetchUrlJson($urlFc, 'merge-forecast');

                // Falls Forecast auch da: per Zeitstempel zusammenführen
                if (is_array($fc) && !empty($fc['hourly']['time'])) {
                    // Map der Forecast-Zeitstempel -> Werte
                    $t2mMap = [];
                    $cldMap = [];
                    $fcTimes = $fc['hourly']['time'];
                    $fcT2m   = $fc['hourly']['temperature_2m'] ?? [];
                    $fcCld   = $fc['hourly']['cloud_cover']    ?? [];
                    $nFC = count($fcTimes);

                    for ($i = 0; $i < $nFC; $i++) {
                        $t = (string)$fcTimes[$i];
                        if (isset($fcT2m[$i])) $t2mMap[$t] = $fcT2m[$i];
                        if (isset($fcCld[$i])) $cldMap[$t] = $fcCld[$i];
                    }

                    // Ziel-Arrays in Satellite-JSON anlegen
                    if (!isset($sat['hourly']['temperature_2m'])) $sat['hourly']['temperature_2m'] = [];
                    if (!isset($sat['hourly']['cloud_cover']))    $sat['hourly']['cloud_cover']    = [];

                    // Satellite liefert 1‑stündige Zeiten (Backward‑Average) → sollten zu Forecast-Zeiten passen
                    $satTimes = $sat['hourly']['time'];
                    $nSat = count($satTimes);

                    for ($i = 0; $i < $nSat; $i++) {
                        $ts = (string)$satTimes[$i];

                        // exakter ISO‑Match (beide mit timezone=auto)
                        $t2m = $t2mMap[$ts] ?? null;
                        $cld = $cldMap[$ts] ?? null;

                        // Falls kein exakter Match (z. B. 10‑min offset), versuche auf volle Stunde zu normalisieren
                        if ($t2m === null || $cld === null) {
                            // Round down to hour (lokale ISO)
                            $tsHour = substr($ts, 0, 13) . ':00:00' . substr($ts, 19); // "YYYY-MM-DDTHH:00:00+XX:YY"
                            if ($t2m === null && isset($t2mMap[$tsHour])) $t2m = $t2mMap[$tsHour];
                            if ($cld === null && isset($cldMap[$tsHour])) $cld = $cldMap[$tsHour];
                        }

                        // Fallbacks (Temperatur braucht die PV‑Berechnung zwingend)
                        if ($t2m === null) $t2m = 12.0;       // milder Default, wird i. d. R. überschrieben
                        if ($cld === null) $cld = 0.0;

                        $sat['hourly']['temperature_2m'][$i] = $t2m;
                        $sat['hourly']['cloud_cover'][$i]    = $cld;
                    }
                }

                // Ergebnis: Satellite mit gemergter Temperatur/Cloud
                return $sat;
            }

            // Satellite leer → Fallback FORECAST (Days)
            $urlFcFull = sprintf(
                'https://api.open-meteo.com/v1/forecast?' .
                'latitude=%F&longitude=%F&hourly=%s,%s&timezone=%s&forecast_days=%d&past_days=%d',
                $lat, $lon,
                // hier für Fallback die komplette Liste (Radiation + T2m/Cloud):
                'shortwave_radiation,direct_radiation,diffuse_radiation,direct_normal_irradiance',
                'temperature_2m,cloud_cover',
                $tz, $fd_days, $pd_days
            );
            $raw2 = $this->fetchUrlJson($urlFcFull, 'fallback-forecast');
            return (is_array($raw2) && !empty($raw2['hourly']['time'])) ? $raw2 : null;
        }

        // -------- 2) FORECAST-PRIME (Days) --------
        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?' .
            'latitude=%F&longitude=%F&hourly=%s,%s&timezone=%s&forecast_days=%d&past_days=%d',
            $lat, $lon,
            'shortwave_radiation,direct_radiation,diffuse_radiation,direct_normal_irradiance',
            'temperature_2m,cloud_cover',
            $tz, $fd_days, $pd_days
        );

        $raw = $this->fetchUrlJson($url, 'forecast');
        return (is_array($raw) && !empty($raw['hourly']['time'])) ? $raw : null;
    }

    private function fetchUrlJson(string $url, string $tag): ?array
    {
        $this->SendDebug('OpenMeteo URL ['.$tag.']', $url, 0);
        // robuster Abruf: bevorzugt Sys_GetURLContent (Symcon), sonst stream
        if (function_exists('Sys_GetURLContent')) {
            $body = @Sys_GetURLContent($url);
        } else {
            $ctx = stream_context_create(['http' => ['timeout' => 15]]);
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
        // Diagnose ins Debug
        $vars = implode(',', array_keys($j['hourly'] ?? []));
        $cnt  = count($j['hourly']['time'] ?? []);
        $this->SendDebug('OpenMeteo ['.$tag.']', 'hourly='.$vars.' | Punkte='.$cnt, 0);
        $hasDNI = isset(($j['hourly'] ?? [])['direct_normal_irradiance']);
        $this->SendDebug('OpenMeteo ['.$tag.']', 'has direct_normal_irradiance = '.($hasDNI ? 'yes' : 'no'), 0);
        return $j;
    }

    private function computeSatelliteNowcast(array $sat, array $fc, float $hours): array
    {
        // Satellite & Forecast Zeitreihen prüfen
        if (empty($sat['hourly']['time']) ||
            empty($sat['hourly']['shortwave_radiation']) ||
            empty($sat['hourly']['direct_normal_irradiance']) ||
            empty($fc['hourly']['time'])) {
            return $fc;
        }

        $sat_time = $sat['hourly']['time'];
        $ghi_sat  = $sat['hourly']['shortwave_radiation'];
        $dni_sat  = $sat['hourly']['direct_normal_irradiance'];

        $n = count($sat_time);
        if ($n < 2) return $fc;   // mindestens zwei Satellite-Punkte nötig

        // === 1) Satellite-Trend (Now -1h → Now)
        $ghi_now  = $ghi_sat[$n - 1];
        $dni_now  = $dni_sat[$n - 1];
        $ghi_prev = $ghi_sat[$n - 2];
        $dni_prev = $dni_sat[$n - 2];

        // Änderungsrate pro Minute
        $trend_ghi = ($ghi_now - $ghi_prev) / 60.0;
        $trend_dni = ($dni_now - $dni_prev) / 60.0;

        // === 2) Zukunft t Minuten (variable Schiebezeit)
        $t_minutes = $hours * 60.0;

        // Aus Satellite extrapoliert
        $ghi_sat_future = max(0.0, $ghi_now + $trend_ghi * $t_minutes);
        $dni_sat_future = max(0.0, $dni_now + $trend_dni * $t_minutes);

        // === 3) Forecast-Werte matchen
        // Forecast index 1 = nächste Stunde
        $ghi_fc_series = $fc['hourly']['shortwave_radiation'] ?? [];
        $dni_fc_series = $fc['hourly']['direct_normal_irradiance'] ?? [];

        if (count($ghi_fc_series) < 5 || count($dni_fc_series) < 5)
            return $fc;

        // === 4) Exponentielle Gewichtung (Glättung)
        // tau bestimmt, wie stark Satellite vs Forecast dominiert
        $tau = 120.0; // 120 Minuten = gute Stabilität
        $w   = exp(-$t_minutes / $tau);

        // === 5) Für die nächsten 4 Stunden anwenden
        // h = 1..4 (Zukunftsstunden)
        for ($h = 1; $h <= 4; $h++) {

            // Prognosezeit dieser Stunde
            $t_h = $h * 60;  

            // Satellite-Trend für h
            $ghi_sat_h = max(0.0, $ghi_now + $trend_ghi * $t_h);
            $dni_sat_h = max(0.0, $dni_now + $trend_dni * $t_h);

            // Forecast
            $ghi_fc_h = $ghi_fc_series[$h] ?? $ghi_now;
            $dni_fc_h = $dni_fc_series[$h] ?? $dni_now;

            // Gewicht für diese Stunde
            $w_h = exp(-( $t_h / $tau ));

            // Hybrid-Nowcast
            $ghi_new = $w_h * $ghi_sat_h + (1 - $w_h) * $ghi_fc_h;
            $dni_new = $w_h * $dni_sat_h + (1 - $w_h) * $dni_fc_h;

            // einsetzen
            $fc['hourly']['shortwave_radiation'][$h] = $ghi_new;
            $fc['hourly']['direct_normal_irradiance'][$h] = $dni_new;
        }

        return $fc;
    }

    private function computePV(array $sat, array $fc): array
    {
        // ============================================================
        // 1) Zeitachsen
        // ============================================================
        if (empty($sat['hourly']['time']) || empty($fc['hourly']['time'])) {
            return $this->computePV_Fallback($fc);
        }

        $sat_time = $sat['hourly']['time'];
        $fc_time  = $fc['hourly']['time'];

        $ghi_sat  = $sat['hourly']['shortwave_radiation'] ?? [];
        $dni_sat  = $sat['hourly']['direct_normal_irradiance'] ?? [];

        $ghi_fc   = $fc['hourly']['shortwave_radiation'] ?? [];
        $dni_fc   = $fc['hourly']['direct_normal_irradiance'] ?? [];

        $temp_fc  = $fc['hourly']['temperature_2m'] ?? [];
        $dhi_fc   = $fc['hourly']['diffuse_radiation'] ?? [];

        $nSat = count($sat_time);
        $nFc  = count($fc_time);

        if ($nSat < 2 || $nFc < 4) {
            return $this->computePV_Fallback($fc);
        }

        // ============================================================
        // 2) Schiebezeit (NowcastHours) – in Minuten
        // ============================================================
        $hours   = (float)$this->ReadPropertyFloat('NowcastHours');  // z.B. 4.0
        $maxH    = min($hours, 6.0);                                 // Sicherung
        $tFuture = $maxH * 60.0;                                     // in Minuten

        // ============================================================
        // 3) Satellite-Trend
        //    letzter Punkt = "jetzt" (Satellite ~ now - 20..30min)
        //    vorletzter Punkt = jetzt - 1h
        // ============================================================
        $ghi_now  = $ghi_sat[$nSat - 1];
        $dni_now  = $dni_sat[$nSat - 1];

        $ghi_prev = $ghi_sat[$nSat - 2];
        $dni_prev = $dni_sat[$nSat - 2];

        // Trend pro Minute
        $trend_ghi = ($ghi_now - $ghi_prev) / 60.0;
        $trend_dni = ($dni_now - $dni_prev) / 60.0;

        // ============================================================
        // 4) Zukunft (Satellite-Extrapolation) + Forecast-Blending
        // ============================================================
        // Zeitkonstante für exponenzielles Abklingen
        $tau = 120.0;  // 120 min = Forecast übernimmt nach 2h schrittweise

        // Für die nächsten +1h, +2h, +3h, +4h ... begrenzt durch NowcastHours
        $hLimit = min( (int)ceil($maxH), $nFc - 1 );

        for ($h = 1; $h <= $hLimit; $h++) {

            $t_h = $h * 60.0;   // Minuten in der Zukunft

            // Satellite-Extrapolation
            $ghi_sat_h = max(0.0, $ghi_now + $trend_ghi * $t_h);
            $dni_sat_h = max(0.0, $dni_now + $trend_dni * $t_h);

            // Forecastwerte
            $ghi_fc_h = $ghi_fc[$h] ?? $ghi_fc[$hLimit-1];
            $dni_fc_h = $dni_fc[$h] ?? $dni_fc[$hLimit-1];

            // Exponentielles Gewicht
            $w = exp(-$t_h / $tau);  // 1.0 → 0.0

            // Nowcast = Hybrid
            $ghi_new = $w * $ghi_sat_h + (1 - $w) * $ghi_fc_h;
            $dni_new = $w * $dni_sat_h + (1 - $w) * $dni_fc_h;

            // Forecast überschreiben
            $ghi_fc[$h] = $ghi_new;
            $dni_fc[$h] = $dni_new;
        }

        // Ersetzen im Forecast-Dataset
        $fc['hourly']['shortwave_radiation']       = $ghi_fc;
        $fc['hourly']['direct_normal_irradiance']  = $dni_fc;

        // ============================================================
        // 5) Berechnung der PV-Erträge (deine vorhandene Logik)
        // ============================================================
        // -> hier nutzt du weiterhin:
        //    - GHI/DNI aus $fc
        //    - DHI aus $fc
        //    - temp aus $fc
        //    - Solarpos, Mask, POA, NOCT
        //    - Strings
        // ============================================================

        return $this->computePV_FinalFromDataset($fc);
    }

    private function findNowIndex(array $times): int
    {
        $now = time();
        $best = 0; $bestDiff = PHP_INT_MAX;
        foreach ($times as $i => $iso) {
            $ts = strtotime($iso);
            $d = abs($ts - $now);
            if ($d < $bestDiff) { $bestDiff = $d; $best = $i; }
        }
        return $best;
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
        if (count($mask) < 2) return 0.0;

        // Masken-Azimutwerte in [-180°, +180°) normalisieren
        foreach ($mask as &$pt) {
            $az = (float)$pt['az'];
            $az = fmod(($az + 540.0), 360.0) - 180.0;
            $pt['az'] = $az;
        }
        unset($pt);

        // Sonnen-Azimut (in Grad) aus Eingabe und normalisieren
        $az = rad2deg($azimuthRad);
        $az = fmod(($az + 540.0), 360.0) - 180.0;

        usort($mask, fn($a,$b) => ($a['az'] <=> $b['az']));

        $prev = end($mask); reset($mask);
        foreach ($mask as $pt) {
            if ($az <= $pt['az']) {
                $x0 = (float)$prev['az']; $y0 = (float)$prev['el'];
                $x1 = (float)$pt['az'];   $y1 = (float)$pt['el'];
                if ($x1 == $x0) return $y1;
                $t = ($az - $x0) / ($x1 - $x0);
                return $y0 + $t * ($y1 - $y0);
            }
            $prev = $pt;
        }
        // Wrap-around (letzter -> erster)
        $first = $mask[0];
        $x0 = (float)$prev['az'];   $y0 = (float)$prev['el'];
        $x1 = (float)$first['az'] + 360.0; $y1 = (float)$first['el'];
        $azw = ($az < $first['az']) ? $az + 360.0 : $az;
        $t = ($azw - $x0) / ($x1 - $x0);
        return $y0 + $t * ($y1 - $y0);
    }

    private function cosIncidence(float $tilt, float $aziM, float $zenith, float $aziSun): float
    {
        $nx = sin($tilt) * cos($aziM);
        $ny = sin($tilt) * sin($aziM);
        $nz = cos($tilt);
        $sx = sin($zenith) * cos($aziSun);
        $sy = sin($zenith) * sin($aziSun);
        $sz = cos($zenith);
        return $nx*$sx + $ny*$sy + $nz*$sz;
    }

    private function solarPosApprox(int $tsUTC, float $lat, float $lon): array
    {
        // vereinfachte Sonnenstandsberechnung (ausreichend für Forecast-Zwecke)
        // Referenz: 2000-01-01 12:00:00 UTC (946728000)
        $d = ($tsUTC - 946728000) / 86400.0;
        $L = deg2rad(fmod(280.46 + 0.9856474 * $d, 360.0));
        $g = deg2rad(fmod(357.528 + 0.9856003 * $d, 360.0));
        $lambda = $L + deg2rad(1.915) * sin($g) + deg2rad(0.020) * sin(2*$g);
        $epsilon = deg2rad(23.439 - 0.0000004 * $d);
        $RA = atan2(cos($epsilon)*sin($lambda), cos($lambda));
        $dec = asin(sin($epsilon)*sin($lambda));
        // Greenwich Mean Sidereal Time in Stunden
        $GMST = fmod(18.697374558 + 24.06570982441908 * $d, 24.0);
        // Local Sidereal Time (Radiant)
        $LST = deg2rad(($GMST * 15.0)) + $lon;
        $HA = $LST - $RA; // Stundenwinkel
        // Horizontal-Koordinaten
        $x = cos($HA)*cos($dec);
        $y = sin($HA)*cos($dec);
        $z = sin($dec);
        $xhor = $x * sin($lat) - $z * cos($lat);
        $yhor = $y;
        $zhor = $x * cos($lat) + $z * sin($lat);
        $azimuth = atan2($yhor, $xhor) + M_PI; // 0..2π, 0=Süden
        $zenith  = acos($zhor);
        return ['zenith' => $zenith, 'azimuth' => $azimuth];
    }
}
?>
