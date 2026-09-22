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

**Gebaut (22.09.2026):** `DVHubDriverBlueLog/` und `DVHubDriverNext/`, je als eigenständiges
Symcon-Modul (`module.json`/`form.json`/`module.php`, Prefix `DVBLM` bzw. `DVNXT`). Beide
kennen kein Modbus-/blue'Log-RPC-Protokoll selbst — die eigentliche Protokollarbeit
übernehmen die nativen ModBus-Device-Instanzen bzw. Dietmars eigenes `NRGModbusServer`-Modul;
die Treiber verbinden nur die daraus schon abgeleiteten IPS-Variablen (per `SelectVariable`
im Formular gewählt, keine hartkodierten IDs) mit dem DVHub-Vertrag. Getestet in
`.tests/driver_test.php` (Stub-Umgebung, 15 Prüfungen).

**`DVHubDriverBlueLog` — Besonderheit Prozent- vs. Watt-Sollwert:** Live an der Solarpark-
Installation vorgefunden (22.09.2026, unter den blue'Log-Datenlogger-Instanzen, Kategorie
„Leistung je Phase"): Der Sollwert-Eingang von blue'Log ist selbst ein Prozentwert
(„Wirkleistungssollwert (%)"), relativ zur von blue'Log berechneten eigenen verfügbaren
Leistung — nicht absolut in Watt. `SetpointMode` (Property, Default `'percent'`) rechnet
deshalb den vom Hub gelieferten absoluten Watt-Sollwert vor dem Schreiben um; `'absolute'`
bleibt für andere EZA-Regler-Systeme vorgesehen, die tatsächlich Watt erwarten.

**Fail-safe-Grundprinzip beider Treiber (noch nicht die volle Timeout-Guard-Logik aus
Abschnitt 3.4 des Neubau-Konzepts, nur der sichere Sofort-Fallback bei fehlender/kaputter
Konfiguration):** Unsicherheit führt immer zu WENIGER angeforderter/gemeldeter Leistung,
nie zu mehr. `GetAvailablePower()` liefert bei fehlender Konfiguration `0.0` (Hub fordert
dann nichts an). `GetCurtailmentSignal()` liefert bei fehlender Konfiguration ebenfalls
`0.0`, nicht `100.0` — eine angenommene Abschaltung verletzt nie eine echte, uns nur nicht
bekannte Abregelungsvorgabe; eine fälschlich angenommene volle Leistung wäre der unsichere
Fehler. `GetDriverState()` meldet `connected: false`, wenn der letzte Wert älter als 300 s
ist (`STALE_SECONDS`), unabhängig vom eigentlichen Wert.

**Noch nicht gebaut:** Die eigentliche Verdrahtung dieser Treiber-Instanzen mit konkreten
Solarpark-Variablen (geschieht beim Anlegen der Instanz im Formular, keine Codeänderung
nötig), Netztransparenz-Import, Fail-safe-Timeout-Logik über den Sofort-Fallback hinaus.

## DVHub-Hauptinstanz (`DVHub/`, Prefix `DVHUB`, gebaut 22.09.2026)

Stammdaten als drei `List`-Formularfelder (bewusst flach, kein verschachteltes
Formularfeld je Zeile — Symcons `List` unterstützt keine editierbare Unterliste):

- `NAPs`: `id`, `name`, `ezaDriverInstanceID` (SelectInstance, zeigt auf eine beliebige
  EZA-Regler-Treiber-Instanz).
- `Anlagenteile`: `id`, `name`, `nameplateKWp`, `ibnDate`, `eegVersion`, `operator`,
  `marketerDriverInstanceID` (0 = kein Direktvermarkter, gültiger Zustand — siehe unten).
- `AnlagenteilNapShares`: eigene, flache Verknüpfungstabelle `anlagenteilID`/`napID`/
  `kwpShare` statt einer Unterliste je Anlagenteil-Zeile — löst, wie ein Anlagenteil (SP I.1)
  an mehreren NAPs hängen kann, ohne Symcons `List`-Grenzen zu verletzen.

**`RunCycle()`** (Prefix-Funktion `DVHUB_RunCycle($id)`, auch per Formular-Button
„Jetzt berechnen" auslösbar): liest je NAP `GetAvailablePower()`, je Anlagenteil mit
Vermarkter-Treiber `GetCurtailmentSignal()`, rechnet über `DVHUB_Calc`, pflegt eigene
Anzeige-Variablen (`AT_<id>_Watts`, `NAP_<id>_Setpoint`, Ident aus der Anlagenteil-/
NAP-Kennung sanitisiert). Zwei unabhängige Sicherheitsstufen, beide standardmäßig
entschärft:

- **`DryRun`** (Boolean, Default `true`): rechnet und zeigt die Sollwerte, ruft aber
  `SetPowerSetpoint()` NICHT auf — der Bericht ist als „[TROCKENLAUF]" gekennzeichnet.
  Bewusst per Voreinstellung an, bevor irgendjemand die erste Instanz an echte
  EZA-Regler-Variablen hängt: der Aufruf schreibt sonst sofort einen echten Sollwert an
  eine reale, ggf. produktive Anlage.
- **`UpdateInterval`** (Sekunden, Default `0` = Timer deaktiviert): auch mit `DryRun=false`
  läuft nichts automatisch, bis der Nutzer bewusst einen Takt einträgt. Vorgeschichte:
  der WriteFunctionCode-Vorfall an der Solarpark-Installation (siehe
  `Solarpark-Neubau-Konzept.md`) — ein Regelkreis darf nicht automatisch mit der
  Instanz-Erstellung scharf werden.

Reihenfolge für einen echten Rollout: 1) Treiber verdrahten, 2) `RunCycle()` manuell mit
`DryRun=true` prüfen, 3) `DryRun` ausschalten, `RunCycle()` erneut manuell auslösen und
das reale Ergebnis am EZA-Regler kontrollieren, 4) erst danach `UpdateInterval` setzen.

