<?php
declare(strict_types=1);

/**
 * OpenMeteoPV – PV-Prognose aus Open-Meteo (Forecast + optional Satellit-Nowcast)
 *
 * Konventionen:
 *  - Modul-Azimut (Property 'Azimuth'): 0° = Süd, -90° = Ost, +90° = West
 *  - Horizontmaske 'az':                gleiche Konvention wie Modul-Azimut
 *  - Sonnenazimut intern:               Kompass (0° = Nord, 90° = Ost, 180° = Süd)
 *  - Open-Meteo-Strahlung ist der Mittelwert der VORANGEGANGENEN Stunde
 *    → Sonnenstand wird zur Intervallmitte (t - 30 min) berechnet.
 */
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
        $this->RegisterPropertyString('Timezone', 'auto');

        // Datenquellen / Optionen
        $this->RegisterPropertyBoolean('UseSatellite', true);
        $this->RegisterPropertyInteger('ForecastDays', 3);    // 1..16
        $this->RegisterPropertyInteger('PastDays', 1);        // 0..7 (nur Anzeige im JSON)
        $this->RegisterPropertyInteger('UpdateMinutes', 60);

        // Nowcasting
        $this->RegisterPropertyBoolean('EnableNowcast', true);
        $this->RegisterPropertyFloat('NowcastHours', 3.0);    // Wirkdauer der Satellitenkorrektur

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
            'Azimuth' => -80,
            'LossFactor' => 0.90,
            'Gamma' => -0.004,
            'NOCT' => 45.0,
            'InverterLimit_kW' => 10.0,
            'HorizonMask' => [
                ['az' => -130, 'el' => 32], ['az' => -120, 'el' => 31], ['az' => -110, 'el' => 29],
                ['az' => -100, 'el' => 28], ['az' =>  -90, 'el' => 27], ['az' =>  -80, 'el' => 25],
                ['az' =>  -70, 'el' => 22], ['az' =>  -60, 'el' => 20], ['az' =>  -40, 'el' => 14],
                ['az' =>  -20, 'el' => 10], ['az' =>    0, 'el' =>  8], ['az' =>   60, 'el' => 28],
                ['az' =>   90, 'el' => 32], ['az' =>  120, 'el' => 36]
            ],
            'DiffuseObstruction' => 1.0
        ], [
            'Name' => 'West',
            'kWp' => 2.7,
            'Tilt' => 10,
            'Azimuth' => +100,
            'LossFactor' => 0.92,
            'Gamma' => -0.004,
            'NOCT' => 45.0,
            'InverterLimit_kW' => 10.0,
            'HorizonMask' => [
                ['az' => -60, 'el' => 8], ['az' => 0, 'el' => 8], ['az' => 60, 'el' => 10]
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

        $this->RegisterTimer('OMPV_Update', 0, 'IPS_RequestAction($_IPS["TARGET"], "Update", 0);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->RegisterMessage(0, IPS_KERNELSTARTED);

        $pos = 30;
        foreach ($this->getArrays() as $i => $a) {
            $name  = (string)($a['Name'] ?? ('Array_' . $i));
            $ident = $this->arrayIdent($name, (int)$i);
            $this->RegisterVariableInteger($ident . '_Power_W', "Leistung {$name} [W]", '~Watt', $pos++);
            $this->RegisterVariableFloat($ident . '_Today_kWh', "Energie heute {$name} [kWh]", '', $pos++);
            $this->RegisterVariableString($ident . '_ForecastJSON', "Forecast JSON {$name}", '', $pos++);
            $this->RegisterVariableString($ident . '_HorizonJSON', "Horizon JSON {$name}", '', $pos++);
            $this->RegisterVariableString($ident . '_DiagJSON', "Diagnose JSON {$name}", '', $pos++);
        }

        $minutes = max(10, $this->ReadPropertyInteger('UpdateMinutes'));
        $this->SetTimerInterval('OMPV_Update', $minutes * 60 * 1000);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->Update();
        }
    }

    public function GetConfigurationForm()
    {
        return json_encode([
            'elements' => [
                ['type' => 'NumberSpinner', 'name' => 'Latitude',  'caption' => 'Breite (°)', 'digits' => 4],
                ['type' => 'NumberSpinner', 'name' => 'Longitude', 'caption' => 'Länge (°)',  'digits' => 4],
                ['type' => 'Select', 'name' => 'Timezone', 'caption' => 'Zeitzone', 'options' => [
                    ['caption' => 'Auto', 'value' => 'auto'],
                    ['caption' => date_default_timezone_get(), 'value' => date_default_timezone_get()]
                ]],
                ['type' => 'CheckBox', 'name' => 'UseSatellite', 'caption' => 'Satellite Radiation API (EU) für Nowcast'],
                ['type' => 'NumberSpinner', 'name' => 'ForecastDays', 'caption' => 'Forecast-Tage (1..16)', 'minimum' => 1, 'maximum' => 16],
                ['type' => 'NumberSpinner', 'name' => 'PastDays', 'caption' => 'Vergangenheits-Tage im JSON (0..7)', 'minimum' => 0, 'maximum' => 7],
                ['type' => 'NumberSpinner', 'name' => 'UpdateMinutes', 'caption' => 'Update-Intervall (min)', 'minimum' => 10],

                ['type' => 'Label', 'caption' => 'Nowcasting'],
                ['type' => 'CheckBox', 'name' => 'EnableNowcast', 'caption' => 'Nowcasting aktiv'],
                ['type' => 'NumberSpinner', 'name' => 'NowcastHours', 'caption' => 'Wirkdauer (0.5..6.0 h)', 'digits' => 1, 'minimum' => 0.5, 'maximum' => 6],

                ['type' => 'NumberSpinner', 'name' => 'Albedo', 'caption' => 'Albedo (0..1)', 'digits' => 2, 'minimum' => 0, 'maximum' => 1],

                ['type' => 'Label', 'caption' => 'Diagnose (heutige Stunden im Fenster → DiagJSON):'],
                ['type' => 'CheckBox', 'name' => 'EnableDiagnostics', 'caption' => 'Diagnose aktiv'],
                ['type' => 'NumberSpinner', 'name' => 'DiagStartHour', 'caption' => 'Startstunde (0..23)'],
                ['type' => 'NumberSpinner', 'name' => 'DiagEndHour', 'caption' => 'Endstunde (0..23)'],

                ['type' => 'Label', 'caption' => 'Strings/Ausrichtungen (JSON, eine Zeile). Azimuth/Maske: 0=Süd, -90=Ost, +90=West'],
                ['type' => 'ValidationTextBox', 'name' => 'Arrays', 'caption' => 'JSON'],
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
            return;
        }
        throw new Exception("Invalid Ident: $Ident");
    }

    /* ============================================================
     *  UPDATE
     * ============================================================ */
    public function Update()
    {
        try {
            $fc = $this->fetchForecast();
            if ($fc === null) {
                $this->SendDebug('Update', 'Forecast leer/ungültig — Abbruch', 0);
                return;
            }

            if ($this->ReadPropertyBoolean('UseSatellite') && $this->ReadPropertyBoolean('EnableNowcast')) {
                $sat = $this->fetchSatellite();
                if ($sat !== null) {
                    $fc = $this->applyNowcast($fc, $sat);
                }
            }

            $r = $this->computePV($fc);

            $this->SetValue('TotalPower_W', (int)round($r['total_power_now_w']));
            $this->SetValue('Today_kWh',    round($r['daily'][0], 2));
            $this->SetValue('Tomorrow_kWh', round($r['daily'][1], 2));
            $this->SetValue('DayAfter_kWh', round($r['daily'][2], 2));
            $this->SetValue('ForecastJSON', json_encode($r['json_total'], JSON_UNESCAPED_SLASHES));

            foreach ($r['strings'] as $ident => $d) {
                $this->SetValue($ident . '_Power_W', (int)round($d['now_w']));
                $this->SetValue($ident . '_Today_kWh', round($d['today_kwh'], 2));
                $this->SetValue($ident . '_ForecastJSON', json_encode($d['json'], JSON_UNESCAPED_SLASHES));
                $this->SetValue($ident . '_HorizonJSON', json_encode($d['horizon'], JSON_UNESCAPED_SLASHES));
                $this->SetValue($ident . '_DiagJSON', json_encode($d['diag'], JSON_UNESCAPED_SLASHES));
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Update ERROR', $e->getMessage() . ' @' . $e->getLine(), 0);
        }
    }

    /* ============================================================
     *  FETCH
     * ============================================================ */

    /** Liefert ['ts'=>[Intervallende, unix], 'ghi','dni','dhi','temp','cloud'] oder null */
    private function fetchForecast(): ?array
    {
        $tzProp = $this->ReadPropertyString('Timezone');
        $tz     = ($tzProp === 'auto') ? 'auto' : urlencode($tzProp);
        $fd     = max(1, min(16, $this->ReadPropertyInteger('ForecastDays')));
        $pd     = max(0, min(7,  $this->ReadPropertyInteger('PastDays')));

        $url = sprintf(
            'https://api.open-meteo.com/v1/forecast?latitude=%F&longitude=%F'
            . '&hourly=shortwave_radiation,direct_normal_irradiance,diffuse_radiation,temperature_2m,cloud_cover'
            . '&timezone=%s&forecast_days=%d&past_days=%d&timeformat=unixtime',
            $this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude'), $tz, $fd, $pd
        );
        $this->SendDebug('URL [forecast]', $url, 0);

        $j = $this->fetchUrlJson($url);
        $h = $j['hourly'] ?? null;
        if (!is_array($h) || empty($h['time'])) {
            return null;
        }

        $n = count($h['time']);
        $out = ['ts' => [], 'ghi' => [], 'dni' => [], 'dhi' => [], 'temp' => [], 'cloud' => []];
        for ($i = 0; $i < $n; $i++) {
            $out['ts'][]    = (int)$h['time'][$i];
            $out['ghi'][]   = (float)($h['shortwave_radiation'][$i] ?? 0);
            $out['dni'][]   = (float)($h['direct_normal_irradiance'][$i] ?? 0);
            $out['dhi'][]   = (float)($h['diffuse_radiation'][$i] ?? 0);
            $out['temp'][]  = isset($h['temperature_2m'][$i]) ? (float)$h['temperature_2m'][$i] : 15.0;
            $out['cloud'][] = isset($h['cloud_cover'][$i]) ? (float)$h['cloud_cover'][$i] : null;
        }
        $this->SendDebug('Forecast', "Punkte=$n, erster=" . date('c', $out['ts'][0]), 0);
        return $out;
    }

    /** Satellit (native Auflösung): Liste [ts(Intervallende), ghi] aufsteigend, nur gültige Werte */
    private function fetchSatellite(): ?array
    {
        $url = sprintf(
            'https://satellite-api.open-meteo.com/v1/archive?latitude=%F&longitude=%F'
            . '&hourly=shortwave_radiation&models=satellite_radiation_seamless'
            . '&past_days=1&temporal_resolution=native&timeformat=unixtime',
            $this->ReadPropertyFloat('Latitude'), $this->ReadPropertyFloat('Longitude')
        );
        $this->SendDebug('URL [satellite]', $url, 0);

        $j = $this->fetchUrlJson($url);
        $h = $j['hourly'] ?? null;
        if (!is_array($h) || empty($h['time']) || empty($h['shortwave_radiation'])) {
            $this->SendDebug('Satellite', 'leer/ungültig', 0);
            return null;
        }

        $pts = [];
        $now = time();
        foreach ($h['time'] as $i => $t) {
            $g = $h['shortwave_radiation'][$i] ?? null;
            if ($g === null || !is_numeric($g) || (int)$t > $now) continue;
            $pts[] = [(int)$t, max(0.0, min(1500.0, (float)$g))];
        }
        if (count($pts) < 2) {
            return null;
        }
        $last = end($pts);
        $this->SendDebug('Satellite', 'Punkte=' . count($pts) . ', letzter=' . date('c', $last[0]) . ' ghi=' . $last[1], 0);
        return $pts;
    }

    private function fetchUrlJson(string $url): ?array
    {
        if (function_exists('Sys_GetURLContent')) {
            $body = @Sys_GetURLContent($url);
        } else {
            $body = @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 20]]));
        }
        if ($body === false || $body === '') {
            return null;
        }
        $j = json_decode($body, true);
        if (!is_array($j)) {
            return null;
        }
        if (!empty($j['error'])) {
            $this->SendDebug('API-Fehler', (string)($j['reason'] ?? 'unbekannt'), 0);
            return null;
        }
        return $j;
    }

    /* ============================================================
     *  NOWCAST: Verhältnis Satellit/Forecast am letzten Satellitenpunkt,
     *  exponentiell abklingend auf GHI, DNI und DHI angewendet.
     * ============================================================ */
    private function applyNowcast(array $fc, array $sat): array
    {
        $n = count($sat);
        [$tsS, $gS] = $sat[$n - 1];
        $step = max(300, $tsS - $sat[$n - 2][0]);
        $midS = $tsS - $step / 2;                                  // Mitte des Satellitenintervalls

        if (time() - $tsS > 3 * 3600) {
            $this->SendDebug('Nowcast', 'Satellitendaten älter als 3 h — übersprungen', 0);
            return $fc;
        }

        // Forecast-GHI zur Satellitenzeit (Stundenwert gilt für Intervallmitte ts-1800)
        $gF = null;
        $cnt = count($fc['ts']);
        for ($i = 1; $i < $cnt; $i++) {
            $m0 = $fc['ts'][$i - 1] - 1800;
            $m1 = $fc['ts'][$i] - 1800;
            if ($midS >= $m0 && $midS <= $m1) {
                $f  = ($midS - $m0) / ($m1 - $m0);
                $gF = $fc['ghi'][$i - 1] + $f * ($fc['ghi'][$i] - $fc['ghi'][$i - 1]);
                break;
            }
        }
        if ($gF === null || $gF < 50.0) {
            $this->SendDebug('Nowcast', 'Forecast-GHI zu klein/nicht gefunden — übersprungen', 0);
            return $fc;
        }

        $k   = max(0.2, min(2.0, $gS / $gF));
        $H   = max(0.5, min(6.0, $this->ReadPropertyFloat('NowcastHours'))) * 3600.0;
        $tau = $H / 2.0;

        for ($i = 0; $i < $cnt; $i++) {
            $dt = ($fc['ts'][$i] - 1800) - $midS;
            if ($dt <= 0 || $dt > $H) continue;
            $f = 1.0 + exp(-$dt / $tau) * ($k - 1.0);
            $fc['ghi'][$i] *= $f;
            $fc['dni'][$i] *= $f;
            $fc['dhi'][$i] *= $f;
        }
        $this->SendDebug('Nowcast', sprintf('sat=%.0f fc=%.0f k=%.2f', $gS, $gF, $k), 0);
        return $fc;
    }

    /* ============================================================
     *  PV-BERECHNUNG
     * ============================================================ */
    private function computePV(array $fc): array
    {
        $n = count($fc['ts']);

        // Cloud-Sanity-Check (unverändert übernommen)
        for ($i = 0; $i < $n; $i++) {
            $c = $fc['cloud'][$i];
            if ($c !== null && $c >= 90 && $fc['ghi'][$i] > 250) {
                $this->SendDebug('CloudSanityPatch', date('c', $fc['ts'][$i]) . " cloud=$c ghi_alt={$fc['ghi'][$i]}", 0);
                $fc['ghi'][$i] = 120.0;
                $fc['dni'][$i] = 0.0;
                $fc['dhi'][$i] = 120.0;
            }
        }

        $latRad = deg2rad($this->ReadPropertyFloat('Latitude'));
        $lonRad = deg2rad($this->ReadPropertyFloat('Longitude'));
        $albedo = $this->ReadPropertyFloat('Albedo');

        // Sonnenstand zur Intervallmitte
        $solar = [];
        for ($i = 0; $i < $n; $i++) {
            $solar[$i] = $this->solarPosApprox($fc['ts'][$i] - 1800, $latRad, $lonRad);
        }

        // Tagesbezug auf das echte Kalenderdatum (lokal), nicht auf den ersten Datenpunkt
        $t0   = strtotime('today');
        $days = [date('Y-m-d', $t0), date('Y-m-d', strtotime('+1 day', $t0)), date('Y-m-d', strtotime('+2 days', $t0))];

        // Index der laufenden Stunde
        $now = time();
        $nowIdx = -1;
        for ($i = 0; $i < $n; $i++) {
            if ($fc['ts'][$i] >= $now) { $nowIdx = $i; break; }
        }

        $diagOn = $this->ReadPropertyBoolean('EnableDiagnostics');
        $dS = $this->ReadPropertyInteger('DiagStartHour');
        $dE = $this->ReadPropertyInteger('DiagEndHour');

        $stringsOut = [];
        $totalMap   = [];
        $dailyTotal = [0.0, 0.0, 0.0];

        foreach ($this->getArrays() as $idx => $arr) {
            $name  = (string)($arr['Name'] ?? ('Array_' . $idx));
            $ident = $this->arrayIdent($name, (int)$idx);

            $tilt   = deg2rad((float)($arr['Tilt'] ?? 30));
            $azM    = deg2rad(180.0 + (float)($arr['Azimuth'] ?? 0));   // Süd-Bezug → Kompass
            $kWp    = (float)($arr['kWp'] ?? 1.0);
            $loss   = (float)($arr['LossFactor'] ?? 0.96);
            $gamma  = (float)($arr['Gamma'] ?? -0.004);
            $NOCT   = (float)($arr['NOCT'] ?? 45.0);
            $inv    = (float)($arr['InverterLimit_kW'] ?? 0.0);
            $mask   = $this->parseHorizonMask($arr['HorizonMask'] ?? []);
            $diffOb = (float)($arr['DiffuseObstruction'] ?? 1.0);

            $series = [];
            $daily  = [];
            $diag   = [];
            $now_w  = 0.0;

            for ($i = 0; $i < $n; $i++) {
                $zen   = $solar[$i]['zenith'];
                $azs   = $solar[$i]['azimuth'];                  // Kompass
                $elSun = 90.0 - rad2deg($zen);

                $cosT = max(0.0, $this->cosIncidence($tilt, $azM, $zen, $azs));

                $azSunSouth = rad2deg($azs) - 180.0;              // Kompass → Süd-Bezug wie Maske
                $hEl     = $this->horizonElevation($mask, deg2rad($azSunSouth));
                $shaded  = ($elSun <= 0.0 || $elSun < $hEl);

                $dni_eff = $shaded ? 0.0 : $fc['dni'][$i];
                $dhi_eff = ($elSun < $hEl) ? $fc['dhi'][$i] * $diffOb : $fc['dhi'][$i];

                $poa = $dni_eff * $cosT
                     + $dhi_eff * (1 + cos($tilt)) / 2
                     + $fc['ghi'][$i] * $albedo * (1 - cos($tilt)) / 2;
                $poa = max(0.0, $poa);

                $tcell = $fc['temp'][$i] + ($NOCT - 20.0) / 800.0 * $poa;
                $pW = $kWp * $poa * $loss * (1 + $gamma * ($tcell - 25.0));     // W (kWp * W/m² / 1000 * 1000)
                $pW = max(0.0, $pW);
                if ($inv > 0 && $pW > $inv * 1000.0) {
                    $pW = $inv * 1000.0;
                }
                $e_kwh = $pW / 1000.0;                            // Stundenintervall

                if ($i === $nowIdx) $now_w = $pW;

                $tsIso = date('c', $fc['ts'][$i]);
                $series[] = ['t' => $tsIso, 'p_w' => (int)round($pW), 'e_kwh' => round($e_kwh, 4)];

                $day = date('Y-m-d', $fc['ts'][$i] - 1800);
                $daily[$day] = ($daily[$day] ?? 0.0) + $e_kwh;

                $totalMap[$fc['ts'][$i]] = ($totalMap[$fc['ts'][$i]] ?? 0) + $pW;

                if ($diagOn && $day === $days[0]) {
                    $hr = (int)date('G', $fc['ts'][$i] - 1800);
                    if ($hr >= $dS && $hr <= $dE) {
                        $diag[] = [
                            't' => $tsIso, 'el' => round($elSun, 1), 'az_sued' => round($azSunSouth, 1),
                            'horizont' => round($hEl, 1), 'cosT' => round($cosT, 3),
                            'dni' => $fc['dni'][$i], 'dhi' => $fc['dhi'][$i], 'poa' => round($poa, 1), 'p_w' => (int)round($pW)
                        ];
                    }
                }
            }

            $stringsOut[$ident] = [
                'now_w'     => $now_w,
                'today_kwh' => $daily[$days[0]] ?? 0.0,
                'json'      => $series,
                'horizon'   => $mask,
                'diag'      => $diag
            ];
            foreach ($days as $k => $d) {
                $dailyTotal[$k] += $daily[$d] ?? 0.0;
            }
        }

        ksort($totalMap);
        $jsonTotal = [];
        foreach ($totalMap as $ts => $p) {
            $jsonTotal[] = ['t' => date('c', $ts), 'p_w' => (int)round($p)];
        }

        $total_now_w = 0.0;
        foreach ($stringsOut as $s) $total_now_w += $s['now_w'];

        return [
            'total_power_now_w' => $total_now_w,
            'daily'             => $dailyTotal,
            'strings'           => $stringsOut,
            'json_total'        => $jsonTotal
        ];
    }

    /* ============================================================
     *  HELPERS
     * ============================================================ */
    private function getArrays(): array
    {
        $a = json_decode($this->ReadPropertyString('Arrays'), true);
        return is_array($a) ? $a : [];
    }

    private function arrayIdent(string $name, int $idx): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9_]/', '_', $name)) . '_' . $idx;
    }

    private function parseHorizonMask($maskField): array
    {
        if (is_string($maskField)) {
            $m = json_decode($maskField, true);
            return is_array($m) ? $m : [];
        }
        return is_array($maskField) ? $maskField : [];
    }

    /** Horizonthöhe [°] bei Azimut (Süd-Bezug, rad) – zyklisch interpoliert, leicht geglättet */
    private function horizonElevation(array $mask, float $azimuthRad): float
    {
        $m = [];
        foreach ($mask as $pt) {
            if (!isset($pt['az'], $pt['el'])) continue;
            $m[] = ['az' => fmod(((float)$pt['az'] + 540.0), 360.0) - 180.0, 'el' => (float)$pt['el']];
        }
        if (count($m) < 2) return 0.0;

        usort($m, fn($a, $b) => $a['az'] <=> $b['az']);

        $N = count($m);
        $smooth = [];
        for ($i = 0; $i < $N; $i++) {
            $smooth[] = [
                'az' => $m[$i]['az'],
                'el' => $m[($i - 1 + $N) % $N]['el'] * 0.2 + $m[$i]['el'] * 0.6 + $m[($i + 1) % $N]['el'] * 0.2
            ];
        }

        $az = fmod((rad2deg($azimuthRad) + 540.0), 360.0) - 180.0;

        $prev = $smooth[$N - 1];
        foreach ($smooth as $pt) {
            if ($az <= $pt['az']) {
                if ($pt === $smooth[0]) break;              // vor dem ersten Punkt → Wrap-Around
                if ($pt['az'] == $prev['az']) return $pt['el'];
                $t = ($az - $prev['az']) / ($pt['az'] - $prev['az']);
                return $prev['el'] + $t * ($pt['el'] - $prev['el']);
            }
            $prev = $pt;
        }

        // Wrap-Around zwischen letztem und erstem Punkt
        $last  = $smooth[$N - 1];
        $first = $smooth[0];
        $x1  = $first['az'] + 360.0;
        $azw = ($az < $first['az']) ? $az + 360.0 : $az;
        $t   = ($azw - $last['az']) / ($x1 - $last['az']);
        return $last['el'] + $t * ($first['el'] - $last['el']);
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
}
