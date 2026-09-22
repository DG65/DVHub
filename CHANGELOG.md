# Changelog

## 0.3.0 (22.09.2026)

- `DVHub`-Hauptinstanz gebaut (Prefix `DVHUB`): Stammdaten-Formular (NAPs, Anlagenteile,
  Kapazitätsanteile je Anlagenteil/NAP), `RunCycle()` verbindet Treiber-Instanzen mit
  `DVHUB_Calc` und schreibt die NAP-Sollwerte zurück, eigene Anzeige-Variablen je
  Anlagenteil/NAP mit automatischem Aufräumen entfernter Zeilen.
- Automatischer Regeltakt (`UpdateInterval`) standardmäßig deaktiviert — bewusste
  Sicherheitsentscheidung, ein Schreib-Regelkreis darf nicht automatisch mit der
  Instanz-Erstellung scharf werden. Manueller Probelauf per Formular-Button.
- `callDriver()`: Treiber-Modul-Prefix wird dynamisch aus der gewählten Instanz
  aufgelöst statt hartkodiert — DVHub bleibt herstellerneutral. Fehlerhafte/unbekannte
  Treiber-Instanzen liefern `null` statt einer Ausnahme.
- Kein Vermarkter-Treiber zugeordnet ist ein gültiger Zustand (100 % Grundannahme, kein
  Fail-safe-Fall) — anders als ein zugeordneter, aber nicht erreichbarer Treiber.
- 11 Stub-Tests (`.tests/hub_test.php`), alle grün.

## 0.2.0 (22.09.2026)

- `DVHubDriverBlueLog` (Prefix `DVBLM`) und `DVHubDriverNext` (Prefix `DVNXT`) gebaut:
  je eigenständiges, protokollunabhängiges Symcon-Modul, verbindet bestehende IPS-
  Variablen (per Formular gewählt, nichts hartkodiert) mit dem DVHub-Treiber-Vertrag.
- Fail-safe-Sofortverhalten bei fehlender/kaputter Konfiguration: nie mehr Leistung
  anfordern/melden als sicher, nie eine unbekannte Abregelung als "volle Leistung"
  interpretieren.
- 15 Stub-Tests (`.tests/driver_test.php`), alle grün.

## 0.1.0 (22.09.2026)

- Repo angelegt. Architektur-Entscheidung: herstellerneutrales Hub+Treiber-Produkt
  (EZA-Regler-Treiber, Direktvermarkter-Treiber), gewerblich lizenzpflichtig.
- Rechenkern `libs/DVHubCalc.php`: Quotierung je (Anlagenteil, NAP)-Paar,
  NAP-/Anlagenteil-Aggregation, Grund-Klassifikation (EEG § 51 vs. Direktvermarkter-
  kommerziell). CLI-Tests (`.tests/calc_test.php`), Regressionsanker gegen Live-Werte
  der Solarpark-Hofweier-Installation.
- Noch nicht gebaut: konkrete Treiber (blue'Log, Next), Netztransparenz-CSV-Import,
  Fail-safe-Timeout-Logik, `module.php`/Formular, Archiv, Abrechnungsreport.
