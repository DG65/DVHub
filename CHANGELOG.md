# Changelog

## 0.4.0 (22.09.2026)

- `libs/NetztransparenzClient.php`: Client für die echte Netztransparenz.de-WebAPI
  (OAuth2 Client-Credentials, `NegativePreise/<1|3|4|6>`-Endpunkt, CSV-Parser).
  IPS-frei, HTTP über injizierte Callables, 13 Stub-Tests
  (`.tests/netztransparenz_test.php`), alle grün.
- Bestätigt: Netztransparenz bietet eine echte API, kein reiner CSV-Download nötig.
- Zwei offene Punkte bewusst nicht verschwiegen: welche der vier Regelvarianten
  (1/3/4/6 Stunden) zu welcher EEG-Fassung gehört, ist nur plausibel hergeleitet, nicht
  gegen den Gesetzestext verifiziert; Zugangsdaten müssen vom Anlagenbetreiber selbst
  über den Netztransparenz-eigenen OAuth Manager beantragt werden.

## 0.3.2 (22.09.2026)

- Live-Verdrahtung an der Solarpark-Hofweier-Installation, Testphase (`DryRun=true`):
  2× `DVHubDriverBlueLog`, 4× `DVHubDriverNext`, 1× `DVHub`-Hauptinstanz mit realen
  Stammdaten, komplett getrennt von den bestehenden Alt-Skripten.
  Probelauf erfolgreich, nichts geschrieben.
- Fund: die naheliegenden "Gesamt"-Variablen sind statische Nennleistungs-Summen, keine
  live Verfügbarkeit; die echte "Verfügbare Wirkleistung" von blue'Log deckt nur 4 von
  12 Trafos ab. Interimslösung: Summe der 12 Trafo-"Leistung"-Werte je NAP (nur
  theoretisch identisch mit echter Verfügbarkeit, solange nicht abgeregelt wird — siehe
  CLAUDE.md). Kein Code-Änderung an den Modulen selbst, nur ein installationsspezifisches
  Aggregations-Skript außerhalb des Repos.

## 0.3.1 (22.09.2026)

- `DryRun`-Eigenschaft (Default `true`): `RunCycle()` rechnet und zeigt die Sollwerte,
  schreibt aber erst nach bewusstem Ausschalten tatsächlich an den EZA-Regler-Treiber.
  Ergänzt den schon vorhandenen `UpdateInterval=0`-Schutz um eine zweite, unabhängige
  Sicherheitsstufe vor dem ersten scharfen Lauf an einer echten Installation.

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
