<?php
header('Content-Type: application/json');

// ===== CONFIG =====
$maxWidth = 1200;
$tempDir  = __DIR__ . '/temp/';
$saveDir  = __DIR__ . '/converted/';
$publicBaseUrl = 'http://app.148.230.72.178.sslip.io/apis/image/converted/';

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

// ===== NOMES =====
$ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
$inputFile  = $tempDir . uniqid('in_') . '.' . $ext;
$outputName = uniqid('img_') . '.jpg';
$outputFile = $saveDir . $outputName;

// ===== DOWNLOAD COM CURL (MUITO MAIS SEGURO) =====
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

$imageData = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$imageData) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao baixar imagem',
        'http_code' => $httpCode
    ]);
    exit;
}

file_put_contents($inputFile, $imageData);

// ===== CONVERSÃO FORÇANDO JPEG REAL =====
$cmd = "ffmpeg -y -i \"$inputFile\" -vf \"scale='min($maxWidth,iw)':-2\" -c:v mjpeg -pix_fmt yuvj420p -qscale:v 3 \"$outputFile\" 2>&1";
exec($cmd, $output, $returnCode);

// remove original
unlink($inputFile);

// ===== VALIDAÇÃO FINAL =====
if ($returnCode !== 0 || !file_exists($outputFile) || filesize($outputFile) < 1000) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro na conversão',
        'ffmpeg_output' => $output
    ]);
    exit;
}

// ===== RETORNO =====
echo json_encode([
    'success' => true,
    'file'    => $outputName,
    'url'     => $publicBaseUrl . $outputName,
    'size_kb' => round(filesize($outputFile) / 1024, 2)
]);