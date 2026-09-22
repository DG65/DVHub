# Changelog

## 0.1.0 (22.09.2026)

- Repo angelegt. Architektur-Entscheidung: herstellerneutrales Hub+Treiber-Produkt
  (EZA-Regler-Treiber, Direktvermarkter-Treiber), gewerblich lizenzpflichtig.
- Rechenkern `libs/DVHubCalc.php`: Quotierung je (Anlagenteil, NAP)-Paar,
  NAP-/Anlagenteil-Aggregation, Grund-Klassifikation (EEG § 51 vs. Direktvermarkter-
  kommerziell). CLI-Tests (`.tests/calc_test.php`), Regressionsanker gegen Live-Werte
  der Solarpark-Hofweier-Installation.
- Noch nicht gebaut: konkrete Treiber (blue'Log, Next), Netztransparenz-CSV-Import,
  Fail-safe-Timeout-Logik, `module.php`/Formular, Archiv, Abrechnungsreport.
