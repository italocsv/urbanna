<?php
header('Content-Type: application/json');

// ===== CONFIG =====
$maxWidth = 1200;
$tempDir  = __DIR__ . '/temp/';
$saveDir  = __DIR__ . '/converted/';

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

// ===== BAIXA IMAGEM =====
$ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
$inputFile  = $tempDir . uniqid('in_') . '.' . $ext;
$outputFile = $saveDir . uniqid('img_') . '.jpg';

$imageData = @file_get_contents($url);

if (!$imageData) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao baixar imagem']);
    exit;
}

file_put_contents($inputFile, $imageData);

// ===== CONVERSÃO FFMPEG =====
$cmd = "ffmpeg -y -i \"$inputFile\" -vf \"scale='min($maxWidth,iw)':-2\" -q:v 2 \"$outputFile\" 2>&1";
exec($cmd, $output, $returnCode);

unlink($inputFile);

if ($returnCode !== 0 || !file_exists($outputFile)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro na conversão', 'ffmpeg' => $output]);
    exit;
}

// ===== RETORNO =====
echo json_encode([
    'success' => true,
    'file' => basename($outputFile),
    'path' => $outputFile
]);