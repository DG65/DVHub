# DVHub — Hinweise für die Arbeit an diesem Repository

## Zweck und Entstehung

DVHub entsteht aus einer Architektur-Neubau-Entscheidung für Dietmars kommerziellen
Solarpark Hofweier (22.09.2026, siehe `Solarpark-Neubau-Konzept.md` im übergeordneten
Verzeichnis `Nextcloud/Claude/` für die vollständige Herleitung). Ausgangsproblem: 5
Anlagenteile (Direktvermarktungs-Schnittstellen), 2 Betreiber, 2 Netzanschlusspunkte
(NAPs) mit je einem EZA-Regler als einzigem realen Abregel-Punkt. Der Direktvermarkter
kennt pro Anlagenteil nur 100 %/0 %, die reale Abregelung muss aber als absolute
Wirkleistung (kW), bezogen auf die aktuell verfügbare Leistung (nicht die Nennleistung),
an die 2 EZA-Regler weitergegeben werden — eine Quotierung.

**Bewusste Entscheidung (Dietmar, 22.09.2026): DVHub wird als echtes, herstellerneutrales
Produkt gebaut ("Variante 3"), nicht als anlagenspezifisches Privat-Tool.** Andere
Betreiber mit anderer EZA-Regler-Hardware und anderen Direktvermarktern sollen es nutzen
können. Deshalb Hub+Treiber-Architektur (siehe unten), analog zu InverterHub/MeterHub/
WPModbusHub im übrigen NRG-Stack.

**Lizenz: gewerblich lizenzpflichtig.** Dieses Modul unterstützt EEG-/VNB-/Direkt­
vermarkter-Abrechnung für kommerzielle Betreiber — anders als die übrigen, primär auf
Privatanlagen zielenden NRG-Stack-Module ist der Regelfall hier die gewerbliche Nutzung,
nicht die Ausnahme. Lizenztext ist trotzdem identisch zur übrigen NRG-Stack-Lizenz
(PolyForm Noncommercial 1.0.0 + gewerblicher Sonderlizenz-Hinweis, siehe `LICENSE`) —
kein neues Lizenzmodell nötig, nur ein anderer erwarteter Nutzerkreis.

## Architektur: Hub + Treiber

DVHub selbst kennt keine konkrete EZA-Regler- oder Direktvermarkter-Hardware/-API. Es
orchestriert nur: Stammdaten, Quotierungsrechnung, Grund-Klassifikation, Archiv,
Abrechnungsreport. Zwei Treiber-Rollen docken über einen festen Funktionsvertrag an
(Muster: `MHUB_GetFunctions` in MeterHub — siehe dortige CLAUDE.md, Abschnitt
„Konvention für `*_GetFunctions`-Verträge", als Referenz für den Stil dieser Vertäge,
nicht wortgleich zu übernehmen, da DVHub im Unterschied zu MeterHub selbst **Konsument**
zweier Fremdverträge ist, nicht nur Anbieter eines eigenen).

### EZA-Regler-Treiber (erster: blue'Log Master, Solarpark Hofweier)

Ein Treiber-Modul pro EZA-Regler-Hersteller, eigenes Prefix, vom Nutzer je NAP in DVHubs
Formular als InstanceID ausgewählt. Vertrag (Platzhalter-Prefix `<X>`, siehe Treiber-Repo
für das tatsächliche Prefix):

| Funktion | Bedeutung |
|---|---|
| `<X>_GetAvailablePower($id): float` | aktuell verfügbare Netto-Wirkleistung am NAP, in W. Muss die reale, wetterabhängige Verfügbarkeit sein, NIE die Nennleistung — Referenzgröße für "100 %" bei der Quotierung. |
| `<X>_SetPowerSetpoint($id, float $watts): bool` | schreibt den absoluten Wirkleistungssollwert an den EZA-Regler. |
| `<X>_GetDriverState($id): string` (JSON) | `{"connected": bool, "lastUpdate": <unix-ts>}` — Grundlage für DVHubs Fail-safe (siehe unten). |

