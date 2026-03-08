<?php
declare(strict_types=1);

class OpenMeteoPV extends IPSModule
{
    public function Create() {
        parent::Create();

        // Standort & Basis
        $this->RegisterPropertyFloat('Latitude', 52.8343);
        $this->RegisterPropertyFloat('Longitude', 8.1555);
        $this->RegisterPropertyString('Timezone', 'auto');

        // Open-Meteo Optionen
        $this->RegisterPropertyBoolean('UseSatellite', true);  // Satellite Radiation API (EU 10-min)
        $this->RegisterPropertyBoolean('UseGTI', false);       // GTI direkt vom API je String (mehr Calls)
        $this->RegisterPropertyInteger('ForecastDays', 3);     // 1..16
        $this->RegisterPropertyInteger('PastDays', 1);         // 0..7
        $this->RegisterPropertyInteger('ResolutionMinutes', 60); // 60 / 15 / 10
        $this->RegisterPropertyInteger('UpdateMinutes', 60);   // Abrufintervall
        $this->RegisterPropertyFloat('Albedo', 0.20);

        // Strings/Ausrichtungen (Default)
        $defaultArrays = json_encode([
            [
                'Name' => 'Sued',
                'kWp' => 7.0,
                'Tilt' => 30.0,
                'Azimuth' => 0.0,           // 0° = Süden, -90°=Ost, +90°=West, ±180°=N
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
            ]
        ]);
        $this->RegisterPropertyString('Arrays', $defaultArrays);

        // Variablen
        $this->RegisterVariableInteger('TotalPower_W', 'Leistung (gesamt) [W]', '~Watt', 10);
        $this->RegisterVariableFloat('Today_kWh', 'Energie heute [kWh]', '', 20);
        $this->RegisterVariableFloat('Tomorrow_kWh', 'Energie morgen [kWh]', '', 21);
        $this->RegisterVariableFloat('DayAfter_kWh', 'Energie übermorgen [kWh]', '', 22);
        $this->RegisterVariableString('ForecastJSON', 'Forecast JSON (gesamt)', '', 90);

        // Timer für zyklische Updates
        $this->RegisterTimer('MeteoPV_Update', 0, 'MeteoPV_Update($_IPS["TARGET"]);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Per-String Variablen anlegen
        $arrays = $this->getArrays();
        $pos = 30;
        foreach ($arrays as $idx => $arr) {
            $ident = $this->arrayIdent($arr['Name'] ?? ('Array_'.$idx), $idx);
            $this->RegisterVariableInteger($ident.'_Power_W', "Leistung {$arr['Name']} [W]", '~Watt', $pos++);
            $this->RegisterVariableFloat($ident.'_Today_kWh', "Energie heute {$arr['Name']} [kWh]", '', $pos++);
            $this->RegisterVariableString($ident.'_ForecastJSON', "Forecast JSON {$arr['Name']}", '', $pos++);
            $this->RegisterVariableString($ident.'_HorizonJSON', "Horizon JSON {$arr['Name']}", '', $pos++);
        }

        // Timer setzen
        $minutes = max(10, (int)$this->ReadPropertyInteger('UpdateMinutes'));
        $this->SetTimerInterval('MeteoPV_Update', $minutes * 60 * 1000);

        // initiales Update
        $this->Update();
    }

    public function GetConfigurationForm() {
        $tz = $this->ReadPropertyString('Timezone');
        return json_encode([
            'elements' => [
                ['type' => 'NumberSpinner', 'name' => 'Latitude', 'caption' => 'Breite (°)'],
                ['type' => 'NumberSpinner', 'name' => 'Longitude', 'caption' => 'Länge (°)'],
                ['type' => 'Select', 'name' => 'Timezone', 'caption' => 'Zeitzone', 'options' => [
                    ['caption' => 'Auto', 'value' => 'auto'],
                    ['caption' => date_default_timezone_get(), 'value' => date_default_timezone_get()]
                ]],
                ['type' => 'CheckBox', 'name' => 'UseSatellite', 'caption' => 'Satellite Radiation API (EU 10-min) nutzen'],
                ['type' => 'CheckBox', 'name' => 'UseGTI', 'caption' => 'GTI direkt von Open-Meteo (je String)'],
                ['type' => 'NumberSpinner', 'name' => 'ResolutionMinutes', 'caption' => 'Auflösung (min; 10/15/60)'],
                ['type' => 'NumberSpinner', 'name' => 'ForecastDays', 'caption' => 'Prognose-Tage (1..16)'],
                ['type' => 'NumberSpinner', 'name' => 'PastDays', 'caption' => 'Vergangenheits-Tage (0..7)'],
                ['type' => 'NumberSpinner', 'name' => 'UpdateMinutes', 'caption' => 'Update-Intervall (min)'],
                ['type' => 'NumberSpinner', 'name' => 'Albedo', 'caption' => 'Albedo (0..1)', 'digits' => 2, 'minimum' => 0, 'maximum' => 1],
                [
                    'type' => 'ListEditor',
                    'name' => 'Arrays',
                    'caption' => 'Strings / Ausrichtungen',
                    'rowCount' => 6,
                    'add' => true,
                    'columns' => [
                        ['caption' => 'Name', 'name' => 'Name', 'width' => '100px', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'kWp', 'name' => 'kWp', 'width' => '70px', 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ['caption' => 'Neigung β (°)', 'name' => 'Tilt', 'width' => '90px', 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ['caption' => 'Azimut γ (°)', 'name' => 'Azimuth', 'width' => '90px', 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ['caption' => 'Loss', 'name' => 'LossFactor', 'width' => '70px', 'edit' => ['type' => 'NumberSpinner', 'digits' => 2]],
                        ['caption' => 'γ (/K)', 'name' => 'Gamma', 'width' => '70px', 'edit' => ['type' => 'NumberSpinner', 'digits' => 4]],
                        ['caption' => 'NOCT (°C)', 'name' => 'NOCT', 'width' => '80px', 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ['caption' => 'WR-Limit (kW)', 'name' => 'InverterLimit_kW', 'width' => '100px', 'edit' => ['type' => 'NumberSpinner', 'digits' => 1]],
                        ['caption' => 'Horizon-Maske (JSON)', 'name' => 'HorizonMask', 'width' => '320px', 'edit' => ['type' => 'ValidationTextBox']],
                        ['caption' => 'Diffuse (0..1)', 'name' => 'DiffuseObstruction', 'width' => '100px', 'edit' => ['type' => 'NumberSpinner', 'digits' => 2]]
                    ]
                ]
            ],
            'actions' => [
                ['type' => 'Button', 'caption' => 'Jetzt aktualisieren', 'onClick' => 'MeteoPV_Update($id);']
            ]
        ]);
    }

    public function Update() {
        try {
            $raw = $this->fetchOpenMeteo();
            if (!$raw) {
                $this->SendDebug('Update', 'Keine Daten empfangen', 0);
                return;
            }
            $result = $this->computePV($raw);

            // Gesamtwerte
            $this->SetValue('TotalPower_W', (int)round($result['total_power_now_w']));
            $this->SetValue('Today_kWh', round($result['daily']['0'] ?? 0.0, 2));
            $this->SetValue('Tomorrow_kWh', round($result['daily']['1'] ?? 0.0, 2));
            $this->SetValue('DayAfter_kWh', round($result['daily']['2'] ?? 0.0, 2));
            $this->SetValue('ForecastJSON', json_encode($result['json_total'], JSON_UNESCAPED_SLASHES));

            // Strings ausgeben
            foreach ($result['strings'] as $ident => $data) {
                $this->SetValue($ident.'_Power_W', (int)round($data['now_w']));
                $this->SetValue($ident.'_Today_kWh', round($data['today_kwh'], 2));
                $this->SetValue($ident.'_ForecastJSON', json_encode($data['json'], JSON_UNESCAPED_SLASHES));
                if (!empty($data['horizon'])) {
                    $this->SetValue($ident.'_HorizonJSON', json_encode($data['horizon'], JSON_UNESCAPED_SLASHES));
                }
            }
        } catch (\Throwable $e) {
            $this->SendDebug('Update ERROR', $e->getMessage(), 0);
        }
    }

    // ----------------- Internals -----------------

    private function getArrays(): array {
        $json = $this->ReadPropertyString('Arrays');
        $arr = json_decode($json, true);
        return is_array($arr) ? $arr : [];
    }

    private function arrayIdent(string $name, int $idx): string {
        $base = preg_replace('/[^A-Za-z0-9_]/', '_', $name);
        return strtoupper($base).'_'.$idx;
    }

    private function fetchOpenMeteo(): ?array {
        $lat  = $this->ReadPropertyFloat('Latitude');
        $lon  = $this->ReadPropertyFloat('Longitude');
        $tz   = $this->ReadPropertyString('Timezone') === 'auto' ? 'auto' : urlencode($this->ReadPropertyString('Timezone'));
        $fd   = max(1, min(16, (int)$this->ReadPropertyInteger('ForecastDays')));
        $pd   = max(0, min(7, (int)$this->ReadPropertyInteger('PastDays')));
        $useSat = $this->ReadPropertyBoolean('UseSatellite');

        $paramsHourly = [
            'shortwave_radiation',           // GHI (Wh/m²/h; averaged over past hour)
            'direct_radiation',              // Wh/m²/h
            'diffuse_radiation',             // DHI (Wh/m²/h)
            'direct_normal_irradiance',      // DNI (Wh/m²/h)
            'temperature_2m',
            'cloud_cover'
        ];

        if ($useSat) {
            $base = 'https://api.open-meteo.com/v1/satellite'; // Satellite Radiation API (EU 10-min)
        } else {
            $base = 'https://api.open-meteo.com/v1/forecast';  // Forecast API (hourly / 15-min CE via DWD)
        }

        $hourly = implode(',', $paramsHourly);
        $url = sprintf('%s?latitude=%F&longitude=%F&hourly=%s&timezone=%s&forecast_days=%d&past_days=%d',
            $base, $lat, $lon, $hourly, $tz, $fd, $pd);

        $this->SendDebug('OpenMeteo URL', $url, 0);
        $ctx = stream_context_create(['http' => ['timeout' => 15]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res === false) return null;
        $json = json_decode($res, true);
        return is_array($json) ? $json : null;
    }

    private function computePV(array $raw): array {
        $arrays = $this->getArrays();
        $albedo = (float)$this->ReadPropertyFloat('Albedo');

        $times = $raw['hourly']['time'] ?? [];
        $ghi   = $raw['hourly']['shortwave_radiation'] ?? [];
        $dni   = $raw['hourly']['direct_normal_irradiance'] ?? [];
        $dhi   = $raw['hourly']['diffuse_radiation'] ?? [];
        $t2m   = $raw['hourly']['temperature_2m'] ?? [];

        $n = min(count($times), count($ghi), count($dni), count($dhi), count($t2m));
        if ($n === 0) return ['total_power_now_w' => 0, 'daily' => [], 'strings' => [], 'json_total' => []];

        $nowIdx = $this->findNowIndex($times);

        // Sonnenpositionen vorberechnen
        $lat = deg2rad($this->ReadPropertyFloat('Latitude'));
        $lon = deg2rad($this->ReadPropertyFloat('Longitude'));
        $solar = [];
        for ($i=0; $i<$n; $i++) {
            $ts = strtotime($times[$i]);
            $solar[$i] = $this->solarPosApprox($ts, $lat, $lon); // ['zenith','azimuth']
        }

        $total_now_w = 0.0;
        $json_total = [];
        $sumToday = 0.0; $sumTomorrow = 0.0; $sumDayAfter = 0.0;
        $strings = [];

        for ($a=0; $a<count($arrays); $a++) {
            $arr  = $arrays[$a];
            $tilt = deg2rad((float)($arr['Tilt'] ?? 30.0));
            $aziM = deg2rad((float)($arr['Azimuth'] ?? 0.0));
            $kWp  = (float)($arr['kWp'] ?? 1.0);
            $loss = (float)($arr['LossFactor'] ?? 0.9);
            $gamma= (float)($arr['Gamma'] ?? -0.0040);
            $NOCT = (float)($arr['NOCT'] ?? 45.0);
            $invLimitKW = (float)($arr['InverterLimit_kW'] ?? 0.0);
            $mask = $this->parseHorizonMask($arr['HorizonMask'] ?? []);
            $diffObs = isset($arr['DiffuseObstruction']) ? max(0.0, min(1.0, (float)$arr['DiffuseObstruction'])) : 1.0;

            $series = [];
            for ($i=0; $i<$n; $i++) {
                $zenith = $solar[$i]['zenith'];
                $azSun  = $solar[$i]['azimuth'];

                $cosTheta = $this->cosIncidence($tilt, $aziM, $zenith, $azSun);
                $cosTheta = max(0.0, $cosTheta);

                // Horizon/Teilverschattung
                $azSun_deg = rad2deg($azSun);
                $elSun_deg = 90.0 - rad2deg($zenith);

                if (count($mask) >= 2) {
                    $hEl = $this->horizonElevation($mask, $azSun);
                    if ($elSun_deg < $hEl) {
                        $dni_eff = 0.0;
                        $dhi_eff = $dhi[$i] * $diffObs;
                    } else {
                        $dni_eff = $dni[$i];
                        $dhi_eff = $dhi[$i];
                    }
                } else {
                    $dni_eff = $dni[$i];
                    $dhi_eff = $dhi[$i];
                }

                // POA (Wh/m²)
                $poa = ($dni_eff * $cosTheta)
                     + ($dhi_eff * (1 + cos($tilt)) / 2.0)
                     + ($ghi[$i] * $albedo * (1 - cos($tilt)) / 2.0);

                // Modultemperatur (NOCT)
                $tcell = $t2m[$i] + ($NOCT - 20.0)/800.0 * max(0.0, $poa);

                // Effektiver Loss (Systemverluste)
                $lossEff = max(0.0, min(1.0, $loss));

                // DC-Leistung (kW) inkl. Temp
                $pdc_kW = $kWp * ($poa / 1000.0) * $lossEff * (1.0 + $gamma * ($tcell - 25.0));
                $pdc_kW = max(0.0, $pdc_kW);

                // Inverter-Clipping
                if ($invLimitKW > 0.0) {
                    $pdc_kW = min($pdc_kW, $invLimitKW);
                }

                // Energie für 1h
                $e_kWh = $pdc_kW;

                // "Jetzt"
                if ($i === $nowIdx) {
                    $series['now_w'] = ($series['now_w'] ?? 0.0) + $pdc_kW * 1000.0;
                }

                // Zeitreihe
                $series['points'][] = ['t' => $times[$i], 'p_w' => (int)round($pdc_kW * 1000.0)];
            }

            // Tages-Buckets
            $dailyBuckets = $this->dailyBucketsFromSeries($series['points']);
            $sumToday     += $dailyBuckets[0] ?? 0.0;
            $sumTomorrow  += $dailyBuckets[1] ?? 0.0;
            $sumDayAfter  += $dailyBuckets[2] ?? 0.0;

            $ident = $this->arrayIdent($arr['Name'] ?? ('Array_'.$a), $a);
            $strings[$ident] = [
                'now_w'     => $series['now_w'] ?? 0.0,
                'today_kwh' => $dailyBuckets[0] ?? 0.0,
                'json'      => $series['points'],
                'horizon'   => $mask
            ];

            $json_total = $this->mergeTotalSeries($json_total, $series['points']);
            $total_now_w += ($series['now_w'] ?? 0.0);
        }

        return [
            'total_power_now_w' => $total_now_w,
            'daily' => ['0' => $sumToday, '1' => $sumTomorrow, '2' => $sumDayAfter],
            'strings' => $strings,
            'json_total' => $json_total
        ];
    }

    private function findNowIndex(array $times): int {
        $now = time();
        $bestIdx = 0; $bestDiff = PHP_INT_MAX;
        foreach ($times as $i => $iso) {
            $ts = strtotime($iso);
            $d = abs($ts - $now);
            if ($d < $bestDiff) { $bestDiff = $d; $bestIdx = $i; }
        }
        return $bestIdx;
    }

    private function dailyBucketsFromSeries(array $pts): array {
        $b = [];
        if (!$pts) return $b;
        $ref = substr($pts[0]['t'], 0, 10);
        foreach ($pts as $p) {
            $day = substr($p['t'], 0, 10);
            $off = (int)round((strtotime($day) - strtotime($ref)) / 86400);
            if (!isset($b[$off])) $b[$off] = 0.0;
            $b[$off] += max(0.0, $p['p_w']) / 1000.0; // kWh
        }
        return $b;
    }

    private function mergeTotalSeries(array $total, array $series): array {
        $map = [];
        foreach ($total as $row) $map[$row['t']] = ($map[$row['t']] ?? 0) + (int)$row['p_w'];
        foreach ($series as $row) $map[$row['t']] = ($map[$row['t']] ?? 0) + (int)$row['p_w'];
        $res = [];
        foreach ($map as $t => $p) $res[] = ['t' => $t, 'p_w' => (int)$p];
        usort($res, fn($a,$b)=> strcmp($a['t'],$b['t']));
        return $res;
    }

    private function parseHorizonMask($maskField): array {
        if (is_string($maskField)) {
            $m = json_decode($maskField, true);
            return is_array($m) ? $m : [];
        }
        return is_array($maskField) ? $maskField : [];
    }

    private function horizonElevation(array $mask, float $azimuthRad): float {
        if (count($mask) < 2) return 0.0;
        usort($mask, fn($a,$b)=> ($a['az'] <=> $b['az']));
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
        $first = $mask[0];
        $x0 = (float)$prev['az']; $y0 = (float)$prev['el'];
        $x1 = (float)$first['az'] + 360.0; $y1 = (float)$first['el'];
        $azw= ($az < $first['az']) ? $az + 360.0 : $az;
        $t = ($azw - $x0) / ($x1 - $x0);
        return $y0 + $t * ($y1 - $y0);
    }

    private function cosIncidence(float $tilt, float $aziM, float $zenith, float $aziSun): float {
        $nx = sin($tilt) * cos($aziM);
        $ny = sin($tilt) * sin($aziM);
        $nz = cos($tilt);
        $sx = sin($zenith) * cos($aziSun);
        $sy = sin($zenith) * sin($aziSun);
        $sz = cos($zenith);
        return $nx*$sx + $ny*$sy + $nz*$sz;
    }

    private function solarPosApprox(int $ts, float $lat, float $lon): array {
        $d = ($ts - 946684800) / 86400.0;
        $L = deg2rad(fmod(280.46 + 0.9856474 * $d, 360.0));
        $g = deg2rad(fmod(357.528 + 0.9856003 * $d, 360.0));
        $lambda = $L + deg2rad(1.915) * sin($g) + deg2rad(0.020) * sin(2*$g);
        $epsilon = deg2rad(23.439 - 0.0000004 * $d);
        $RA = atan2(cos($epsilon)*sin($lambda), cos($lambda));
        $dec = asin(sin($epsilon)*sin($lambda));
        $GMST = fmod(18.697374558 + 24.06570982441908 * $d, 24.0);
        $LST = deg2rad(($GMST*15.0)) + $lon;
        $HA = $LST - $RA;
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

function MeteoPV_Update(int $InstanceID) {
    $inst = IPS_GetInstance($InstanceID);
    if (!isset($inst['ModuleInfo']['ModuleID']) || $inst['ModuleInfo']['ModuleID'] !== '{2E3D8D62-33C1-4F51-A6B1-34F1C4A6B1E8}') return;
    /** @var OpenMeteoPV $obj */
    $obj = IPS_GetObject($InstanceID);
    // In Modulen wäre normalerweise RequestAction zu nutzen; hier rufen wir Update direkt.
    IPS_RunScriptText("IPS_RequestAction($InstanceID, 'Update', null);");
}
?>
