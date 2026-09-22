<?php

declare(strict_types=1);

/**
 * Client für die Netztransparenz.de WebAPI (https://ds.netztransparenz.de/api/v1/data),
 * hier nur für den Endpunkt "Negative Preise" (Grundlage für die EEG-§51-Grund-
 * Klassifikation in DVHUB_Calc::classifyReason()). IPS-frei, HTTP-Aufrufe über
 * injizierte Callables (kein echtes Netzwerk in Tests, kein Bezug auf IPS-Funktionen).
 *
 * Quelle der API-Details: offizielle "Dokumentation WebAPI Netztransparenz" (Version
 * 07.02.2025). Authentifizierung: OAuth 2.0 Client-Credentials-Flow. Zugangsdaten
 * (Client_ID/Client_Secret) müssen im "OAuth Manager" im Extranet von Netztransparenz.de
 * beantragt werden — das kann nur der Anlagenbetreiber selbst tun, nicht automatisiert.
 */
class DVHUB_NetztransparenzClient
{
    private const TOKEN_URL = 'https://identity.netztransparenz.de/users/connect/token';
    private const BASE_URL = 'https://ds.netztransparenz.de/api/v1/data';

    /**
     * Welche der vier Netztransparenz-Regelvarianten (Endpunkt NegativePreise/<N>) für
     * welche EEG-Fassung gilt. STAND 22.09.2026: aus dem Antwortformat der API selbst
     * abgeleitet (Spalten "Negative Stunden (6H)/(4H)/(3H)/(1H)" in Format 12 der
     * WebAPI-Doku) — die Zuordnung Fassung -> Stundenzahl ist NICHT anhand des
     * konkreten Gesetzestexts gegengeprüft. Vor produktivem Einsatz gegen § 51 EEG in
     * der jeweils gültigen Fassung verifizieren, nicht ungeprüft übernehmen.
     */
    public const VERMUTETE_FASSUNG_ZU_STUNDEN = [
        // ältere Anlagen (vor größeren EEG-Novellen) - historisch die "6-Stunden-Regel"
        'vor2016' => 6,
        // EEG 2017/2021-Ära
        '2017' => 4,
        // EEG 2023 (bis 30.09.2025)
        '2023' => 3,
        // ab 01.10.2025: kalenderstundenscharf, arithmetisches Mittel der Viertelstunden
        'ab2025-10' => 1,
    ];

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
     * Liest die "Negative Preise"-Tabelle für eine der vier Regelvarianten (1/3/4/6
     * Stunden) im angegebenen Zeitraum.
     *
     * @param int $stunden 1, 3, 4 oder 6 (siehe VERMUTETE_FASSUNG_ZU_STUNDEN)
     * @return array [ 'YYYY-MM-DD HH:00' => bool ] - true = negative Preis-Stunde (EEG § 51: kein Vergütungsanspruch)
     */
    public function fetchNegativePreise(int $stunden, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if (!in_array($stunden, [1, 3, 4, 6], true)) {
            throw new \InvalidArgumentException('Unbekannte Regelvariante: ' . $stunden);
        }
        $token = $this->getAccessToken();
        $url = self::BASE_URL . '/NegativePreise/' . $stunden
            . '?dateFrom=' . rawurlencode($from->format('Y-m-d\TH:i:s'))
            . '&dateTo=' . rawurlencode($to->format('Y-m-d\TH:i:s'));
        $response = ($this->httpGet)($url, ['Authorization: Bearer ' . $token]);
        if ($response['status'] !== 200) {
            throw new \RuntimeException('Netztransparenz-Abfrage fehlgeschlagen, Status ' . $response['status']);
        }
        return self::parseNegativePreiseCsv($response['body']);
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
