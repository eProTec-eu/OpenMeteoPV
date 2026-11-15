<?php
declare(strict_types=1);
/* Nachbildung von IPSModule und benötigten IP-Symcon-Funktionen für Offline-Tests */

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

require_once __DIR__ . '/../OpenMeteoPV/module.php';
