<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/DVHubCalc.php';

/**
 * DVHub-Hauptinstanz. Orchestriert Stammdaten (NAPs, Anlagenteile, Kapazitätsanteile),
 * ruft die Treiber-Instanzen über den in CLAUDE.md dokumentierten Vertrag ab (dynamisch
 * über deren Modul-Prefix aufgelöst — DVHub kennt keine konkrete Treiber-Implementierung),
 * rechnet über DVHUB_Calc und schreibt die NAP-Sollwerte zurück.
 *
 * Bewusst noch NICHT enthalten (siehe CLAUDE.md „Nächste Schritte"): Grund-Klassifikation
 * mit Netztransparenz-Anbindung, Archiv, Abrechnungsreport, Fail-safe-Timeout-Logik über
 * den Sofort-Fallback der Treiber hinaus.
 */
class DVHub extends IPSModule
{
    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('NAPs', '[]');
        $this->RegisterPropertyString('Anlagenteile', '[]');
        $this->RegisterPropertyString('AnlagenteilNapShares', '[]');
        $this->RegisterPropertyInteger('UpdateInterval', 0);
        $this->RegisterAttributeString('LastRunSummary', '');
        $this->RegisterAttributeString('KnownIdents', '[]');
        $this->RegisterTimer('RunCycle', 0, 'DVHUB_RunCycle($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->registerVariables();
        $this->SetTimerInterval('RunCycle', $this->ReadPropertyInteger('UpdateInterval') * 1000);
        $this->SetStatus(102);
    }

    /**
     * Ein vollständiger Quotierungs-Durchlauf: liest Treiber, rechnet über DVHUB_Calc,
     * schreibt die NAP-Sollwerte zurück, aktualisiert die eigenen Anzeige-Variablen.
     * Rückgabe ist ein lesbarer Kurzbericht (Formular-Button-Rückmeldung).
     */
    public function RunCycle(): string
    {
        $naps = $this->decodeList('NAPs');
        $anlagenteileRaw = $this->decodeList('Anlagenteile');
        $shareRows = $this->decodeList('AnlagenteilNapShares');

        $napAvailableWatts = [];
        foreach ($naps as $nap) {
            $driverID = (int) ($nap['ezaDriverInstanceID'] ?? 0);
            $value = $driverID > 0 ? $this->callDriver($driverID, 'GetAvailablePower') : null;
            $napAvailableWatts[$nap['id']] = $value ?? 0.0;
        }

        $anlagenteile = [];
        foreach ($anlagenteileRaw as $a) {
            $marketerID = (int) ($a['marketerDriverInstanceID'] ?? 0);
            if ($marketerID > 0) {
                // Vermarkter zugeordnet, aber gerade nicht erreichbar -> Fail-safe wie beim
                // Treiber selbst: 0 %, nie fälschlich volle Leistung annehmen.
                $signal = $this->callDriver($marketerID, 'GetCurtailmentSignal') ?? 0.0;
            } else {
                // Kein Vermarkter zugeordnet ist ein gültiger, bewusster Zustand (z. B. SP I.1),
                // kein Fehlerfall -> voller Betrieb als Grundannahme, keine Fail-safe-Reduktion.
                $signal = 100.0;
            }

            $napShares = [];
            foreach ($shareRows as $s) {
                if (($s['anlagenteilID'] ?? null) === $a['id']) {
                    $napShares[] = ['napID' => $s['napID'], 'kwpShare' => (float) $s['kwpShare']];
                }
            }

            $anlagenteile[] = [
                'id' => $a['id'],
                'curtailmentSignal' => $signal,
                'napShares' => $napShares,
            ];
        }

        $shares = DVHUB_Calc::quotaWattsByShare($anlagenteile, $napAvailableWatts);
        $napSetpoints = DVHUB_Calc::napSetpoints($shares);
        $anlagenteilWatts = DVHUB_Calc::anlagenteilWatts($shares);

        $written = [];
        foreach ($naps as $nap) {
            $driverID = (int) ($nap['ezaDriverInstanceID'] ?? 0);
            $watts = $napSetpoints[$nap['id']] ?? 0.0;
            if ($driverID > 0) {
                $this->callDriver($driverID, 'SetPowerSetpoint', [$watts]);
            }
            $this->setMaintainedValue('NAP_' . $this->safeIdent($nap['id']) . '_Setpoint', $watts);
            $written[] = $nap['id'] . '=' . round($watts) . ' W';
        }
        foreach ($anlagenteileRaw as $a) {
            $watts = $anlagenteilWatts[$a['id']] ?? 0.0;
            $this->setMaintainedValue('AT_' . $this->safeIdent($a['id']) . '_Watts', $watts);
        }

        $summary = date('d.m.Y H:i:s') . ' — ' . implode(', ', $written);
        $this->WriteAttributeString('LastRunSummary', $summary);
        $this->UpdateFormField('LastRunLabel', 'caption', $summary);

        return $summary;
    }

