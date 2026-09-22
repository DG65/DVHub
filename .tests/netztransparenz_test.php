<?php

declare(strict_types=1);

require __DIR__ . '/../libs/NetztransparenzClient.php';

$failures = 0;
$checks = 0;

function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    $ok = $actual === $expected;
    if (!$ok) {
        $failures++;
        echo "FEHLER: $label — erwartet " . var_export($expected, true) . ", erhalten " . var_export($actual, true) . "\n";
    } else {
        echo "OK: $label\n";
    }
}

// --- CSV-Parser (Format 4/16 aus der WebAPI-Doku) ---
$csv = "Datum;Negativ\n2022-06-10 13:00;Ja\n2022-06-10 14:00;Ja\n2022-06-10 15:00;Nein\n";
$parsed = DVHUB_NetztransparenzClient::parseNegativePreiseCsv($csv);
check('CSV-Parser: 3 Zeilen erkannt', count($parsed), 3);
check('CSV-Parser: "Ja" wird true', $parsed['2022-06-10 13:00'], true);
check('CSV-Parser: "Nein" wird false', $parsed['2022-06-10 15:00'], false);

// --- Token-Handling (gemockter HTTP-POST) ---
$postCalls = [];
$getCalls = [];
$fakePost = function (string $url, array $fields) use (&$postCalls) {
    $postCalls[] = [$url, $fields];
    return ['status' => 200, 'body' => json_encode(['access_token' => 'TOKEN-1', 'expires_in' => 3600])];
};
$fakeGet = function (string $url, array $headers) use (&$getCalls) {
    $getCalls[] = [$url, $headers];
    return ['status' => 200, 'body' => "Datum;Negativ\n2024-01-15 03:00;Ja\n2024-01-15 04:00;Nein\n"];
};

$client = new DVHUB_NetztransparenzClient('mein-client-id', 'mein-secret', $fakePost, $fakeGet);
$token = $client->getAccessToken(1000);
check('getAccessToken liefert das Token aus der Antwort', $token, 'TOKEN-1');
check('getAccessToken ruft den korrekten Token-Endpunkt auf', $postCalls[0][0], 'https://identity.netztransparenz.de/users/connect/token');
check('getAccessToken sendet client_credentials-Grant', $postCalls[0][1]['grant_type'], 'client_credentials');

$tokenAgain = $client->getAccessToken(1500); // noch innerhalb der 1h Gültigkeit
check('getAccessToken cacht das Token innerhalb der Gültigkeit (kein zweiter POST)', count($postCalls), 1);

$client->getAccessToken(1000 + 3600 + 1); // nach Ablauf
check('getAccessToken holt nach Ablauf ein neues Token', count($postCalls), 2);

// --- fetchNegativePreise ---
$result = $client->fetchNegativePreise(4, new DateTimeImmutable('2024-01-15T00:00:00'), new DateTimeImmutable('2024-01-16T00:00:00'));
check('fetchNegativePreise liefert das geparste Ergebnis', $result, ['2024-01-15 03:00' => true, '2024-01-15 04:00' => false]);
check('fetchNegativePreise ruft den richtigen Endpunkt auf (4h-Variante)', str_contains(end($getCalls)[0], '/NegativePreise/4'), true);
check('fetchNegativePreise übergibt den Bearer-Token', end($getCalls)[1][0], 'Authorization: Bearer TOKEN-1');

try {
    $client->fetchNegativePreise(2, new DateTimeImmutable(), new DateTimeImmutable());
    check('fetchNegativePreise lehnt unbekannte Regelvariante ab', 'keine Ausnahme', 'InvalidArgumentException');
} catch (\InvalidArgumentException $e) {
    check('fetchNegativePreise lehnt unbekannte Regelvariante ab', 'InvalidArgumentException', 'InvalidArgumentException');
}

// --- Fehlerfall: Token-Endpunkt antwortet nicht mit 200 ---
$failPost = fn($url, $fields) => ['status' => 401, 'body' => 'Unauthorized'];
$failClient = new DVHUB_NetztransparenzClient('x', 'y', $failPost, $fakeGet);
try {
    $failClient->getAccessToken();
    check('getAccessToken wirft bei fehlgeschlagener Authentifizierung', 'keine Ausnahme', 'RuntimeException');
} catch (\RuntimeException $e) {
    check('getAccessToken wirft bei fehlgeschlagener Authentifizierung', 'RuntimeException', 'RuntimeException');
}

echo "\n$checks Prüfungen, $failures Fehler.\n";
exit($failures > 0 ? 1 : 0);
