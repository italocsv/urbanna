<?php
header('Content-Type: application/json');

// ===== CONFIG =====
$maxWidth = 1200;
$tempDir  = __DIR__ . '/temp/';
$saveDir  = __DIR__ . '/converted/';
$publicBaseUrl = 'https://SEUDOMINIO.com/converted/'; // ALTERAR AQUI

// ===== LÊ BODY =====
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['url'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'URL não enviada']);
    exit;
}

$url = filter_var($input['url'], FILTER_VALIDATE_URL);

if (!$url) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'URL inválida']);
    exit;
}

// ===== CRIA PASTAS =====
if (!is_dir($tempDir)) mkdir($tempDir, 0755, true);
if (!is_dir($saveDir)) mkdir($saveDir, 0755, true);

// ===== NOME BASEADO EM HASH (evita duplicar) =====
$hash = md5($url);
$outputFile = $saveDir . $hash . '.jpg';
$publicUrl  = $publicBaseUrl . $hash . '.jpg';

// Se já existe, não reconverte
if (file_exists($outputFile)) {
    echo json_encode([
        'success' => true,
        'cached'  => true,
        'url'     => $publicUrl
    ]);
    exit;
}

// ===== BAIXA IMAGEM VIA CURL =====
$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_TIMEOUT        => 30
]);

$imageData = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$mime      = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

$size = strlen($imageData);

// ===== VALIDAÇÃO FORTE =====
if (
    $httpCode !== 200 ||
    !$imageData ||
    strpos($mime, 'image') === false ||
    $size < 1000
) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Arquivo inválido',
        'http'    => $httpCode,
        'mime'    => $mime,
        'size'    => $size
    ]);
    exit;
}

// ===== SALVA TEMP =====
$inputFile = $tempDir . $hash . '.tmp';
file_put_contents($inputFile, $imageData);

// ===== CONVERSÃO FFMPEG =====
$cmd = "ffmpeg -y -i \"$inputFile\" -vf \"scale='min($maxWidth,iw)':-2\" -q:v 3 \"$outputFile\" 2>&1";
exec($cmd, $output, $returnCode);

unlink($inputFile);

if ($returnCode !== 0 || !file_exists($outputFile)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erro na conversão',
        'ffmpeg'  => $output
    ]);
    exit;
}

// ===== RETORNO FINAL =====
echo json_encode([
    'success' => true,
    'cached'  => false,
    'url'     => $publicUrl
]);