### Direktvermarkter-Treiber (erster: Next, über Dietmars eigenes `NRGModbusServer`-Modul
als blue'Log-RPC-Emulation)

Ein Treiber-Modul pro Direktvermarkter/API, eigenes Prefix, vom Nutzer je Anlagenteil in
DVHubs Formular als InstanceID ausgewählt (0 = kein Direktvermarkter, z. B. SP I.1).

| Funktion | Bedeutung |
|---|---|
| `<X>_GetCurtailmentSignal($id): float` | Vorgabe in Prozent, 0.0–100.0. Vertrag erlaubt bewusst Zwischenwerte, auch wenn heutige Vermarkter (Next) real nur 0/100 liefern — künftige Vermarkter mit feinerer Stufung (z. B. 60/30/0 %) brauchen keinen Vertragsbruch. |
| `<X>_GetDriverState($id): string` (JSON) | wie oben. |

**Noch nicht gebaut:** der konkrete Next-Treiber (liest die Register aus Dietmars
`NRGModbusServer`-Instanz) und der blue'Log-Treiber (liest/schreibt über die nativen
ModBus-Device-Instanzen). Das sind eigene, spätere Bausteine — DVHub selbst darf keine
Kenntnis von Modbus/blue'Log/Next haben, sonst bricht die Herstellerneutralität.

### Warum nicht ein gemeinsamer Vertrag für beide Rollen?

EZA-Regler-Treiber sind lesend UND schreibend (Sollwert), Direktvermarkter-Treiber nur
lesend (Vorgabe). Ein gemeinsames Interface würde bei einer der beiden Rollen sinnlose
Funktionen erzwingen (z. B. `SetPowerSetpoint` bei einem reinen Vorgabe-Lieferanten).
Getrennte, kleine Verträge statt eines aufgeblähten gemeinsamen.

## Stammdaten-Modell (Property, DVHub-Hauptinstanz)

Zwei Ebenen, beide als `List`-Formularfelder, N/M beliebig (kein Hardcoding auf 2
NAPs/5 Anlagenteile — bewusste Entscheidung, siehe Neubau-Konzept):

**NAPs:** `id`, `name`, `ezaDriverInstanceID`.

**Anlagenteile:** `id`, `name`, `nameplateKWp`, `ibnDate`, `eegVersion`, `operator`,
`marketerDriverInstanceID` (0 = kein Direktvermarkter, z. B. SP I.1), `napShares` (Liste
`{napID, kwpShare}` — ein Anlagenteil kann an mehreren NAPs hängen, wie SP I.1 an Hofweier
UND Albersbösch; Summe der `kwpShare` über alle Zeilen eines Anlagenteils = `nameplateKWp`,
wird geprüft, nicht erzwungen berechnet).

## Rechenkern: `libs/DVHubCalc.php`

IPS-frei, CLI-testbar (`php .tests/calc_test.php`), analog zum Aufbau von
`libs/ModbusServer.php` im ModbusSlave-Repo. Enthält:

- **Quotierung** (`quotaWattsByShare()`): pro (Anlagenteil, NAP)-Paar `kwpShare /
  Summe(kwpShare am selben NAP) × verfügbare Wirkleistung am NAP × Curtailment-Signal/100`.
  Bewusst granular je Paar gehalten, nicht direkt je Anlagenteil summiert — ein Anlagenteil
  wie SP I.1 hängt an zwei NAPs mit i. A. unterschiedlicher verfügbarer Leistung, eine
  vorzeitige Summe wäre bei der späteren NAP-Aufteilung nicht mehr korrekt rekonstruierbar.
  Fachliche Vorlage: Solarpark-Skripte „Prozentuale Verteilung" (#15969) und „Berechnung
  Prozentuale Weitergabe an das ÜWM" (#38899), aber verallgemeinert auf N NAPs/M
  Anlagenteile statt hartcodiert auf 2/5. Live-Referenzwerte aus der Solarpark-Installation
  (22.09.2026, SP I.1 an Hofweier 4015,77 kWp von 7387,61 kWp NAP-Summe = 54,36 %) dienen
  als Regressionsanker in `.tests/calc_test.php`.
