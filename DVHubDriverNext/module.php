<?php

declare(strict_types=1);

/**
 * Direktvermarkter-Treiber für Next. Vertrag siehe CLAUDE.md ("Direktvermarkter-Treiber").
 * Kennt kein Modbus-/blue'Log-RPC-Protokoll selbst — Next liefert seine Vorgabe real über
 * Register, die das eigene NRGModbusServer-Modul (blue'Log-RPC-Emulation) bereits in eine
 * IPS-Variable übersetzt; dieser Treiber verbindet nur diese Variable mit dem DVHub-Vertrag.
 * Rein lesend, protokollunabhängig — funktioniert für jeden Direktvermarkter, dessen Vorgabe
 * bereits als Prozent-Variable in Symcon vorliegt, nicht nur für Next.
 */
class DVHubDriverNext extends IPSModule
{
    // Ab dieser Stille gilt die Datenquelle als nicht mehr verbunden (Fail-safe-Signal für DVHub).
    private const STALE_SECONDS = 300;

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyInteger('SignalVariableID', 0);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetStatus($this->ReadPropertyInteger('SignalVariableID') > 0 ? 102 : 201);
    }

    /**
     * Curtailment-Vorgabe in Prozent (0-100). Fail-safe bewusst 0.0 bei fehlender/kaputter
     * Konfiguration — eine angenommene Abschaltung ist der sichere Fehler (verletzt nie eine
     * echte, uns nur nicht bekannte Abregelungsvorgabe), eine fälschlich angenommene volle
     * Leistung wäre der unsichere.
     */
    public function GetCurtailmentSignal(): float
    {
        $id = $this->ReadPropertyInteger('SignalVariableID');
        if ($id <= 0 || !IPS_VariableExists($id)) {
            $this->LogMessage('Signal-Variable nicht konfiguriert oder nicht vorhanden — nehme sicherheitshalber 0 % an.', KL_WARNING);
            return 0.0;
        }
        return max(0.0, min(100.0, (float) GetValue($id)));
    }

    /** {"connected": bool, "lastUpdate": unix-ts} — Grundlage für DVHubs Fail-safe. */
    public function GetDriverState(): string
    {
        $id = $this->ReadPropertyInteger('SignalVariableID');
        $configured = $id > 0 && IPS_VariableExists($id);

        $lastUpdate = 0;
        $connected = false;
        if ($configured) {
            $lastUpdate = IPS_GetVariable($id)['VariableUpdated'];
            $connected = (time() - $lastUpdate) <= self::STALE_SECONDS;
        }

        return json_encode(['connected' => $connected, 'lastUpdate' => $lastUpdate]);
    }
}
