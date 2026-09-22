<?php

declare(strict_types=1);

/**
 * Treiber-Test mit Stub-Umgebung (ohne echtes IP-Symcon): php .tests/driver_test.php
 * Prüft DVHubDriverBlueLog und DVHubDriverNext gegen den in CLAUDE.md dokumentierten
 * Vertrag: GetAvailablePower/SetPowerSetpoint/GetDriverState bzw.
 * GetCurtailmentSignal/GetDriverState, inkl. Fail-safe-Verhalten bei fehlender Konfiguration.
 */

define('KL_WARNING', 2);

$GLOBALS['vars'] = [];   // id => ['value'=>float, 'updated'=>int, 'action'=>int]
$GLOBALS['props'] = [];
$GLOBALS['requested'] = [];
$GLOBALS['setValues'] = [];
$GLOBALS['logs'] = [];

class IPSModule
{
    public $InstanceID = 999;
    public function __construct() {}
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyInteger($n, $v) { $GLOBALS['props'][$n] ??= $v; }
    public function RegisterPropertyString($n, $v) { $GLOBALS['props'][$n] ??= $v; }
    public function ReadPropertyInteger($n) { return $GLOBALS['props'][$n]; }
    public function ReadPropertyString($n) { return $GLOBALS['props'][$n]; }
    public function SetStatus($s) { $GLOBALS['status'] = $s; }
    public function LogMessage($msg, $level) { $GLOBALS['logs'][] = $msg; }
}

function IPS_VariableExists($id) { return isset($GLOBALS['vars'][$id]); }
function IPS_GetVariable($id) { return ['VariableUpdated' => $GLOBALS['vars'][$id]['updated'], 'VariableAction' => $GLOBALS['vars'][$id]['action']]; }
function GetValue($id) { return $GLOBALS['vars'][$id]['value']; }
function SetValue($id, $v) { $GLOBALS['setValues'][] = [$id, $v]; return true; }
function RequestAction($id, $v) { $GLOBALS['requested'][] = [$id, $v]; return true; }

$fail = 0;
function t(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}

// ---------- DVHubDriverBlueLog ----------
require __DIR__ . '/../DVHubDriverBlueLog/module.php';
$bl = new DVHubDriverBlueLog();
$bl->Create();

t('Fail-safe: GetAvailablePower liefert 0.0 ohne konfigurierte Variable', $bl->GetAvailablePower() === 0.0);
t('Fail-safe: SetPowerSetpoint schlägt ohne konfiguriertes Ziel fehl', $bl->SetPowerSetpoint(1000.0) === false);

$GLOBALS['vars'][100] = ['value' => 5000.0, 'updated' => time(), 'action' => 0];
$GLOBALS['vars'][101] = ['value' => 0.0, 'updated' => time(), 'action' => 0];
$GLOBALS['props']['AvailablePowerVariableID'] = 100;
$GLOBALS['props']['SetpointVariableID'] = 101;
$bl->ApplyChanges();
t('ApplyChanges setzt Status 102 bei vollständiger Konfiguration', $GLOBALS['status'] === 102);
t('GetAvailablePower liest die konfigurierte Variable', $bl->GetAvailablePower() === 5000.0);

$GLOBALS['setValues'] = [];
$ok1 = $bl->SetPowerSetpoint(2500.0); // 50 % von 5000 W verfügbar
t('SetPowerSetpoint (percent-Modus) rechnet 2500 W auf 50 % um', $ok1 && end($GLOBALS['setValues']) === [101, 50.0]);

$GLOBALS['props']['SetpointMode'] = 'absolute';
$GLOBALS['setValues'] = [];
$bl->SetPowerSetpoint(3000.0);
t('SetPowerSetpoint (absolute-Modus) schreibt den Watt-Wert unverändert', end($GLOBALS['setValues']) === [101, 3000.0]);

$GLOBALS['vars'][101]['action'] = 1;
$GLOBALS['requested'] = [];
$bl->SetPowerSetpoint(1234.0);
t('SetPowerSetpoint nutzt RequestAction, wenn die Zielvariable eine Aktion hat', end($GLOBALS['requested']) === [101, 1234.0]);

$state = json_decode($bl->GetDriverState(), true);
t('GetDriverState meldet verbunden bei frischem Wert', $state['connected'] === true);
$GLOBALS['vars'][100]['updated'] = time() - 999;
$state2 = json_decode($bl->GetDriverState(), true);
t('GetDriverState meldet nicht verbunden bei veraltetem Wert (>300s)', $state2['connected'] === false);

// ---------- DVHubDriverNext ----------
$GLOBALS['props'] = [];
$GLOBALS['vars'] = [];
require __DIR__ . '/../DVHubDriverNext/module.php';
$nx = new DVHubDriverNext();
$nx->Create();

t('Fail-safe: GetCurtailmentSignal liefert 0.0 (nicht 100.0!) ohne konfigurierte Variable', $nx->GetCurtailmentSignal() === 0.0);

$GLOBALS['vars'][200] = ['value' => 100.0, 'updated' => time(), 'action' => 0];
$GLOBALS['props']['SignalVariableID'] = 200;
$nx->ApplyChanges();
t('ApplyChanges setzt Status 102 bei konfigurierter Signal-Variable', $GLOBALS['status'] === 102);
t('GetCurtailmentSignal liest die konfigurierte Variable', $nx->GetCurtailmentSignal() === 100.0);

$GLOBALS['vars'][200]['value'] = 150.0; // ungültiger Wert außerhalb 0-100
t('GetCurtailmentSignal kappt auf 100 %', $nx->GetCurtailmentSignal() === 100.0);
$GLOBALS['vars'][200]['value'] = -10.0;
t('GetCurtailmentSignal kappt auf 0 %', $nx->GetCurtailmentSignal() === 0.0);

$GLOBALS['vars'][200]['value'] = 0.0;
$GLOBALS['vars'][200]['updated'] = time();
$stateN = json_decode($nx->GetDriverState(), true);
t('Next-Treiber: GetDriverState meldet verbunden bei frischem Wert', $stateN['connected'] === true);

echo "\n" . ($fail === 0 ? 'Alle Prüfungen bestanden.' : "$fail Prüfung(en) fehlgeschlagen.") . "\n";
exit($fail > 0 ? 1 : 0);