    /**
     * Ruft eine Vertragsfunktion auf einer Treiber-Instanz auf. Der Modul-Prefix wird
     * dynamisch über die Instanz aufgelöst (nicht hartkodiert auf einen bestimmten
     * Treiber) — DVHub darf keine konkrete Treiber-Implementierung voraussetzen, das
     * widerspräche der Herstellerneutralität. Liefert null, wenn die Instanz fehlt, ihr
     * Modul die Funktion nicht anbietet, oder der Aufruf fehlschlägt — nie eine Ausnahme,
     * ein kaputter/fremdartiger Treiber darf DVHub nicht mitreißen.
     */
    private function callDriver(int $instanceID, string $function, array $args = [])
    {
        if (!IPS_InstanceExists($instanceID)) {
            $this->LogMessage("Treiber-Instanz $instanceID existiert nicht.", KL_WARNING);
            return null;
        }
        $moduleID = IPS_GetInstance($instanceID)['ModuleInfo']['ModuleID'];
        $prefix = IPS_GetModule($moduleID)['Prefix'];
        $fn = $prefix . '_' . $function;
        if (!function_exists($fn)) {
            $this->LogMessage("Treiber-Instanz $instanceID (Modul-Prefix $prefix) bietet $function nicht an.", KL_WARNING);
            return null;
        }
        try {
            return call_user_func($fn, $instanceID, ...$args);
        } catch (\Throwable $e) {
            $this->LogMessage("Aufruf $fn an Instanz $instanceID fehlgeschlagen: " . $e->getMessage(), KL_WARNING);
            return null;
        }
    }

    private function decodeList(string $property): array
    {
        $decoded = json_decode($this->ReadPropertyString($property), true);
        return is_array($decoded) ? $decoded : [];
    }

    private function safeIdent(string $id): string
    {
        return preg_replace('/[^A-Za-z0-9_]/', '_', $id);
    }

    /** Registriert/entfernt die Anzeige-Variablen passend zu den aktuellen Stammdaten. */
    private function registerVariables(): void
    {
        $wanted = [];
        foreach ($this->decodeList('NAPs') as $nap) {
            if (($nap['id'] ?? '') !== '') {
                $wanted['NAP_' . $this->safeIdent($nap['id']) . '_Setpoint'] = 'NAP-Sollwert ' . $nap['id'];
            }
        }
        foreach ($this->decodeList('Anlagenteile') as $a) {
            if (($a['id'] ?? '') !== '') {
                $wanted['AT_' . $this->safeIdent($a['id']) . '_Watts'] = 'Sollwert ' . $a['id'];
            }
        }

        $known = json_decode($this->ReadAttributeString('KnownIdents'), true);
        $known = is_array($known) ? $known : [];

        $this->ensureSharedWattProfile();
        foreach ($wanted as $ident => $caption) {
            $this->MaintainVariable($ident, $caption, VARIABLETYPE_FLOAT, 'NRG.Watt', 0, true);
        }
        foreach ($known as $ident) {
            if (!array_key_exists($ident, $wanted)) {
                $this->MaintainVariable($ident, '', VARIABLETYPE_FLOAT, '', 0, false);
            }
        }

        $this->WriteAttributeString('KnownIdents', json_encode(array_keys($wanted)));
    }

    /**
     * `NRG.Watt` ist das gemeinsame NRG-Stack-Profil für Wirkleistung (Konvention siehe
     * MeterHub-CLAUDE.md). DVHub ist nicht dessen Eigentümer — nur bei Fehlen anlegen,
     * eine bereits von einem anderen NRG-Stack-Modul angelegte Definition nicht anfassen,
     * damit ein gemeinsam genutztes Profil nicht zum stillen Konflikt wird.
     */
    private function ensureSharedWattProfile(): void
    {
        if (!IPS_VariableProfileExists('NRG.Watt')) {
            IPS_CreateVariableProfile('NRG.Watt', VARIABLETYPE_FLOAT);
            IPS_SetVariableProfileDigits('NRG.Watt', 1);
            IPS_SetVariableProfileText('NRG.Watt', '', ' W');
        }
    }

    private function setMaintainedValue(string $ident, float $value): void
    {
        $id = @$this->GetIDForIdent($ident);
        if ($id > 0) {
            $this->SetValue($ident, $value);
        }
    }
}
