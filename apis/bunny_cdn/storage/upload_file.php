<?php

require __DIR__ . '/../auth/read_token.php';

header("Content-Type: application/json");

$bunny_token = getBunnyToken();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit(json_encode(["success"=>false,"error"=>"Método não permitido"]));
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    http_response_code(400);
    exit(json_encode(["success"=>false,"error"=>"JSON inválido"]));
}

$required = ['video_url','storage_zone_name','file_name'];

foreach ($required as $field) {
    if (empty($input[$field])) {
        http_response_code(400);
        exit(json_encode(["success"=>false,"error"=>"Campo obrigatório: {$field}"]));
    }
}

$video_url = trim($input['video_url']);
$storage_zone_name = trim($input['storage_zone_name']);
$path = isset($input['path']) ? trim($input['path']) : '';
$file_name = trim($input['file_name']);

if (!filter_var($video_url, FILTER_VALIDATE_URL)) {
    http_response_code(400);
    exit(json_encode(["success"=>false,"error"=>"video_url inválida"]));
}

/*
====================================================
BAIXA E ENVIA VIA STREAM (SEM ESTOURAR MEMÓRIA)
====================================================
*/

$endpoint = "https://br.storage.bunnycdn.com/{$storage_zone_name}/";

if (!empty($path)) {
    $endpoint .= trim($path,'/') . "/";
}

$endpoint .= rawurlencode($file_name);

$readStream = fopen($video_url, 'rb');

if (!$readStream) {
    http_response_code(500);
    exit(json_encode(["success"=>false,"error"=>"Falha ao abrir stream da URL"]));
}

$ch = curl_init($endpoint);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => "PUT",
    CURLOPT_UPLOAD => true,
    CURLOPT_INFILE => $readStream,
    CURLOPT_HTTPHEADER => [
        "AccessKey: " . $bunny_token,
        "Content-Type: application/octet-stream"
    ],
    CURLOPT_TIMEOUT => 300
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "error" => curl_error($ch)
    ]);
    curl_close($ch);
    fclose($readStream);
    exit;
}

curl_close($ch);
fclose($readStream);

$cdnUrl = "https://urbanna.b-cdn.net/";

if (!empty($path)) {
    $cdnUrl .= trim($path,'/') . "/";
}

$cdnUrl .= rawurlencode($file_name);

http_response_code($httpCode);

echo json_encode([
    "success" => $httpCode >= 200 && $httpCode < 300,
    "bunny_http_code" => $httpCode,
    "cdn_url" => $cdnUrl
]);