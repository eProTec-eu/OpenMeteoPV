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
        // Per-String Variablen (Power/Today/ForecastJSON/HorizonJSON)
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

    private function fetchOpenMeteo(): ?array
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

    private function computePV(array $raw): array
    {
        // --------------------------------------------------------------------
        // 1. OPEN-METEO HOURLY EINLESEN (Forecast/Satellite liefern W/m²!)
        // --------------------------------------------------------------------
        $t_h       = $raw['hourly']['time'] ?? [];
        $ghi_h     = $raw['hourly']['shortwave_radiation'] ?? [];
        $dir_hor_h = $raw['hourly']['direct_radiation'] ?? [];            // Direkt auf Horizontal (DBH)
        $dni_n_h   = $raw['hourly']['direct_normal_irradiance'] ?? [];    // Echte DNI (normal); kann fehlen
        $dhi_h     = $raw['hourly']['diffuse_radiation'] ?? [];
        $t2m_h     = $raw['hourly']['temperature_2m'] ?? [];

        $nh = min(count($t_h), count($ghi_h), count($dir_hor_h), count($dhi_h), count($t2m_h));
        if ($nh === 0) {
            return [
                'total_power_now_w' => 0,
                'daily' => [],
                'strings' => [],
                'json_total' => []
            ];
        }

        // Einheitenerkennung (Forecast/Satellite = W/m²)
        $units = $raw['hourly_units'] ?? [];
        $uGHI  = $units['shortwave_radiation'] ?? 'W/m²';
        $uDIR  = $units['direct_radiation'] ?? 'W/m²';
        $uDNI  = $units['direct_normal_irradiance'] ?? 'W/m²';
        $uDHI  = $units['diffuse_radiation'] ?? 'W/m²';

        // Skalierung: W/m² → Wh/m² (für 15-min Intervall)
        $interval_h = 0.25;
        $scaleGHI = ($uGHI === 'W/m²') ? $interval_h : 1.0;
        $scaleDIR = ($uDIR === 'W/m²') ? $interval_h : 1.0;
        $scaleDNI = ($uDNI === 'W/m²') ? $interval_h : 1.0;
        $scaleDHI = ($uDHI === 'W/m²') ? $interval_h : 1.0;

        // --------------------------------------------------------------------
        // 2. 15-MIN INTERPOLATION – parallel W/m² (Temperatur) & Wh/m² (Energie)
        // --------------------------------------------------------------------
        $times   = [];
        $ghi_W   = []; $dirHor_W = []; $dniN_W = []; $dhi_W = []; $t2m = [];
        $ghi_Wh  = []; $dniN_Wh  = []; $dhi_Wh = [];

        for ($i = 0; $i < $nh; $i++) {
            $t0 = strtotime($t_h[$i]);
            $t1 = ($i < $nh - 1) ? strtotime($t_h[$i+1]) : ($t0 + 3600);

            for ($k = 0; $k < 4; $k++) {
                $tk = $t0 + $k * 900;
                if ($tk > $t1) $tk = $t1;
                $times[] = date('c', $tk);

                // Leistung W/m² (keine Skalierung)
                $ghi_W[]    = $ghi_h[$i]     ?? 0.0;
                $dirHor_W[] = $dir_hor_h[$i] ?? 0.0;
                $dniN_W[]   = $dni_n_h[$i]   ?? null;   // kann fehlen/null sein
                $dhi_W[]    = $dhi_h[$i]     ?? 0.0;

                // Energie Wh/m² (für das 15-min-Intervall)
                $ghi_Wh[]   = ($ghi_h[$i]    ?? 0.0) * $scaleGHI;
                $dniN_Wh[]  = ($dni_n_h[$i]  ?? 0.0) * $scaleDNI;
                $dhi_Wh[]   = ($dhi_h[$i]    ?? 0.0) * $scaleDHI;

                // Temperatur linear interpolieren
                if ($i < $nh - 1) {
                    $alpha = ($tk - $t0) / max(1, ($t1 - $t0));
                    $t2m[] = $t2m_h[$i] + $alpha * ($t2m_h[$i+1] - $t2m_h[$i]);
                } else {
                    $t2m[] = $t2m_h[$i];
                }
            }
        }

        // Schutz
        $n = min(count($times), count($ghi_W), count($dirHor_W), count($dhi_W), count($t2m));
        if ($n === 0) {
            return [
                'total_power_now_w' => 0,
                'daily' => [],
                'strings' => [],
                'json_total' => []
            ];
        }

        // --------------------------------------------------------------------
        // 3. SONNENPOSITION
        // --------------------------------------------------------------------
        $lat = deg2rad((float)$this->ReadPropertyFloat('Latitude'));
        $lon = deg2rad((float)$this->ReadPropertyFloat('Longitude'));
        $solar = [];
        for ($i = 0; $i < $n; $i++) {
            $solar[$i] = $this->solarPosApprox(strtotime($times[$i]), $lat, $lon);
        }

        // Jetzt-Index
        $now = time();
        $nowIdx = 0;
        $best = PHP_INT_MAX;
        for ($i = 0; $i < $n; $i++) {
            $d = abs(strtotime($times[$i]) - $now);
            if ($d < $best) { $best = $d; $nowIdx = $i; }
        }

        // Tagesreferenz
        $refDayTs = strtotime(substr($times[0], 0, 10));

        // --------------------------------------------------------------------
        // 4. STRINGS
        // --------------------------------------------------------------------
        $arrays = $this->getArrays();
        $albedo = (float)$this->ReadPropertyFloat('Albedo');

        $total_now = 0.0;
        $json_total_map = [];
        $stringsOut = [];
        $sumToday = 0.0;
        $sumTomorrow = 0.0;
        $sumDayAfter = 0.0;

        foreach ($arrays as $idx => $arr) {
            // Azimut-Korrektur: Modul (0=S,+90=W,-90=E) → Sonnen-Frame (0=S,+90=E)
            $az_deg = (float)($arr['Azimuth'] ?? 0.0);
            $aziM = deg2rad(-$az_deg);

            // Modulparameter
            $tilt = deg2rad((float)($arr['Tilt'] ?? 30));
            $kWp  = (float)($arr['kWp'] ?? 1.0);
            $loss = (float)($arr['LossFactor'] ?? 0.96);
            $gamma= (float)($arr['Gamma'] ?? -0.004);
            $NOCT = (float)($arr['NOCT'] ?? 45);
            $inv  = (float)($arr['InverterLimit_kW'] ?? 0.0);
            $mask = $this->parseHorizonMask($arr['HorizonMask'] ?? []);
            $diffOb = isset($arr['DiffuseObstruction']) ? (float)$arr['DiffuseObstruction'] : 1.0;

            $series = [];
            $now_w = 0.0;
            $daily = [];

            // --- Intervallweise Berechnung ---
            for ($i = 0; $i < $n; $i++) {
                $zen = $solar[$i]['zenith'];
                $azs = $solar[$i]['azimuth'];

                // Einfallswinkel
                $cosT = $this->cosIncidence($tilt, $aziM, $zen, $azs);
                if ($cosT < 0) $cosT = 0.0;

                // DNI bestimmen: echte DNI bevorzugen; fehlt sie, aus horizontaler Direktstrahlung ableiten
                $cz = max(0.01, cos($zen)); // clamp gegen 0
                $dniN_now_W  = $dniN_W[$i];
                if ($dniN_now_W === null || $dniN_now_W === 0.0) {
                    $dniN_now_W = $dirHor_W[$i] / $cz; // DBH -> DNI
                }
                $dniN_now_Wh = ($dniN_Wh[$i] > 0) ? $dniN_Wh[$i] : ($dirHor_W[$i] * $scaleDIR / $cz);

                // Diffus
                $dhi_eff_W  = $dhi_W[$i];
                $dhi_eff_Wh = $dhi_Wh[$i];
                $dni_eff_W  = $dniN_now_W;
                $dni_eff_Wh = $dniN_now_Wh;

                // Horizon-Maske → Diffus-Abschattung
                if (count($mask) >= 2) {
                    $elSun = 90 - rad2deg($zen);
                    $hEl = $this->horizonElevation($mask, -$azs);
                    if ($elSun < $hEl) {
                        $dni_eff_W  = 0.0;  $dni_eff_Wh = 0.0;
                        $dhi_eff_W  = $dhi_W[$i] * $diffOb;
                        $dhi_eff_Wh = $dhi_Wh[$i] * $diffOb;
                    }
                }

                // --- Diagnose ---
                if ($i === $nowIdx) {
                    $this->SendDebug('DUMP',
                        sprintf(
                            't=%s | cosZ=%.3f | DNI(W)=%.1f | DIR_HOR(W)=%.1f | cosT=%.3f | POA_W=%.1f',
                            $times[$i],
                            cos($zen),
                            $dniN_now_W,
                            $dirHor_W[$i],
                            $cosT,
                            isset($poa_W) ? $poa_W : -1
                        ),
                        0
                    );
                }

                // POA-Leistung (W/m²) und -Energie (Wh/m²)
                $poa_W  = ($dni_eff_W  * $cosT)
                        + ($dhi_eff_W  * (1 + cos($tilt)) / 2)
                        + ($ghi_W[$i]  * $albedo * (1 - cos($tilt)) / 2);
                if ($poa_W < 0) $poa_W = 0.0;

                $poa_Wh = ($dni_eff_Wh * $cosT)
                        + ($dhi_eff_Wh * (1 + cos($tilt)) / 2)
                        + ($ghi_Wh[$i] * $albedo * (1 - cos($tilt)) / 2);
                if ($poa_Wh < 0) $poa_Wh = 0.0;

                // Modultemperatur aus W/m² (NOCT-Formel erwartet Leistung)
                $tcell = $t2m[$i] + ($NOCT - 20)/800.0 * $poa_W;

                // DC Energie kWh
                $e = $kWp * ($poa_Wh / 1000.0) * $loss * (1 + $gamma * ($tcell - 25));
                if ($e < 0) $e = 0.0;

                // Leistung (kW)
                $p_kW = $e / $interval_h;

                // Clipping
                if ($inv > 0 && $p_kW > $inv) {
                    $p_kW = $inv;
                    $e    = $p_kW * $interval_h;
                }

                // "Jetzt"
                if ($i === $nowIdx) {
                    $now_w += $p_kW * 1000.0;
                }

                // Tag
                $dayTs = strtotime(substr($times[$i], 0, 10));
                $off = (int)(($dayTs - $refDayTs)/86400);
                if (!isset($daily[$off])) $daily[$off] = 0.0;
                $daily[$off] += $e;

                // Serie
                $series[] = [
                    't'     => $times[$i],
                    'p_w'   => (int)round($p_kW * 1000.0),
                    'e_kwh' => $e
                ];

                // Gesamt
                $json_total_map[$times[$i]] = ($json_total_map[$times[$i]] ?? 0) + (int)round($p_kW*1000.0);
            }

            $ident = $this->arrayIdent((string)($arr['Name'] ?? ('Array_'.$idx)), $idx);
            $stringsOut[$ident] = [
                'now_w'     => $now_w,
                'today_kwh' => $daily[0] ?? 0.0,
                'json'      => $series,
                'horizon'   => $mask
            ];

            $total_now   += $now_w;
            $sumToday    += $daily[0] ?? 0.0;
            $sumTomorrow += $daily[1] ?? 0.0;
            $sumDayAfter += $daily[2] ?? 0.0;
        }

        // --------------------------------------------------------------------
        // GESAMTZEITREIHE SORTIEREN
        // --------------------------------------------------------------------
        ksort($json_total_map);
        $json_total = [];
        foreach ($json_total_map as $t => $p) {
            $json_total[] = ['t' => $t, 'p_w' => $p];
        }

        return [
            'total_power_now_w' => $total_now,
            'daily' => [
                '0' => $sumToday,
                '1' => $sumTomorrow,
                '2' => $sumDayAfter
            ],
            'strings' => $stringsOut,
            'json_total' => $json_total
        ];
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

    private function dailyBucketsFromSeries(array $pts): array {
        $b = [];
        if (!$pts) return $b;
        $refDayTs = strtotime(substr($pts[0]['t'], 0, 10));
        foreach ($pts as $p) {
            $dayStr = substr($p['t'], 0, 10);
            $dayTs = strtotime($dayStr);
            $off = (int)round(($dayTs - $refDayTs) / 86400);
            if (!isset($b[$off])) $b[$off] = 0.0;
            // Bevorzugt Intervall-Energie verwenden (falls vorhanden)
            if (isset($p['e_kwh'])) {
                $b[$off] += max(0.0, (float)$p['e_kwh']);
            } else {
                // Fallback: p_w (W) in kWh umrechnen, angenommen hourly
                $b[$off] += max(0.0, (float)$p['p_w']) / 1000.0;
            }
        }
        return $b;
    }

    private function mergeTotalSeries(array $total, array $series): array
    {
        $map = [];
        foreach ($total as $row) {
            $map[$row['t']] = ($map[$row['t']] ?? 0) + (int)$row['p_w'];
        }
        foreach ($series as $row) {
            $map[$row['t']] = ($map[$row['t']] ?? 0) + (int)$row['p_w'];
        }
        $res = [];
        foreach ($map as $t => $p) {
            $res[] = ['t' => $t, 'p_w' => (int)$p];
        }
        usort($res, fn($a,$b) => strcmp($a['t'],$b['t']));
        return $res;
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
        usort($mask, fn($a,$b) => ($a['az'] <=> $b['az']));
        $az = rad2deg($azimuthRad);
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

    private function solarPosApprox(int $ts, float $lat, float $lon): array
    {
        // vereinfachte Sonnenstandsberechnung (ausreichend für Forecast-Zwecke)
        
        // NEU (12:00 UTC am 2000-01-01, korrekt):
        $d = ($ts - 946728000) / 86400.0;
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
        $azimuth = atan2($yhor, $xhor) + M_PI; // 0..2π, 0=Süden
        $zenith  = acos($zhor);

        $elev = 90 - rad2deg($zenith);
        $this->SendDebug('SUN', sprintf('t=%s elev=%.1f° cosZ=%.3f', $times[$i], $elev, cos($zenith)), 0);

        return ['zenith' => $zenith, 'azimuth' => $azimuth];
    }
}
?>
