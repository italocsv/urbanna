<?php

// ============================================================
// CONFIGURAÇÕES
// ============================================================
define('API_KEY',       'M2emsvjmt*20'); // ex: uuidv4 ou string aleatória
define('CLIENT_ID',     'b3ae379c-57d4-4b63-8e1f-8dc7814549de');
define('CLIENT_SECRET', '23aa6f68-9e2b-4312-9b26-d9d8085259a0');
define('SCOPE',         'cob.write cob.read cobv.write cobv.read pix.write pix.read');
define('CERT_PATH',     '/var/inter/certs/cert.crt');
define('KEY_PATH',      '/var/inter/certs/key.key');
define('TOKEN_URL',     'https://cdpj.partners.bancointer.com.br/oauth/v2/token');

// ============================================================
// VALIDAÇÃO DA API KEY
// ============================================================
$headers = getallheaders();
$apikey  = $headers['x-api-key'] ?? $headers['X-Api-Key'] ?? '';

if ($apikey !== API_KEY) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ============================================================
// APENAS POST É ACEITO
// ============================================================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

// ============================================================
// REQUISIÇÃO mTLS PARA O INTER
// ============================================================
$ch = curl_init();

curl_setopt_array($ch, [
    CURLOPT_URL            => TOKEN_URL,
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_SSLCERT        => CERT_PATH,
    CURLOPT_SSLKEY         => KEY_PATH,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/x-www-form-urlencoded'
    ],
    CURLOPT_POSTFIELDS     => http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => CLIENT_ID,
        'client_secret' => CLIENT_SECRET,
        'scope'         => SCOPE,
    ]),
    CURLOPT_TIMEOUT        => 30,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// ============================================================
// RETORNO
// ============================================================
header('Content-Type: application/json');

if ($curlError) {
    http_response_code(500);
    echo json_encode(['error' => 'cURL error', 'detail' => $curlError]);
    exit;
}

http_response_code($httpCode);
echo $response;