- **Aggregation je Anlagenteil** (`anlagenteilWatts()`): Summe über die NAP-Anteile eines
  Anlagenteils — Grundlage für Archiv/Abrechnung je Anlagenteil.
- **Aggregation je NAP** (`napSetpoints()`): Summe über alle Anlagenteil-Anteile eines
  NAP → das, was tatsächlich per `SetPowerSetpoint()` an den jeweiligen EZA-Regler-Treiber
  geht. Beide Aggregationen laufen auf derselben granularen `quotaWattsByShare()`-Liste,
  nicht auseinander abgeleitet.
- **Grund-Klassifikation** (`classifyReason()`): reine Funktion `(bool $isNegativePriceHour,
  float $curtailmentSignal): string`, Ergebnis `'eeg51'|'marketer'|'none'`. Regel
  (Dietmar, 22.09.2026, bewusst so vereinfacht, weil Next den tatsächlichen Grund nicht
  mitteilt): Ist die Stunde laut Netztransparenz-Tabelle amtlich negativ → immer `'eeg51'`,
  unabhängig vom tatsächlichen Signal. Sonst, wenn `curtailmentSignal < 100` →
  `'marketer'` (deckt auch unbekannte Gründe wie eine mögliche Regelenergiemarkt-
  Teilnahme ab, ohne dass wir das von Next erfahren müssten). Sonst `'none'`.

**Noch nicht gebaut:** Netztransparenz-CSV-Import (liefert `isNegativePriceHour` je
Stunde und EEG-Fassung — offizielle, monatlich aktualisierte Tabellen unter
https://www.netztransparenz.de/de-de/Erneuerbare-Energien-und-Umlagen/EEG/
Transparenzanforderungen/Marktprämie/Negativer-Spotmarktpreis-Übersichtstabellen,
genaue Schwellenwerte je EEG-Fassung/Jahr noch nicht im Detail nachrecherchiert — VOR
Verwendung die tatsächlichen CSV-Spalten/Werte gegen die Seite prüfen, nicht aus dieser
Notiz raten), Fail-safe-Timeout-Logik (Vorbild: `MBSLVTimeoutGuard` im ModbusSlave-Repo),
Archiv-Schreibstruktur je Anlagenteil, Abrechnungsreport, das eigentliche `module.php`
mit Formular/Live-Verdrahtung.

## Bezug zu bestehenden NRG-Stack-Konventionen

Diese Konventionen gelten hier genauso wie in den übrigen Repos (Details siehe
`EMS/SUITE.md` bzw. die CLAUDE.md von MeterHub/InverterHub als Referenz, nicht hier
wiederholt): Sprachregel Deutsch für alles Nutzersichtbare (Idents ausgenommen), Emoji
permissiv mit Augenmaß, `NRG.*`-Profile für die sechs Grundgrößen, Zugangsdaten-Handshake-
Regel bei Cloud-/API-Treibern, „keine eigene Anlage als Norm" (Prüffrage bei jedem
Formularfeld: gilt das für JEDEN Nutzer?), Hilfsordner im Repo-Wurzelverzeichnis mit
führendem Punkt (Store-Fallstrick), globale Klassennamen mit Modul-Präfix (`DVHUB_…`).

## Nächste Schritte (Stand 22.09.2026, noch offen)

1. blue'Log-Treiber und Next-Treiber tatsächlich bauen (letzterer konsumiert Dietmars
   `NRGModbusServer`-Register).
2. Netztransparenz-CSV-Import als eigenständiger, wiederverwendbarer Baustein.
3. Fail-safe-Timeout-Logik im Rechenkern.
4. `module.php`/`form.json` für die DVHub-Hauptinstanz (Stammdaten-Formular, Live-
   Verdrahtung der Treiber, Archiv, Abrechnungsreport).
5. VCOM-API als optionale Fallback-Quelle für `GetAvailablePower()` — Zugang/Doku noch
   nicht vorhanden.
