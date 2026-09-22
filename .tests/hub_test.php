<?php

declare(strict_types=1);

/**
 * DVHub-Hauptinstanz-Test mit Stub-Umgebung (ohne echtes IP-Symcon): php .tests/hub_test.php
 * Simuliert zwei Treiber-Instanzen (blue'Log-artig, Next-artig) rein über globale
 * Funktionen mit ihrem jeweiligen Modul-Prefix, wie es das reale Zusammenspiel mit
 * DVHubDriverBlueLog/DVHubDriverNext wäre, um die dynamische Prefix-Auflösung in
 * callDriver() gegenzuprüfen.
 */

define('KL_WARNING', 2);
define('VARIABLETYPE_FLOAT', 2);

$GLOBALS['props'] = [];
$GLOBALS['attrs'] = ['KnownIdents' => '[]', 'LastRunSummary' => ''];
$GLOBALS['maintained'] = []; // ident => value
$GLOBALS['pruned'] = [];
$GLOBALS['formUpdates'] = [];
$GLOBALS['blueLogSetpoints'] = []; // instanceID => letzter geschriebener Wert

class IPSModule
{
    public $InstanceID = 1;
    public function __construct() {}
    public function Create() {}
    public function ApplyChanges() {}
    public function RegisterPropertyString($n, $v) { $GLOBALS['props'][$n] ??= $v; }
    public function RegisterPropertyInteger($n, $v) { $GLOBALS['props'][$n] ??= $v; }
    public function RegisterPropertyBoolean($n, $v) { $GLOBALS['props'][$n] ??= $v; }
    public function ReadPropertyString($n) { return $GLOBALS['props'][$n]; }
    public function ReadPropertyInteger($n) { return $GLOBALS['props'][$n]; }
    public function ReadPropertyBoolean($n) { return $GLOBALS['props'][$n]; }
    public function RegisterAttributeString($n, $v) { $GLOBALS['attrs'][$n] ??= $v; }
    public function ReadAttributeString($n) { return $GLOBALS['attrs'][$n]; }
    public function WriteAttributeString($n, $v) { $GLOBALS['attrs'][$n] = $v; }
    public function RegisterTimer($n, $i, $s) {}
    public function SetTimerInterval($n, $i) {}
    public function SetStatus($s) { $GLOBALS['status'] = $s; }
    public function LogMessage($msg, $level) { $GLOBALS['logs'][] = $msg; }
    public function MaintainVariable($ident, $caption, $type, $profile, $pos, $keep)
    {
        if ($keep) {
            $GLOBALS['maintained'][$ident] = $GLOBALS['maintained'][$ident] ?? 0.0;
        } else {
            unset($GLOBALS['maintained'][$ident]);
            $GLOBALS['pruned'][] = $ident;
        }
    }
    public function GetIDForIdent($ident) { return array_key_exists($ident, $GLOBALS['maintained']) ? 1 : 0; }
    public function SetValue($ident, $value) { $GLOBALS['maintained'][$ident] = $value; }
    public function UpdateFormField($field, $key, $value) { $GLOBALS['formUpdates'][] = [$field, $key, $value]; }
}

// Zwei simulierte Treiber-Instanzen: 501 = blue'Log-Treiber (Hofweier), 502 = blue'Log-Treiber
// (Albersbösch), 601 = Next-Treiber (nur SP I.2 vermarktet).
$GLOBALS['instances'] = [
    501 => 'DVBLM',
    502 => 'DVBLM',
    601 => 'DVNXT',
];
function IPS_InstanceExists($id) { return isset($GLOBALS['instances'][$id]); }
$GLOBALS['profiles'] = [];
function IPS_VariableProfileExists($name) { return isset($GLOBALS['profiles'][$name]); }
function IPS_CreateVariableProfile($name, $type) { $GLOBALS['profiles'][$name] = true; }
function IPS_SetVariableProfileDigits($name, $digits) {}
function IPS_SetVariableProfileText($name, $prefix, $suffix) {}
function IPS_GetInstance($id) { return ['ModuleInfo' => ['ModuleID' => '{' . $GLOBALS['instances'][$id] . '}']]; }
function IPS_GetModule($moduleID) { return ['Prefix' => trim($moduleID, '{}')]; }