**Kein Vermarkter-Treiber zugeordnet ist ein gültiger Zustand, keine Fail-safe-Situation.**
`marketerDriverInstanceID == 0` (wie heute SP I.1) → Grundannahme 100 % (voller Betrieb),
nicht die 0-%-Fail-safe der Treiber selbst. Die 0-%-Fail-safe gilt nur, wenn ein Treiber
zugeordnet, aber gerade nicht erreichbar ist — das ist der eigentliche Fehlerfall.

**Treiber-Aufruf mit dynamisch aufgelöstem Modul-Prefix (`callDriver()`):** anders als das
sonst im NRG-Stack übliche Muster eines fest bekannten Partnermoduls
(`function_exists('MHUB_GetFunctions')`) kennt DVHub den Prefix einer Treiber-Instanz zur
Entwicklungszeit nicht — er wird zur Laufzeit über `IPS_GetInstance()`/`IPS_GetModule()`
aus der vom Nutzer gewählten `SelectInstance` aufgelöst. Jeder Aufruf steht in
`try/catch (\Throwable)`: eine fehlerhafte oder unbekannte Treiber-Instanz liefert `null`
zurück (führt zu deren jeweiligem Fail-safe-Wert weiter oben in der Kette), reißt DVHub
nie mit.

**`NRG.Watt`-Profil:** DVHub ist nicht dessen Eigentümer (`ensureSharedWattProfile()`,
legt nur bei Fehlen an, überschreibt eine bereits von einem anderen NRG-Stack-Modul
angelegte Definition nicht — Muster aus MeterHubs `ensureSharedProfile()`).

Getestet in `.tests/hub_test.php` (Stub-Umgebung inkl. zweier simulierter Treiber-Module
mit eigenem Prefix, 11 Prüfungen: Variablen-Registrierung/-Pruning, End-zu-Ende-Rechnung
über zwei NAPs mit einem mehrfach angebundenen Anlagenteil, unvermarkteter vs.
vermarkteter Anlagenteil, defensive Behandlung eines unbekannten Treiber-Prefix).

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

## Netztransparenz-Anbindung — ausgelagert nach `DG65/NRGNetztransparenz` (22.09.2026)

War zunächst als DVHub-interner Client gebaut (`libs/NetztransparenzClient.php`), dann
noch am selben Tag wieder entfernt und als **eigenständiges, verbundweites Modul**
ausgelagert — Grund: die Netztransparenz-API erlaubt nur 2 Anfragen/Sekunde/IP (2 h
Sperre bei Überschreitung), und mindestens Szenariorechner hat denselben Bedarf
(dort liegen bereits echte Zugangsdaten, aber noch keine `NegativePreise`-Anbindung).
Drei unabhängige Clients (DVHub, Szenariorechner, absehbar EMS) hätten sich gegenseitig
aussperren können. Details, Live-Swagger-Verifikation der API (Pfadsegmente statt der
in der PDF-Doku beschriebenen Query-Parameter, vollständige `logic`-Werteliste
1/2/3/4/6/15) und die noch offene Migration von Szenariorechners bestehenden
Zugangsdaten: siehe `DG65/NRGNetztransparenz`, dortige CLAUDE.md.

