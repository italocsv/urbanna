<?php

require $_SERVER['DOCUMENT_ROOT'] . '/bootstrap.php';
require BASE_PATH . '/apis/bunny_cdn/auth/read_token.php';

header("Content-Type: application/json");

$bunny_token = getBunnyToken();

// =============================
// 1) Só aceita POST
// =============================
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode([
        "success" => false,
        "error" => "Método não permitido"
    ]));
}

// =============================
// 2) Lê JSON do body
// =============================
$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    http_response_code(400);
    exit(json_encode([
        "success" => false,
        "error" => "JSON inválido"
    ]));
}

// =============================
// 3) Validação dos parâmetros
// =============================
if (!isset($input['video_url']) || !isset($input['libraryId'])) {
    http_response_code(400);
    exit(json_encode([
        "success" => false,
        "error" => "Parâmetros obrigatórios: video_url e libraryId"
    ]));
}

$video_url = trim($input['video_url']);
$libraryId = trim($input['libraryId']);

if (!filter_var($video_url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit(json_encode([
        "success" => false,
        "error" => "video_url inválida"
    ]));
}

if (!is_numeric($libraryId)) {
    http_response_code(400);
    exit(json_encode([
        "success" => false,
        "error" => "libraryId inválido"
    ]));
}

// =============================
// 4) Monta endpoint Bunny
// =============================
$endpoint = "https://video.bunnycdn.com/library/" . $libraryId . "/videos/fetch";

$body = json_encode([
    "url" => $video_url
]);

// =============================
// 5) Faz requisição cURL
// =============================
$ch = curl_init($endpoint);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        "AccessKey: " . $bunny_token,
        "Content-Type: application/json",
        "Accept: application/json"
    ],
    CURLOPT_TIMEOUT => 60
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => "Erro na requisição cURL",
        "details" => curl_error($ch)
    ]);
    curl_close($ch);
    exit;
}

curl_close($ch);

// =============================
// 6) Retorna resposta do Bunny
// =============================
http_response_code($httpCode);

echo json_encode([
    "success" => $httpCode >= 200 && $httpCode < 300,
    "bunny_http_code" => $httpCode,
    "bunny_response" => json_decode($response, true)
]);