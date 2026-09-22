<?php

declare(strict_types=1);

/**
 * Client für die Netztransparenz.de WebAPI (https://ds.netztransparenz.de/api/v1/data),
 * hier nur für den Endpunkt "Negative Preise" (Grundlage für die EEG-§51-Grund-
 * Klassifikation in DVHUB_Calc::classifyReason()). IPS-frei, HTTP-Aufrufe über
 * injizierte Callables (kein echtes Netzwerk in Tests, kein Bezug auf IPS-Funktionen).
 *
 * Quelle der API-Details: LIVE gegen die öffentliche Swagger-UI verifiziert
 * (https://api-portal.netztransparenz.de/public-swagger-ui, 22.09.2026) — NICHT nur aus
 * der PDF-Doku übernommen. Die PDF (Version 07.02.2025) beschreibt dateFrom/dateTo als
 * Query-Parameter; das ist laut Swagger-UI veraltet, tatsächlich sind es PFAD-Segmente
 * (Lehre aus Szenariorechners KONZEPT.md: die PDF-Doku kann hinter der Live-API
 * zurückliegen, vor jeder neuen Endpunkt-Anbindung die Swagger-UI gegenprüfen).
 *
 * Authentifizierung: OAuth 2.0 Client-Credentials-Flow. Zugangsdaten (Client_ID/
 * Client_Secret) müssen im "OAuth Manager" im Extranet von Netztransparenz.de beantragt
 * werden. Dietmar hat bereits welche — hinterlegt in der Szenariorechner-Instanz
 * (`NetztransparenzClientId`/`-Secret`-Attribute), noch nicht hier. Siehe CLAUDE.md,
 * Abschnitt "Netztransparenz-Anbindung", zur offenen Frage Wiederverwendung vs. eigene.
 */
class DVHUB_NetztransparenzClient
{
    private const TOKEN_URL = 'https://identity.netztransparenz.de/users/connect/token';
    private const BASE_URL = 'https://ds.netztransparenz.de/api/v1/data';

    /**
     * Live-verifiziert (Swagger-UI, 22.09.2026): gültige Werte für den `logic`-
     * Pfadparameter von `/NegativePreise/{logic}/{dateFrom}/{dateTo}` ("Logic type as
     * Integer for negative quarterly hours"). Mehr Varianten als ursprünglich angenommen
     * (nicht nur 1/3/4/6, auch 2 und 15).
     */
    public const GUELTIGE_LOGIC_WERTE = [1, 2, 3, 4, 6, 15];

    /** @var callable(string $url, array $formFields): array{status:int, body:string} */
    private $httpPost;
    /** @var callable(string $url, array $headers): array{status:int, body:string} */
    private $httpGet;
    private string $clientId;
    private string $clientSecret;
    private ?string $cachedToken = null;
    private int $cachedTokenExpiresAt = 0;

    public function __construct(string $clientId, string $clientSecret, callable $httpPost, callable $httpGet)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->httpPost = $httpPost;
        $this->httpGet = $httpGet;
    }

    /** Zugangstoken, 1 h gültig (laut API-Doku) - wird bis kurz vor Ablauf zwischengespeichert. */
    public function getAccessToken(?int $now = null): string
    {
        $now ??= time();
        if ($this->cachedToken !== null && $now < $this->cachedTokenExpiresAt - 30) {
            return $this->cachedToken;
        }

        $response = ($this->httpPost)(self::TOKEN_URL, [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        if ($response['status'] !== 200) {
            throw new \RuntimeException('Netztransparenz-Token-Anfrage fehlgeschlagen, Status ' . $response['status']);
        }
        $data = json_decode($response['body'], true);
        if (!is_array($data) || !isset($data['access_token'], $data['expires_in'])) {
            throw new \RuntimeException('Netztransparenz-Token-Antwort unerwartet: ' . $response['body']);
        }
        $this->cachedToken = $data['access_token'];
        $this->cachedTokenExpiresAt = $now + (int) $data['expires_in'];
        return $this->cachedToken;
    }

    /**
     * Liest die "Negative Preise"-Tabelle für eine bestimmte historische Regelvariante
     * (`logic`, siehe GUELTIGE_LOGIC_WERTE) im angegebenen Zeitraum. Für DIESE Variante
     * muss der Aufrufer wissen, welche `logic`-Zahl in dem abgefragten Zeitraum rechtlich
     * einschlägig war (die Regel hat sich mehrfach kalendarisch geändert) — relevant für
     * eine rückwirkende Abrechnung über mehrere Jahre, NICHT für die laufende
     * Live-Klassifikation (dafür fetchCurrentNegativePreise() unten verwenden).
     *
     * @param int $logic siehe GUELTIGE_LOGIC_WERTE
     * @return array [ 'YYYY-MM-DD HH:00' => bool ] - true = negative Preis-Stunde (EEG § 51: kein Vergütungsanspruch)
     */
    public function fetchNegativePreise(int $logic, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (!in_array($logic, self::GUELTIGE_LOGIC_WERTE, true)) {
            throw new \InvalidArgumentException('Unbekannte Regelvariante: ' . $logic);
        }
        return $this->getCsv(self::BASE_URL . '/NegativePreise/' . $logic . '/' . self::formatDate($from) . '/' . self::formatDate($to));
    }

    /**
     * Liest die "Negative Preise"-Tabelle nach der aktuell gültigen (stundenscharfen)
     * Regel — ohne `logic`-Parameter, laut Swagger-UI "entitlement to remuneration
     * according to the hourly claim bases". Das ist die richtige Wahl für DVHubs
     * laufende Live-Klassifikation (`DVHUB_Calc::classifyReason()`): die API wendet
     * selbst die für den abgefragten Zeitpunkt aktuell gültige Regel an, DVHub muss
     * keine EEG-Fassung-zu-logic-Zuordnung kennen oder pflegen.
     *
     * @return array [ 'YYYY-MM-DD HH:00' => bool ]
     */
    public function fetchCurrentNegativePreise(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        return $this->getCsv(self::BASE_URL . '/NegativePreise/' . self::formatDate($from) . '/' . self::formatDate($to));
    }

    private function getCsv(string $url): array
    {
        $token = $this->getAccessToken();
        $response = ($this->httpGet)($url, ['Authorization: Bearer ' . $token]);
        if ($response['status'] !== 200) {
            throw new \RuntimeException('Netztransparenz-Abfrage fehlgeschlagen, Status ' . $response['status']);
        }
        return self::parseNegativePreiseCsv($response['body']);
    }

    private static function formatDate(\DateTimeImmutable $d): string
    {
        return rawurlencode($d->format('Y-m-d\TH:i:s'));
    }

    /**
     * Parst das CSV-Format der Endpunkte NegativePreise/1..6 ("Datum;Negativ", Ja/Nein
     * je Stunde - Format 4/16 der WebAPI-Doku).
     *
     * @return array [ 'YYYY-MM-DD HH:00' => bool ]
     */
    public static function parseNegativePreiseCsv(string $csv): array
    {
        $result = [];
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        foreach ($lines as $i => $line) {
            if ($line === '' || $i === 0) {
                continue; // Kopfzeile "Datum;Negativ"
            }
            $parts = explode(';', $line);
            if (count($parts) < 2) {
                continue;
            }
            [$datum, $negativ] = $parts;
            $result[trim($datum)] = strtolower(trim($negativ)) === 'ja';
        }
        return $result;
    }
}
