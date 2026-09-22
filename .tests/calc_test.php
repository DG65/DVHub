<?php

declare(strict_types=1);

require __DIR__ . '/../libs/DVHubCalc.php';

$failures = 0;
$checks = 0;

function check(string $label, $actual, $expected, float $epsilon = 0.01): void
{
    global $failures, $checks;
    $checks++;
    $ok = is_float($expected) || is_int($expected)
        ? abs($actual - $expected) <= $epsilon
        : $actual === $expected;
    if (!$ok) {
        $failures++;
        echo "FEHLER: $label — erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    } else {
        echo "OK: $label\n";
    }
}

// --- Szenario: Solarpark Hofweier, Live-Werte 22.09.2026 ---
// SP I.1 (10 MWp) hängt an beiden NAPs; SP I.2 nur Hofweier; SP II.1-3 nur Albersbösch.
$anlagenteile = [
    [
        'id' => 'SP I.1',
        'curtailmentSignal' => 100.0, // Gate #31050, aktuell Festwert 100 %
        'napShares' => [
            ['napID' => 'Hofweier', 'kwpShare' => 4015.77],
            ['napID' => 'Albersboesch', 'kwpShare' => 5984.16],
        ],
    ],
    [
        'id' => 'SP I.2',
        'curtailmentSignal' => 100.0,
        'napShares' => [
            ['napID' => 'Hofweier', 'kwpShare' => 3371.84],
        ],
    ],
    [
        'id' => 'SP II.1',
        'curtailmentSignal' => 100.0,
        'napShares' => [
            ['napID' => 'Albersboesch', 'kwpShare' => 2000.0],
        ],
    ],
    [
        'id' => 'SP II.2',
        'curtailmentSignal' => 100.0,
        'napShares' => [
            ['napID' => 'Albersboesch', 'kwpShare' => 1000.0],
        ],
    ],
    [
        'id' => 'SP II.3',
        'curtailmentSignal' => 100.0,
        'napShares' => [
            ['napID' => 'Albersboesch', 'kwpShare' => 1032.84],
        ],
    ],
];

// Verfügbare Leistung testweise = Nennleistung (100 % Sonne), damit die Prozent-Regressionsanker
// direkt gegen die Live-Werte #10664 (54,36 %) / #44214 (59,74 %) prüfbar sind.
$napAvailableWatts = [
    'Hofweier' => 4015.77 + 3371.84,
    'Albersboesch' => 5984.16 + 2000.0 + 1000.0 + 1032.84,
];

$shares = DVHUB_Calc::quotaWattsByShare($anlagenteile, $napAvailableWatts);

$spi1Hofweier = null;
$spi1Albersboesch = null;
foreach ($shares as $row) {
    if ($row['anlagenteilID'] === 'SP I.1' && $row['napID'] === 'Hofweier') {
        $spi1Hofweier = $row['watts'];
    }
    if ($row['anlagenteilID'] === 'SP I.1' && $row['napID'] === 'Albersboesch') {
        $spi1Albersboesch = $row['watts'];
    }
}

check(
    'SP I.1 Anteil Hofweier entspricht #10664 (54,36 %)',
    $spi1Hofweier / $napAvailableWatts['Hofweier'] * 100,
    54.36
);
check(
    'SP I.1 Anteil Albersbösch entspricht #44214 (59,74 %)',
    $spi1Albersboesch / $napAvailableWatts['Albersboesch'] * 100,
    59.74
);

$napSetpoints = DVHUB_Calc::napSetpoints($shares);
check('NAP-Sollwert Hofweier = volle verfügbare Leistung bei 100 % Signal überall', $napSetpoints['Hofweier'], $napAvailableWatts['Hofweier']);
check('NAP-Sollwert Albersbösch = volle verfügbare Leistung bei 100 % Signal überall', $napSetpoints['Albersboesch'], $napAvailableWatts['Albersboesch']);

$anlagenteilWatts = DVHUB_Calc::anlagenteilWatts($shares);
check('SP I.1 Gesamt-Sollwert = Summe beider NAP-Anteile', $anlagenteilWatts['SP I.1'], $spi1Hofweier + $spi1Albersboesch);

// --- Szenario: Next fordert SP I.2 auf 0 %, Rest bleibt unberührt ---
$anlagenteile2 = $anlagenteile;
$anlagenteile2[1]['curtailmentSignal'] = 0.0; // SP I.2
$shares2 = DVHUB_Calc::quotaWattsByShare($anlagenteile2, $napAvailableWatts);
$napSetpoints2 = DVHUB_Calc::napSetpoints($shares2);
check(
    'Hofweier-Sollwert sinkt exakt um SP I.2s vollen Anteil, wenn SP I.2 auf 0 % geht',
    $napSetpoints2['Hofweier'],
    $napAvailableWatts['Hofweier'] - 3371.84
);
check(
    'Albersbösch bleibt unverändert, wenn nur SP I.2 (nur Hofweier) auf 0 % geht',
    $napSetpoints2['Albersboesch'],
    $napAvailableWatts['Albersboesch']
);

// --- Szenario: SP I.1-Gate (#31050) auf 0 % — beide NAP-Anteile fallen weg ---
$anlagenteile3 = $anlagenteile;
$anlagenteile3[0]['curtailmentSignal'] = 0.0; // SP I.1
$shares3 = DVHUB_Calc::quotaWattsByShare($anlagenteile3, $napAvailableWatts);
$anlagenteilWatts3 = DVHUB_Calc::anlagenteilWatts($shares3);
check('SP I.1-Gate auf 0 % nimmt beide NAP-Anteile gleichzeitig raus', $anlagenteilWatts3['SP I.1'], 0.0);

// --- Grund-Klassifikation ---
check('negative Preis-Stunde überstimmt volles Next-Signal', DVHUB_Calc::classifyReason(true, 100.0), 'eeg51');
check('negative Preis-Stunde überstimmt auch eine Next-Reduktion', DVHUB_Calc::classifyReason(true, 0.0), 'eeg51');
check('keine negative Preis-Stunde, aber Next reduziert -> marketer', DVHUB_Calc::classifyReason(false, 0.0), 'marketer');
check('keine negative Preis-Stunde, volles Signal -> none', DVHUB_Calc::classifyReason(false, 100.0), 'none');

// --- Randfall: NAP ohne jegliche Kapazität (Konfigurationsfehler) darf nicht durch 0 teilen ---
$leer = [['id' => 'X', 'curtailmentSignal' => 100.0, 'napShares' => [['napID' => 'Leer', 'kwpShare' => 0.0]]]];
$sharesLeer = DVHUB_Calc::quotaWattsByShare($leer, ['Leer' => 500.0]);
check('NAP-Gesamtkapazität 0 ergibt 0 W statt Division-durch-0-Fehler', $sharesLeer[0]['watts'], 0.0);

echo "\n$checks Prüfungen, $failures Fehler.\n";
exit($failures > 0 ? 1 : 0);
