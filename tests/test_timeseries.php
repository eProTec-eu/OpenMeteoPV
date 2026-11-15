<?php
declare(strict_types=1);
/* Offline-Test der Zeitreihen-Ausgabe von OpenMeteoPV (IPSModule nachgebildet) */

const KR_READY = 10103; const IPS_KERNELSTARTED = 10001; const KL_WARNING = 10206; const KL_NOTIFY = 10203;
const VARIABLETYPE_BOOLEAN = 0; const VARIABLETYPE_INTEGER = 1; const VARIABLETYPE_FLOAT = 2; const VARIABLETYPE_STRING = 3;
function IPS_VariableProfileExists(string $n): bool { return true; }
function IPS_GetKernelRunlevel(): int { return KR_READY; }
function IPS_GetChildrenIDs(int $id): array { return []; }
function IPS_VariableExists(int $id): bool { return false; }

class IPSModule
{
    public int $InstanceID = 12345;
    public array $props = [], $attrs = [], $vars = [], $debug = [];
    private int $status = 102;
    public function __construct() {}
    public function Create() {}
    public function ApplyChanges() {}
    protected function RegisterPropertyFloat($n, $v) { $this->props[$n] = (float)$v; }
    protected function RegisterPropertyString($n, $v) { $this->props[$n] = (string)$v; }
    protected function RegisterPropertyInteger($n, $v) { $this->props[$n] = (int)$v; }
    protected function RegisterPropertyBoolean($n, $v) { $this->props[$n] = (bool)$v; }
    protected function ReadPropertyFloat($n) { return $this->props[$n]; }
    protected function ReadPropertyString($n) { return $this->props[$n]; }
    protected function ReadPropertyInteger($n) { return $this->props[$n]; }
    protected function ReadPropertyBoolean($n) { return $this->props[$n]; }
    protected function RegisterAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function ReadAttributeString($n) { return $this->attrs[$n]; }
    public function WriteAttributeString($n, $v) { $this->attrs[$n] = $v; }
    protected function RegisterVariableInteger($i, $n, $p = '', $pos = 0) { $this->vars[$i] ??= 0; }
    protected function RegisterVariableFloat($i, $n, $p = '', $pos = 0) { $this->vars[$i] ??= 0.0; }
    protected function RegisterVariableString($i, $n, $p = '', $pos = 0) { $this->vars[$i] ??= ''; }
    protected function MaintainVariable($i, $n, $t, $p, $pos, $keep) { if ($keep) { $this->vars[$i] ??= ''; } else { unset($this->vars[$i]); } }
    protected function SetValue($i, $v) { if (!array_key_exists($i, $this->vars)) throw new Exception("Variable $i fehlt"); $this->vars[$i] = $v; }
    protected function GetIDForIdent($i) { return array_key_exists($i, $this->vars) ? 1 : false; }
    protected function RegisterTimer($n, $i, $s) {}
    protected function SetTimerInterval($n, $i) {}
    protected function RegisterMessage($id, $m) {}
    protected function SendDebug($a, $b, $c) { $this->debug[] = "$a: $b"; }
    protected function LogMessage($m, $t) { $this->debug[] = "LOG: $m"; }
    protected function SetStatus($s) { $this->status = $s; }
    protected function GetStatus() { return $this->status; }
}

require __DIR__ . '/../OpenMeteoPV/module.php';

$fail = 0;
function check(string $n, bool $ok, string $info = ''): void { global $fail; if (!$ok) { $fail++; echo "FAIL: $n $info\n"; } else { echo "ok:   $n $info\n"; } }