$GLOBALS['available'] = [501 => 7387.61 * 1000.0, 502 => 10017.0 * 1000.0]; // W, testweise = Nennleistung
function DVBLM_GetAvailablePower($id) { return $GLOBALS['available'][$id]; }
function DVBLM_SetPowerSetpoint($id, $watts) { $GLOBALS['blueLogSetpoints'][$id] = $watts; return true; }
function DVNXT_GetCurtailmentSignal($id) { return 0.0; } // SP I.2 wird gerade auf 0 % gestellt

require __DIR__ . '/../DVHub/module.php';

$hub = new DVHub();
$hub->Create();
$GLOBALS['props']['NAPs'] = json_encode([
    ['id' => 'Hofweier', 'name' => 'NAP Hofweier', 'ezaDriverInstanceID' => 501],
    ['id' => 'Albersboesch', 'name' => 'NAP Albersbösch', 'ezaDriverInstanceID' => 502],
]);
$GLOBALS['props']['Anlagenteile'] = json_encode([
    ['id' => 'SP I.1', 'name' => 'SP I.1', 'nameplateKWp' => 10000, 'ibnDate' => '2012', 'eegVersion' => '2012', 'operator' => 'A', 'marketerDriverInstanceID' => 0],
    ['id' => 'SP I.2', 'name' => 'SP I.2', 'nameplateKWp' => 3371.84, 'ibnDate' => '2013', 'eegVersion' => '2013', 'operator' => 'A', 'marketerDriverInstanceID' => 601],
]);
$GLOBALS['props']['AnlagenteilNapShares'] = json_encode([
    ['anlagenteilID' => 'SP I.1', 'napID' => 'Hofweier', 'kwpShare' => 4015.77],
    ['anlagenteilID' => 'SP I.1', 'napID' => 'Albersboesch', 'kwpShare' => 5984.16],
    ['anlagenteilID' => 'SP I.2', 'napID' => 'Hofweier', 'kwpShare' => 3371.84],
]);
$GLOBALS['props']['UpdateInterval'] = 0;
$GLOBALS['props']['DryRun'] = false; // Live-Verhalten in diesem Test bewusst mitprüfen
$hub->ApplyChanges();

$fail = 0;
function t(string $label, bool $ok): void
{
    global $fail;
    echo ($ok ? 'OK   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        $fail++;
    }
}

t('registerVariables legt Anzeige-Variablen für NAPs und Anlagenteile an', count($GLOBALS['maintained']) === 4);

$summary = $hub->RunCycle();
t('RunCycle liefert einen nichtleeren Bericht zurück', $summary !== '' && str_contains($summary, 'Hofweier'));
t('LastRunSummary-Attribut wird geschrieben', $GLOBALS['attrs']['LastRunSummary'] === $summary);
t('LastRunLabel im Formular wird aktualisiert', end($GLOBALS['formUpdates'])[0] === 'LastRunLabel');

// SP I.1 hat keinen Vermarkter -> 100 % Grundannahme, kein Fail-safe-0.
// SP I.2 wird von Next (Treiber 601) auf 0 % gestellt -> ihr gesamter Hofweier-Anteil entfällt.
$expectedHofweier = $GLOBALS['available'][501] - 3371.84 * 1000.0;
t(
    'blue\'Log-Treiber Hofweier (501) bekommt korrekten Sollwert (SP I.2 auf 0 % -> entfällt)',
    abs($GLOBALS['blueLogSetpoints'][501] - $expectedHofweier) < 1.0
);
t(
    'blue\'Log-Treiber Albersbösch (502) bekommt die volle verfügbare Leistung (nur SP I.1 dort, unvermarktet -> 100 %)',
    abs($GLOBALS['blueLogSetpoints'][502] - $GLOBALS['available'][502]) < 1.0
);

