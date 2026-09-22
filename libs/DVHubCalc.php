<?php

declare(strict_types=1);

/**
 * IPS-freier Rechenkern der DVHub-Quotierung. CLI-testbar, siehe .tests/calc_test.php.
 */
class DVHUB_Calc
{
    /**
     * Berechnet den absoluten Sollwert in W je (Anlagenteil, NAP)-Paar. Granular gehalten
     * (nicht direkt je Anlagenteil summiert), weil ein Anlagenteil wie SP I.1 an mehreren
     * NAPs mit unterschiedlicher aktuell verfügbarer Leistung hängen kann — der Watt-Anteil
     * an Hofweier und der an Albersbösch dürfen nicht zu einer Zahl verschmolzen werden,
     * sonst ist eine spätere Aufteilung auf die realen NAP-Sollwerte nicht mehr korrekt
     * rekonstruierbar (die beiden NAPs haben i. A. unterschiedliche verfügbare Leistung).
     *
     * @param array $anlagenteile Liste von ['id'=>string, 'curtailmentSignal'=>float (0-100),
     *   'napShares'=>[['napID'=>string,'kwpShare'=>float], ...]]
     * @param array $napAvailableWatts [napID => verfügbare Wirkleistung in W]
     * @return array Liste von ['anlagenteilID'=>string, 'napID'=>string, 'watts'=>float]
     */
    public static function quotaWattsByShare(array $anlagenteile, array $napAvailableWatts): array
    {
        $napTotalKwp = [];
        foreach ($anlagenteile as $a) {
            foreach ($a['napShares'] as $share) {
                $napTotalKwp[$share['napID']] = ($napTotalKwp[$share['napID']] ?? 0.0) + $share['kwpShare'];
            }
        }

        $result = [];
        foreach ($anlagenteile as $a) {
            $signal = max(0.0, min(100.0, (float) $a['curtailmentSignal']));
            foreach ($a['napShares'] as $share) {
                $napID = $share['napID'];
                $totalKwp = $napTotalKwp[$napID] ?? 0.0;
                $watts = 0.0;
                if ($totalKwp > 0.0) {
                    $available = $napAvailableWatts[$napID] ?? 0.0;
                    $anteil = $share['kwpShare'] / $totalKwp;
                    $watts = $anteil * $available * ($signal / 100.0);
                }
                $result[] = ['anlagenteilID' => $a['id'], 'napID' => $napID, 'watts' => $watts];
            }
        }
        return $result;
    }

    /**
     * Summiert die (Anlagenteil, NAP)-Werte je Anlagenteil — Grundlage für die
     * Abrechnung/das Archiv je Anlagenteil (Abschnitt 3.7 des Neubau-Konzepts).
     *
     * @param array $shares Ergebnis von quotaWattsByShare()
     * @return array [anlagenteilID => W]
     */
    public static function anlagenteilWatts(array $shares): array
    {
        $result = [];
        foreach ($shares as $row) {
            $result[$row['anlagenteilID']] = ($result[$row['anlagenteilID']] ?? 0.0) + $row['watts'];
        }
        return $result;
    }

    /**
     * Summiert die (Anlagenteil, NAP)-Werte je NAP — das Ergebnis geht an
     * <EZA-Treiber-Prefix>_SetPowerSetpoint() für den jeweiligen NAP.
     *
     * @param array $shares Ergebnis von quotaWattsByShare()
     * @return array [napID => W]
     */
    public static function napSetpoints(array $shares): array
    {
        $result = [];
        foreach ($shares as $row) {
            $result[$row['napID']] = ($result[$row['napID']] ?? 0.0) + $row['watts'];
        }
        return $result;
    }

    /**
     * Grund-Klassifikation für die Abrechnung. Next teilt den tatsächlichen Grund einer
     * Abregelung nicht mit — deshalb bewusst vereinfachte Regel (Dietmar, 22.09.2026):
     * eine amtlich bestätigte negative Preis-Stunde ist IMMER 'eeg51', unabhängig vom
     * tatsächlichen Signal. Sonst zählt jede Reduktion als 'marketer' (deckt auch unbekannte
     * kommerzielle Gründe ab, z. B. mögliche Regelenergiemarkt-Teilnahme).
     */
    public static function classifyReason(bool $isNegativePriceHour, float $curtailmentSignal): string
    {
        if ($isNegativePriceHour) {
            return 'eeg51';
        }
        if ($curtailmentSignal < 100.0) {
            return 'marketer';
        }
        return 'none';
    }
}
