<?php
header('Content-Type: application/json');

// ===== CONFIG =====
$maxWidth = 1200;
$saveDir  = __DIR__ . '/converted/';
$publicBaseUrl = 'https://app.148.230.72.178.sslip.io/apis/image/converted/'; // AJUSTE SE PRECISAR

// ===== VALIDA ENVIO =====
if (!isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Arquivo não enviado'
    ]);
    exit;
}

$tmpFile = $_FILES['file']['tmp_name'];

if (!file_exists($tmpFile)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Arquivo temporário inválido'
    ]);
    exit;
}

// ===== VALIDA MIME =====
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $tmpFile);
finfo_close($finfo);

if (strpos($mime, 'image') === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Arquivo não é imagem válida'
    ]);
    exit;
}

// ===== CRIA PASTA =====
if (!is_dir($saveDir)) {
    mkdir($saveDir, 0755, true);
}

// ===== GERA NOME ÚNICO =====
$hash = md5_file($tmpFile);
$outputFile = $saveDir . $hash . '.jpg';
$publicUrl  = $publicBaseUrl . $hash . '.jpg';

// Se já convertido antes
if (file_exists($outputFile)) {
    echo json_encode([
        'success' => true,
        'cached'  => true,
        'url'     => $publicUrl
    ]);
    exit;
}

// ===== CONVERSÃO COM FFMPEG =====
$cmd = "ffmpeg -y -i \"$tmpFile\" -vf \"scale='min($maxWidth,iw)':-2\" -c:v mjpeg -pix_fmt yuvj420p -qscale:v 3 \"$outputFile\" 2>&1";
exec($cmd, $output, $returnCode);

if ($returnCode !== 0 || !file_exists($outputFile) || filesize($outputFile) < 1000) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Erro na conversão',
        'ffmpeg_output' => $output
    ]);
    exit;
}

// ===== SUCESSO =====
echo json_encode([
    'success' => true,
    'cached'  => false,
    'url'     => $publicUrl
]);