t('Anzeige-Variable AT_SP_I_2_Watts spiegelt die Next-Abschaltung (0 W)', abs($GLOBALS['maintained']['AT_SP_I_2_Watts']) < 1.0);
t('Anzeige-Variable AT_SP_I_1_Watts > 0 (unvermarktet, läuft voll)', $GLOBALS['maintained']['AT_SP_I_1_Watts'] > 0);

// Stammdaten ändern: Anlagenteil SP I.2 entfernen -> zugehörige Variable muss geprunt werden.
$GLOBALS['props']['Anlagenteile'] = json_encode([
    ['id' => 'SP I.1', 'name' => 'SP I.1', 'nameplateKWp' => 10000, 'ibnDate' => '2012', 'eegVersion' => '2012', 'operator' => 'A', 'marketerDriverInstanceID' => 0],
]);
$hub->ApplyChanges();
t('Entfernter Anlagenteil wird aus den Anzeige-Variablen entfernt (pruned)', in_array('AT_SP_I_2_Watts', $GLOBALS['pruned'], true));
t('Verbleibender Anlagenteil bleibt erhalten', array_key_exists('AT_SP_I_1_Watts', $GLOBALS['maintained']));

// Trockenlauf (Standard): rechnet, zeigt, schreibt NICHT an den echten EZA-Regler.
$GLOBALS['props']['Anlagenteile'] = json_encode([
    ['id' => 'SP I.1', 'name' => 'SP I.1', 'nameplateKWp' => 10000, 'ibnDate' => '2012', 'eegVersion' => '2012', 'operator' => 'A', 'marketerDriverInstanceID' => 0],
    ['id' => 'SP I.2', 'name' => 'SP I.2', 'nameplateKWp' => 3371.84, 'ibnDate' => '2013', 'eegVersion' => '2013', 'operator' => 'A', 'marketerDriverInstanceID' => 601],
]);
$GLOBALS['props']['AnlagenteilNapShares'] = json_encode([
    ['anlagenteilID' => 'SP I.1', 'napID' => 'Hofweier', 'kwpShare' => 4015.77],
    ['anlagenteilID' => 'SP I.1', 'napID' => 'Albersboesch', 'kwpShare' => 5984.16],
    ['anlagenteilID' => 'SP I.2', 'napID' => 'Hofweier', 'kwpShare' => 3371.84],
]);
$GLOBALS['props']['DryRun'] = true;
$hub->ApplyChanges();
$GLOBALS['blueLogSetpoints'] = [];
$dryRunSummary = $hub->RunCycle();
t('Trockenlauf schreibt NICHTS an den blue\'Log-Treiber', $GLOBALS['blueLogSetpoints'] === []);
t('Trockenlauf-Bericht ist als solcher gekennzeichnet', str_contains($dryRunSummary, 'TROCKENLAUF'));
t('Trockenlauf aktualisiert trotzdem die Anzeige-Variablen', $GLOBALS['maintained']['NAP_Hofweier_Setpoint'] > 0);
$GLOBALS['props']['DryRun'] = false;
$hub->ApplyChanges();

// Fehlerhafte/fremde Treiber-Instanz darf nicht abstürzen.
$GLOBALS['instances'][999] = 'UNBEKANNT';
$GLOBALS['props']['NAPs'] = json_encode([
    ['id' => 'Fremd', 'name' => 'Fremd', 'ezaDriverInstanceID' => 999],
]);
$GLOBALS['props']['AnlagenteilNapShares'] = json_encode([]);
$hub->ApplyChanges();
$summary2 = $hub->RunCycle();
t('Unbekannter Treiber-Prefix ohne passende Funktion führt zu keinem Fatal Error', is_string($summary2));

echo "\n" . ($fail === 0 ? 'Alle Prüfungen bestanden.' : "$fail Prüfung(en) fehlgeschlagen.") . "\n";
exit($fail > 0 ? 1 : 0);