foreach ([900, 1800, 3600] as $interval) {
    foreach ([false, true] as $perString) {
        $m = new OpenMeteoPV();
        $m->Create();
        $m->props['TSInterval']  = $interval;
        $m->props['TSPerString'] = $perString;
        $m->props['EnableNowcast'] = false;
        $m->ApplyChanges();

        // synthetische Stundenwerte (Zeitstempel = Intervallende), gestern bis +4 Tage
        $data = [];
        $start = strtotime('yesterday');
        for ($ts = $start + 3600; $ts <= $start + 6 * 86400; $ts += 3600) {
            $hMid = ((int)date('G', $ts - 1800)) + 0.5;
            $ghi = max(0.0, 700.0 * sin(M_PI * ($hMid - 7.5) / 9.0));
            $data[$ts] = [$ghi, null, 8.0, null];
        }
        $m->WriteAttributeString('ForecastCache', json_encode(['key' => 'x', 'fetched' => time() - 600, 'data' => $data]));
        $m->Recalc();

        $tag = "[{$interval}s, je String " . ($perString ? 'ja' : 'nein') . ']';
        $ts = json_decode($m->vars['Forecast_TS'], true);
        file_put_contents(sys_get_temp_dir() . "/omp_ts_{$interval}.json", $m->vars['Forecast_TS']);   // für Gegenprüfung mit lib_core
        check("$tag Format", is_array($ts) && $ts['version'] === 1 && $ts['interval_s'] === $interval && $ts['unit'] === 'W'
            && is_int($ts['start_ts']) && is_int($ts['created_ts']) && is_int($ts['issued_ts']));
        check("$tag Start = heute 00:00", $ts['start_ts'] === strtotime('today'), date('c', $ts['start_ts']));
        $end = $ts['start_ts'] + count($ts['values']) * $interval;
        check("$tag Ende = Ende übermorgen", $end === strtotime('+3 days', strtotime('today')), date('c', $end));
        check("$tag keine Lücken", !in_array(null, $ts['values'], true));

        $t0 = strtotime('today');
        $sumDay = function (int $from, int $to) use ($ts, $interval): float {
            $e = 0.0;
            foreach ($ts['values'] as $i => $w) {
                $a = $ts['start_ts'] + $i * $interval;
                if ($a >= $from && $a < $to) $e += $w * $interval / 3600 / 1000;
            }
            return $e;
        };
        $eToday = $sumDay($t0, strtotime('+1 day', $t0));
        $eTom   = $sumDay(strtotime('+1 day', $t0), strtotime('+2 days', $t0));
        check("$tag Energie heute = Today_kWh", abs($eToday - $m->vars['Today_kWh']) < 0.02, sprintf('%.3f / %.3f', $eToday, $m->vars['Today_kWh']));
        check("$tag Energie morgen = Tomorrow_kWh", abs($eTom - $m->vars['Tomorrow_kWh']) < 0.02, sprintf('%.3f / %.3f', $eTom, $m->vars['Tomorrow_kWh']));
        check("$tag Quelle", str_starts_with($ts['source'], 'dwd-mosmix'), $ts['source']);

        $perVars = array_filter(array_keys($m->vars), fn($k) => str_ends_with($k, '_TS') && $k !== 'Forecast_TS');
        if ($perString) {
            $sum = array_fill(0, count($ts['values']), 0.0);
            foreach ($perVars as $k) {
                $s = json_decode($m->vars[$k], true);
                foreach ($s['values'] as $i => $w) $sum[$i] += $w;
            }
            $maxDiff = max(array_map(fn($a, $b) => abs($a - $b), $sum, $ts['values']));
            check("$tag Summe Strings = gesamt", count($perVars) === 2 && $maxDiff <= 0.2, sprintf('max. Abweichung %.2f W', $maxDiff));
        } else {
            check("$tag keine Zeitreihen je String", count($perVars) === 0);
        }
    }
}
// Abwahl löscht die String-Zeitreihen
$m = new OpenMeteoPV(); $m->Create(); $m->props['TSPerString'] = true; $m->ApplyChanges();
$n1 = count(array_filter(array_keys($m->vars), fn($k) => str_ends_with($k, '_TS')));
$m->props['TSPerString'] = false; $m->ApplyChanges();
$n2 = count(array_filter(array_keys($m->vars), fn($k) => str_ends_with($k, '_TS')));
check('Abwahl entfernt String-Zeitreihen', $n1 === 3 && $n2 === 1, "$n1 → $n2");

echo $fail ? "\n$fail Fehler\n" : "\nalle Tests bestanden\n";
exit($fail ? 1 : 0);