**Verdrahtet (22.09.2026):** `RunCycle()` ruft `isNegativePriceHour()` (private
Hilfsmethode) einmal je Durchlauf für `time()` auf und berechnet daraus je Anlagenteil
den Grund über `DVHUB_Calc::classifyReason()` — sichtbar als neue String-Anzeige-
Variable `AT_<id>_Grund` (`'eeg51'|'marketer'|'none'`), mitgepflegt/geprunt wie die
`_Watts`-Variablen. **Bewusst KEIN dynamisch aufgelöster Treiber wie bei EZA-Regler/
Direktvermarkter** (`callDriver()`): Netztransparenz ist ein einziges, fest bekanntes
Verbundmodul, kein vom Nutzer wählbarer Treiber — DVHub sucht sich selbst die erste
vorhandene `Netztransparenz`-Instanz (`IPS_GetInstanceListByModuleID()` mit deren
Modul-GUID), der Nutzer konfiguriert dafür nichts in den Stammdaten. Fail-safe wie im
Netztransparenz-Modul selbst: `false`, wenn das Modul fehlt oder der Aufruf fehlschlägt
(nie eine unbestätigte negative-Preis-Behauptung unterstellen). Getestet in
`.tests/hub_test.php` (u. a.: Grund kippt korrekt auf `eeg51`, sobald eine simulierte
Netztransparenz-Instanz eine negative Stunde meldet — überstimmt sowohl "kein
Vermarkter" als auch ein Next-Signal).

**Noch nicht enthalten:** historisches Wegschreiben des Grunds (Archiv/
Abrechnungsreport, siehe „Nächste Schritte") — der Grund ist aktuell nur eine
Live-Momentaufnahme, keine Zeitreihe.

## Nächste Schritte (Stand 22.09.2026, noch offen)

1. Archiv/Abrechnungsreport: den je Durchlauf berechneten Grund (`AT_<id>_Grund`)
   historisch wegschreiben (z. B. IPS-Archivdienst je Variable), plus ein Report, der
   das je Anlagenteil/Betreiber auswertet (Abschnitt 3.7/3.8 des Neubau-Konzepts).
2. Fail-safe-Timeout-Logik im Rechenkern (über den Sofort-Fallback der Treiber/des Hubs
   hinaus, siehe Abschnitt 3.4 des Neubau-Konzepts: kein Einfrieren, kein Sprung).
3. VCOM-API als optionale Fallback-Quelle für `GetAvailablePower()` — Zugang/Doku noch
   nicht vorhanden.
5. Live-Test an der Solarpark-Installation: Treiber-Instanzen anlegen, mit den echten
   blue'Log-/Next-Variablen verknüpfen, `UpdateInterval` bewusst weiterhin auf 0 lassen,
   bis ein manueller Probelauf (Button) die Ergebnisse bestätigt hat.

## Live-Verdrahtung Solarpark Hofweier (22.09.2026, Testphase, `DryRun=true`)

Erster Probelauf zeigte: die naheliegenden "Gesamt"-Variablen der Anlage (Hofweier/
Albersbösch) sind **statische Nennleistungs-Summen**, keine live wetterabhängige
Verfügbarkeit — untauglich als `AvailablePowerVariableID` trotz plausibel klingendem
Namen. Die "echte" `Verfügbare Wirkleistung`, die blue'Log selbst berechnet (siehe oben),
existiert an dieser Installation nur für 4 von 12 Trafos (deckt nur einen Teil beider
NAPs ab) — als alleinige Quelle ungeeignet, weil sie die tatsächliche Verfügbarkeit
massiv unterschätzen würde.

**Interimslösung (Dietmar, 22.09.2026):** Summe der 12 Trafo-„Leistung"-Werte (aktuell
erzeugte, nicht theoretisch mögliche Wirkleistung) je NAP, in einem eigenen Skript
außerhalb des Moduls (Solarpark-installationsspezifische Trafo-Zuordnung gehört nicht in
den generischen Treiber-Code). Bewusste Einschränkung, von Dietmar bestätigt: **nur
theoretisch identisch mit echter Verfügbarkeit, solange nicht abgeregelt wird** — sobald
eine echte Abregelung aktiv ist, spiegelt "Leistung" bereits die Abregelung selbst wider
(Zirkelschluss-Risiko: die Referenzgröße für "100 %" würde mit der Abregelung selbst
schrumpfen, eine Wiederaufnahme der vollen Leistung wäre rechnerisch nicht mehr sauber
darstellbar). Deckt aber, anders als die 4-von-12-Teilabdeckung der echten "Verfügbaren
Wirkleistung", alle 12 Trafos/beide NAPs vollständig ab.

Aufbau: neue Kategorie „DVHub (Neubau, Testphase)" unter der Direktvermarktungs­
schnittstellen-Kategorie der Solarpark-Installation, komplett getrennt von den
bestehenden Alt-Skripten (nichts Bestehendes verändert). 2× `DVHubDriverBlueLog`
(Hofweier/Albersbösch), 4× `DVHubDriverNext` (SP I.2/II.1/II.2/II.3 — SP I.1 bewusst
ohne Vermarkter-Treiber, siehe oben), 1× `DVHub`-Hauptinstanz mit den realen
Stammdaten, `DryRun=true`. Ein separates Skript summiert die 12 Trafo-„Leistung"-Werte
alle 60 s in zwei Variablen, auf die die beiden `DVHubDriverBlueLog`-Instanzen zeigen.

Probelauf bestätigt: Ergebnis reagiert jetzt korrekt auf Tageszeit (0 W nachts, statt
konstant der Nennleistungs-Summe wie zuvor). Sobald eine echte, nicht-zirkuläre
Verfügbarkeitsquelle existiert (Pyranometer/Einstrahlungssensoren-Berechnung, siehe
Neubau-Konzept 3.6, oder eine vollständigere blue'Log-Quelle), sollte sie diese
Interimslösung ersetzen — vor allem, bevor `DryRun` für einen echten Betrieb
ausgeschaltet wird.
