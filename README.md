# NRG-Stack DVHub

Quotierung, Abregelung und EEG-/VNB-/Direktvermarkter-Abrechnung für Solarparks mit
mehreren Netzanschlusspunkten (NAPs) und mehreren Direktvermarktungs-Schnittstellen.

## Problem

Ein Direktvermarkter kennt pro Anlagenteil oft nur 100 %/0 %. Reale Abregelung erfolgt
aber ausschließlich über die (meist deutlich wenigeren) EZA-Regler an den NAPs, als
absoluter Wirkleistungssollwert, bezogen auf die aktuell tatsächlich verfügbare Leistung
— nicht auf die Nennleistung. Hängt ein Anlagenteil an mehreren NAPs, muss seine
Nennleistung anteilig quotiert werden. Für die Abrechnung mit VNB und Direktvermarkter
muss zusätzlich nachvollziehbar sein, ob eine Abregelung wegen negativer Börsenpreise
(EEG § 51, kein Vergütungsanspruch) oder aus kommerziellen Gründen des Vermarkters
erfolgte.

## Architektur

DVHub selbst kennt keine konkrete Hardware/API. Zwei Treiber-Rollen docken über einen
festen Funktionsvertrag an — analog zum InverterHub/MeterHub-Muster im übrigen
NRG-Stack:

- **EZA-Regler-Treiber** (liest verfügbare Leistung, schreibt Sollwert je NAP)
- **Direktvermarkter-Treiber** (liest die Curtailment-Vorgabe je Anlagenteil)

Details und Vertragsdefinitionen: [CLAUDE.md](CLAUDE.md).

## Status

Frühe Entwicklungsphase (0.5.0). Fertig und getestet: der Rechenkern
(`libs/DVHubCalc.php`, `php .tests/calc_test.php`), die beiden ersten Treiber
`DVHubDriverBlueLog`/`DVHubDriverNext` (`php .tests/driver_test.php`) und die
DVHub-Hauptinstanz mit Stammdaten-Formular und Quotierungs-Durchlauf
(`php .tests/hub_test.php`). Der automatische Regeltakt ist standardmäßig deaktiviert,
`DryRun` ebenso — ein Lauf muss zunächst manuell und ohne Schreibzugriff bestätigt
werden. Live an der Solarpark-Hofweier-Installation verdrahtet und getestet (Testphase,
weiterhin `DryRun=true`).

Die EEG-§51-Negativpreis-Anbindung ist NICHT Teil dieses Repos — sie lebt als
eigenständiges, verbundweites Modul in [DG65/NRGNetztransparenz](https://github.com/DG65/NRGNetztransparenz)
(Grund: eine geteilte API-Ratenbegrenzung betrifft mehrere NRG-Stack-Module, nicht nur
DVHub). DVHub wird sie über `function_exists('NTP_IsNegativePriceHour')` konsumieren,
sobald in `RunCycle()` eingebaut (noch offen).

Noch nicht gebaut: Grund-Klassifikation/Archiv/Abrechnungsreport in `RunCycle()`,
Einbau des `NTP_IsNegativePriceHour()`-Aufrufs, Fail-safe-Timeout-Logik über den
Sofort-Fallback hinaus.

## Lizenz

Private und nicht-kommerzielle Nutzung frei, gewerbliche Nutzung lizenzpflichtig —
siehe [LICENSE](LICENSE).
