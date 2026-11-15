<?php
declare(strict_types=1);
/* Offline-Test: geglättete Verteilung der Stundenstrahlung und MOSMIX-IssueTime */

require_once __DIR__ . '/ipsmodule_stub.php';

$fail = 0;
function check(string $n, bool $ok, string $info = ''): void { global $fail; if (!$ok) { $fail++; echo "FAIL: $n $info\n"; } else { echo "ok:   $n $info\n"; } }
function call(object $o, string $method, ...$args) { $m = new ReflectionMethod($o, $method); $m->setAccessible(true); return $m->invoke($o, ...$args); }

/**
 * Physikalisch konsistente Stundenreihe (Zeitstempel = Intervallende): GHI = kt · I0 · e0 · mittl. cos(Zenit),
 * kt je Stunde zufällig 0,15…0,75 (wechselnde Bewölkung). Sonnenstand über das Modul selbst.
 */
function cacheData(): array
{
    static $data = null;
    if ($data !== null) return $data;
    $m = new OpenMeteoPV(); $m->Create();
    $lat = deg2rad($m->props['Latitude']); $lon = deg2rad($m->props['Longitude']);
    mt_srand(42);
    $data = [];
    $start = strtotime('yesterday');
    for ($ts = $start + 3600; $ts <= $start + 6 * 86400; $ts += 3600) {
        $c = 0.0;
        for ($k = 0; $k < 12; $k++) {
            $pos = call($m, 'solarPosApprox', (int)($ts - 3600 + ($k + 0.5) * 300), $lat, $lon);
            $c += max(0.0, cos($pos['zenith'])) / 12;
        }
        $kt = 0.15 + 0.6 * mt_rand() / mt_getrandmax();
        $data[$ts] = [round($kt * 1361 * $c, 1), null, 8.0, null];
    }
    return $data;
}

function run(bool $smooth): OpenMeteoPV
{
    $m = new OpenMeteoPV();
    $m->Create();
    $m->props['SmoothIrradiance'] = $smooth;
    $m->props['EnableNowcast'] = false;
    $m->ApplyChanges();
    $m->WriteAttributeString('ForecastCache', json_encode(['key' => 'x', 'fetched' => time(), 'issued' => 1794744000, 'data' => cacheData()]));
    $m->Recalc();
    return $m;
}

function jumps(array $v): array
{
    $inner = $edge = [];
    for ($i = 0; $i < count($v) - 1; $i++) {
        if ($v[$i] <= 0 || $v[$i + 1] <= 0) continue;
        if (($i + 1) % 4 === 0) $edge[] = abs($v[$i + 1] - $v[$i]); else $inner[] = abs($v[$i + 1] - $v[$i]);
    }
    return [array_sum($inner) / max(1, count($inner)), array_sum($edge) / max(1, count($edge))];
}

/* ---- Verteilung je Teilschritt: Energieerhaltung und Plausibilität ---- */
$m = new OpenMeteoPV(); $m->Create();
$series = call($m, 'cacheToSeries', cacheData());
$ctx = call($m, 'prepareIrradiance', $series);
$maxErrGhi = 0.0; $maxErrDif = 0.0; $neg = 0; $over = 0; $hoursSun = 0;
foreach ($ctx['hours'] as $k => $h) {
    $g = array_sum(array_column($h['sub'], 'ghi')) / $ctx['S'];
    if ($h['ghi'] > 0) { $hoursSun++; $maxErrGhi = max($maxErrGhi, abs($g - $h['ghi'])); }
    foreach ($h['sub'] as $s) {
        if ($s['dni'] < 0 || $s['dif'] < 0 || $s['ghi'] < 0) $neg++;
        if ($s['dif'] > $s['ghi'] + 1e-6 && $s['cosZ'] >= 0.02) $over++;
        // GHI = DNI·cosZ + DIF je Teilschritt
        if ($s['ghi'] > 0) $maxErrDif = max($maxErrDif, abs($s['dni'] * $s['cosZ'] + $s['dif'] - $s['ghi']));
    }
}
check('GHI-Stundenmittel exakt erhalten', $maxErrGhi < 0.01, sprintf('max. Abweichung %.4f W/m² über %d Sonnenstunden', $maxErrGhi, $hoursSun));
check('GHI = DNI·cosZ + DIF je Teilschritt', $maxErrDif < 0.01, sprintf('%.4f', $maxErrDif));
check('keine negativen Werte', $neg === 0, (string)$neg);
check('Diffus ≤ Global', $over === 0, (string)$over);

/* ---- Zeitreihe: Sprünge an Stundengrenzen ---- */
$off = run(false); $on = run(true);
$tsOff = json_decode($off->vars['Forecast_TS'], true)['values'];
$tsOn  = json_decode($on->vars['Forecast_TS'], true)['values'];
[$iOff, $eOff] = jumps($tsOff);
[$iOn,  $eOn]  = jumps($tsOn);
printf("      ohne Glättung: innen %.0f W, Stundengrenze %.0f W (Faktor %.1f)\n", $iOff, $eOff, $eOff / $iOff);
printf("      mit Glättung:  innen %.0f W, Stundengrenze %.0f W (Faktor %.1f)\n", $iOn, $eOn, $eOn / $iOn);
check('Sprünge an Stundengrenzen deutlich kleiner', $eOn < 0.6 * $eOff, sprintf('%.0f → %.0f W', $eOff, $eOn));
check('Stundengrenze nicht auffälliger als innen', $eOn / $iOn < 1.6, sprintf('%.2f', $eOn / $iOn));

foreach (['Today_kWh', 'Tomorrow_kWh', 'DayAfter_kWh'] as $k) {
    $d = abs($on->vars[$k] - $off->vars[$k]) / max(0.01, $off->vars[$k]);
    check("$k ändert sich kaum", $d < 0.03, sprintf('%.2f → %.2f kWh (%.1f %%)', $off->vars[$k], $on->vars[$k], $d * 100));
}
check('issued_ts aus Cache', json_decode($on->vars['Forecast_TS'], true)['issued_ts'] === 1794744000);

/* ---- MOSMIX IssueTime ---- */
$vals = implode(' ', array_fill(0, 30, '500.00'));
$steps = '';
for ($i = 0; $i < 30; $i++) $steps .= '<dwd:TimeStep>' . gmdate('Y-m-d\TH:i:s.000\Z', 1794744000 + 3600 * ($i + 1)) . "</dwd:TimeStep>\n";
$kml = "<kml><dwd:ProductDefinition><dwd:IssueTime>2026-11-15T09:00:00.000Z</dwd:IssueTime></dwd:ProductDefinition>\n"
     . "<dwd:ForecastTimeSteps>$steps</dwd:ForecastTimeSteps>\n"
     . "<dwd:Forecast dwd:elementName=\"Rad1h\"><dwd:value>$vals</dwd:value></dwd:Forecast></kml>";
$p = call($m, 'parseMosmixKml', $kml);
check('IssueTime gelesen', is_array($p) && $p['issued'] === strtotime('2026-11-15T09:00:00Z'), (string)($p['issued'] ?? 'null'));
$p2 = call($m, 'parseMosmixKml', str_replace('<dwd:IssueTime>2026-11-15T09:00:00.000Z</dwd:IssueTime>', '', $kml));
check('ohne IssueTime → null', is_array($p2) && $p2['issued'] === null);

echo $fail ? "\n$fail Fehler\n" : "\nalle Tests bestanden\n";
exit($fail ? 1 : 0);
