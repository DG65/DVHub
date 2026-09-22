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

Frühe Entwicklungsphase (0.1.0). Der Rechenkern (`libs/DVHubCalc.php`, CLI-testbar über
`php .tests/calc_test.php`) ist funktionsfähig und gegen Live-Werte einer echten
Solarpark-Installation abgesichert. Das eigentliche IP-Symcon-Modul (Formular, Treiber-
Anbindung, Archiv, Abrechnungsreport) ist noch nicht gebaut.

## Lizenz

Private und nicht-kommerzielle Nutzung frei, gewerbliche Nutzung lizenzpflichtig —
siehe [LICENSE](LICENSE).
