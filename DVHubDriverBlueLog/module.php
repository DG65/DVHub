<?php

declare(strict_types=1);

/**
 * EZA-Regler-Treiber für blue'Log Master. Vertrag siehe CLAUDE.md ("EZA-Regler-Treiber").
 * Kennt kein Modbus/blue'Log-Protokoll selbst — das übernehmen die nativen ModBus-Device-
 * Instanzen bzw. eigene Module wie NRGModbusServer; dieser Treiber verbindet nur die von
 * dort schon abgeleiteten IPS-Variablen mit dem DVHub-Vertrag.
 */
class DVHubDriverBlueLog extends IPSModule
{
    // Ab dieser Stille gilt die Datenquelle als nicht mehr verbunden (Fail-safe-Signal für DVHub).
    private const STALE_SECONDS = 300;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('AvailablePowerVariableID', 0);
        $this->RegisterPropertyInteger('SetpointVariableID', 0);
        // blue'Log erwartet den Sollwert selbst als Prozent der eigenen verfügbaren Leistung,
        // nicht als absoluten Watt-Wert (live an der Solarpark-Installation so vorgefunden,
        // 22.09.2026) — deshalb Default 'percent'. Andere EZA-Regler-Systeme können absolute
        // Watt erwarten, daher konfigurierbar statt hartkodiert.
        $this->RegisterPropertyString('SetpointMode', 'percent');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $ok = $this->ReadPropertyInteger('AvailablePowerVariableID') > 0
            && $this->ReadPropertyInteger('SetpointVariableID') > 0;
        $this->SetStatus($ok ? 102 : 201);
    }

    /** Aktuell verfügbare Netto-Wirkleistung am NAP, in W. 0.0 (sicherer Fallback), wenn nicht konfiguriert/vorhanden. */
    public function GetAvailablePower(): float
    {
        $id = $this->ReadPropertyInteger('AvailablePowerVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            $this->LogMessage('Variable "verfügbare Wirkleistung" nicht konfiguriert oder nicht vorhanden — melde 0 W.', KL_WARNING);
            return 0.0;
        }
        return (float) GetValue($id);
    }

    /**
     * Schreibt den absoluten Sollwert (W) an blue'Log — rechnet ihn je nach SetpointMode
     * zuvor in Prozent der aktuell verfügbaren Leistung um. false, wenn nicht konfiguriert
     * oder der Schreibvorgang fehlschlägt.
     */
    public function SetPowerSetpoint(float $watts): bool
    {
        $targetID = $this->ReadPropertyInteger('SetpointVariableID');
        if ($targetID <= 0 || !IPS_VariableExists($targetID)) {
            $this->LogMessage('Sollwert-Zielvariable nicht konfiguriert oder nicht vorhanden.', KL_WARNING);
            return false;
        }

        $value = $watts;
        if ($this->ReadPropertyString('SetpointMode') === 'percent') {
            $available = $this->GetAvailablePower();
            $value = $available > 0.0 ? max(0.0, min(100.0, $watts / $available * 100.0)) : 0.0;
        }

        $variable = IPS_GetVariable($targetID);
        if ($variable['VariableAction'] != 0) {
            return (bool) @RequestAction($targetID, $value);
        }
        return (bool) @SetValue($targetID, $value);
    }

    /** {"connected": bool, "lastUpdate": unix-ts} — Grundlage für DVHubs Fail-safe. */
    public function GetDriverState(): string
    {
        $availID = $this->ReadPropertyInteger('AvailablePowerVariableID');
        $setID = $this->ReadPropertyInteger('SetpointVariableID');
        $configured = $availID > 0 && IPS_VariableExists($availID)
            && $setID > 0 && IPS_VariableExists($setID);

        $lastUpdate = 0;
        $connected = false;
        if ($configured) {
            $lastUpdate = IPS_GetVariable($availID)['VariableUpdated'];
            $connected = (time() - $lastUpdate) <= self::STALE_SECONDS;
        }

        return json_encode(['connected' => $connected, 'lastUpdate' => $lastUpdate]);
    }
}
