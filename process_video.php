<?php
header('Content-Type: application/json');

/**
 * ===============================
 * 1. Validação básica da requisição
 * ===============================
 */

// Aceita apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed'
    ]);
    exit;
}

// Lê JSON do body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Valida parâmetro obrigatório
if (!$data || empty($data['video_url'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'video_url obrigatório'
    ]);
    exit;
}

$videoUrl = $data['video_url'];

/**
 * ===============================
 * 2. Preparação de diretórios
 * ===============================
 */

$tmpInputDir  = __DIR__ . '/tmp/input';
$tmpOutputDir = __DIR__ . '/tmp/output';

@mkdir($tmpInputDir, 0777, true);
@mkdir($tmpOutputDir, 0777, true);

/**
 * ===============================
 * 3. Download do vídeo
 * ===============================
 */

// Descobre extensão (fallback mp4)
$ext = pathinfo(parse_url($videoUrl, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp4';

// Nome único (safe para paralelismo)
$uniqueName = uniqid('video_', true);
$inputFile  = "$tmpInputDir/$uniqueName.$ext";

// Baixa vídeo
$videoData = @file_get_contents($videoUrl);
if ($videoData === false) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Falha ao baixar vídeo'
    ]);
    exit;
}

file_put_contents($inputFile, $videoData);

/**
 * ===============================
 * 4. Validação de duração (ffprobe)
 * ===============================
 */

$cmdDuration = "ffprobe -v error -show_entries format=duration "
             . "-of default=noprint_wrappers=1:nokey=1 "
             . escapeshellarg($inputFile);

$durationRaw = shell_exec($cmdDuration);

if ($durationRaw === null) {
    http_response_code(500);
    echo json_encode([
        'error' => 'ffprobe não disponível no servidor'
    ]);
    exit;
}

$duration = (float) $durationRaw;

if ($duration > 60) {
    echo json_encode([
        'error'    => 'Vídeo maior que 60 segundos',
        'duration' => $duration
    ]);
    exit;
}

/**
 * ===============================
 * 5. Conversão para MP4 compatível Shopee
 * ===============================
 */

$outputFile = "$tmpOutputDir/$uniqueName.mp4";

$cmdConvert = "ffmpeg -y -i "
            . escapeshellarg($inputFile)
            . " -c:v libx264 -profile:v baseline -level 3.0 "
            . " -pix_fmt yuv420p -movflags +faststart "
            . escapeshellarg($outputFile);

shell_exec($cmdConvert);

// Garante que o arquivo foi gerado
if (!file_exists($outputFile)) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Falha na conversão do vídeo'
    ]);
    exit;
}

/**
 * ===============================
 * 6. Metadados exigidos pela Shopee
 * ===============================
 */

$fileSize = filesize($outputFile);
$fileMd5  = md5_file($outputFile);

/**
 * ===============================
 * 7. Resposta final (ÚNICA)
 * ===============================
 */

echo json_encode([
    'status'     => 'ok',
    'message'    => 'Vídeo processado com sucesso',
    'duration'   => $duration,
    'file_name'  => basename($outputFile),
    'file_size'  => $fileSize,
    'file_md5'   => $fileMd5
]);
