<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bootstrap.php';
require BASE_PATH . '/apis/shopee/auth/read_tokens.php';

// ===============================
// VALIDAÇÃO DA REQUISIÇÃO
// ===============================

// Aceita apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed'
    ]);
    exit;
}

// Lê e valida JSON do body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!$data) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'error' => 'JSON inválido'
    ]);
    exit;
}

// Valida envio de shop_id
if (!isset($data['shop_id'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'error' => 'Parametro shop_id obrigatório'
    ]);
    exit;
}

//Valida envio de video_url
if (!isset($data['video_url'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'error' => 'Parâmetro video_url obrigatório'
    ]);
    exit;
}

$shop_id   = $data['shop_id'];
$video_url = $data['video_url'];

// ===============================
// PREPARAÇÃO DE DIRETÓRIOS
// ===============================

$tmpInputDir  = __DIR__ . '/tmp/input';
$tmpOutputDir = __DIR__ . '/tmp/output';

@mkdir($tmpInputDir, 0777, true);
@mkdir($tmpOutputDir, 0777, true);

// ===============================
// DOWNLOAD DO VIDEO
// ===============================

// Descobre extensão (fallback mp4)
$ext = pathinfo(parse_url($video_url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'mp4';

// Nome único (safe para paralelismo)
$uniqueName = uniqid('video_', true);
$inputFile  = "$tmpInputDir/$uniqueName.$ext";

// Baixa vídeo
$fp = fopen($inputFile, 'w');

$ch = curl_init($video_url);
curl_setopt_array($ch, [
    CURLOPT_FILE => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_FAILONERROR => true
]);

$ok = curl_exec($ch);
curl_close($ch);
fclose($fp);

if (!$ok || !file_exists($inputFile) || filesize($inputFile) === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Falha ao baixar vídeo']);
    exit;
}

// ===============================
// VALIDAÇÃO DE DURAÇÃO (ffprobe)
// ===============================

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
        'error'    => 'Vídeo maior que 60 segundos :: Será cortado',
        'duration' => $duration
    ]);
}

// ===============================
// CONVERSÃO PARA MP4 COMPATÍVEL COM SHOPEE
// ===============================

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

// ===============================
// AJUSTE SHOPEE: DURAÇÃO + RESOLUÇÃO + TAMANHO
// ===============================

$outputFile = "$tmpOutputDir/$uniqueName.mp4";

// 10s~60s: se <10 repete até 10; se >60 corta
$targetDuration = $duration;
$ffDurArgs = '';

if ($duration < 10) {
    // repete o vídeo até fechar 10s
    $targetDuration = 10.0;
    // -stream_loop -1 repete infinito; -t 10 limita
    $ffDurArgs = "-stream_loop -1 -t 10";
} elseif ($duration > 60) {
    // corta para 60s
    $targetDuration = 60.0;
    $ffDurArgs = "-t 60";
} else {
    $targetDuration = $duration;
}

// Tamanho alvo: use 29MB pra ficar seguro abaixo dos 30MB
$targetBytes = 29 * 1024 * 1024;

// Bitrate total alvo (bps) = (bytes * 8) / segundos
$targetTotalBps = (int) floor(($targetBytes * 8) / max(1.0, $targetDuration));

// Reserva bitrate de áudio (bps)
$audioBps = 96000;

// Bitrate de vídeo (bps)
$videoBps = max(300000, $targetTotalBps - $audioBps);

// Converte bps -> k para ffmpeg
$videoK = (int) floor($videoBps / 1000);
$maxrateK = (int) floor($videoK * 1.10);
$bufsizeK = (int) floor($videoK * 2.00);

// Scale: garante no máximo 1280 em qualquer lado, preservando proporção
$vf = "scale='min(1280,iw)':'min(1280,ih)':force_original_aspect_ratio=decrease";

// Comando final (1-pass). Se quiser qualidade melhor: 2-pass depois.
$cmd = "ffmpeg -y $ffDurArgs -i " . escapeshellarg($inputFile)
     . " -vf " . escapeshellarg($vf)
     . " -c:v libx264 -profile:v baseline -level 3.0 -pix_fmt yuv420p"
     . " -b:v {$videoK}k -maxrate {$maxrateK}k -bufsize {$bufsizeK}k"
     . " -c:a aac -b:a 96k -ac 2"
     . " -movflags +faststart "
     . escapeshellarg($outputFile) . " 2>&1";

$out = shell_exec($cmd);

// valida gerou
if (!file_exists($outputFile) || filesize($outputFile) === 0) {
    http_response_code(500);
    echo json_encode(['error' => 'Falha na conversão/compactação do vídeo', 'ffmpeg' => $out]);
    exit;
}

// valida tamanho <= 30MB
if (filesize($outputFile) > 30 * 1024 * 1024) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Não consegui compactar para <=30MB ainda',
        'size_bytes' => filesize($outputFile)
    ]);
    exit;
}

// ===============================
// METADADOS EXIGIDOS PELA SHOPEE
// ===============================

$fileSize = filesize($outputFile);
$fileMd5  = md5_file($outputFile);

// ===============================
// DIVIDIR O VÍDEO EM PARTES DE 4MB
// ===============================

$chunkSize = 4 * 1024 * 1024; // 4MB
$chunksDir = $tmpOutputDir . "/chunks_$uniqueName";

@mkdir($chunksDir, 0777, true);

// ========== Particionamento binário (robusto e performático) ==========

$handle = fopen($outputFile, 'rb');

if (!$handle) {
    http_response_code(500);
    echo json_encode(['error' => 'Não foi possível abrir o vídeo para leitura']);
    exit;
}

$partIndex   = 0;
$parts       = [];
$totalBytes = 0;

while (!feof($handle)) {
    $data = fread($handle, $chunkSize);
    if ($data === false || $data === '') {
        break;
    }

    $partFile = $chunksDir . "/part_$partIndex.bin";
    file_put_contents($partFile, $data);

    $partSize = filesize($partFile);

    $parts[] = [
        'part_seq'  => $partIndex,
        'file'      => basename($partFile),
        'size'      => $partSize,
        'md5'       => md5_file($partFile)
    ];

    $totalBytes += $partSize;
    $partIndex++;
}

fclose($handle);

/**
 * ===============================
 * 8. Resposta final (ÚNICA)
 * ===============================
 */

/**
*echo json_encode([
*    'status'        => 'ok',
*    'message'       => 'Vídeo processado e particionado com sucesso',
*
*    'video' => [
*        'file_name' => basename($outputFile),
*        'file_size' => $fileSize,
*        'file_md5'  => $fileMd5,
*        'duration'  => $duration
*    ],
*
*    'chunk_config' => [
*        'chunk_size_bytes' => $chunkSize,
*        'total_parts'      => count($parts)
*    ],
*
*    'part_seq_list' => array_column($parts, 'part_seq'),
*
*    'parts' => $parts
*]);
*/ 

/**
 * ===============================
 * Envia Video para Shopee
 * ===============================
 */

// ===============================
// 1. INIT VIDEO UPLOAD
// ===============================

// Retorna tokens da Shopee
$tokens = getShopeeTokensByShopId($shop_id);

$access_token = $tokens['access_token'];
$partner_id   = $tokens['partner_id'];
$partner_key  = $tokens['partner_key'];
$host         = $tokens['host'];

// =================== DECLARA VARIÁVEIS E ENVIA REQUISIÇÃO ===================
$api_path = "/api/v2/media_space/init_video_upload";
$timestamp = time();
$base_string = $partner_id . $api_path . $timestamp . $access_token . $shop_id;
$sign = hash_hmac(
    'sha256',
    $base_string,
    $partner_key
);

$params_url = "?partner_id=" . $partner_id . "&timestamp=" . $timestamp . "&access_token=" . $access_token . "&shop_id=" . $shop_id . "&sign=" . $sign;
$request_url = $host . $api_path . $params_url;

$payload = [
    'file_size' => $fileSize,
    'file_md5'  => $fileMd5,
];

$ch = curl_init($request_url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);

$response = curl_exec($ch);
curl_close($ch);

$initResp = json_decode($response, true);

$videoUploadId = $initResp['response']['video_upload_id'] ?? null;

if (!$videoUploadId) {
    echo json_encode(['error' => 'Falha no init_video_upload', 'shopee' => $initResp]);
    exit;
}

// ===============================
// 2. UPLOAD VIDEO PART
// ===============================

// Retorna tokens da Shopee
$tokens = getShopeeTokensByShopId($shop_id);

$access_token = $tokens['access_token'];
$partner_id   = $tokens['partner_id'];
$partner_key  = $tokens['partner_key'];
$host         = $tokens['host'];

// =================== DECLARA VARIÁVEIS E ENVIA REQUISIÇÃO ===================
$api_path = "/api/v2/media_space/upload_video_part";
$timestamp = time();
$uploadStart = microtime(true); // Inicia cálculo do tempo de upload

foreach ($parts as $part) {
    
    $timestamp = time();
    $baseString = $partner_id . $api_path . $timestamp;
    $sign = hash_hmac(
        'sha256',
        $baseString,
        $partner_key
    );

    $params_url = "?partner_id=" . $partner_id . "&timestamp=" . $timestamp . "&sign=" . $sign;
    $request_url = $host . $api_path . $params_url;

    // caminho real do chunk
    $chunkFile = $chunksDir . '/' . $part['file'];

    // payload MULTIPART
    $payload = [
        'video_upload_id' => $videoUploadId,
        'part_seq'        => $part['part_seq'],
        'content_md5'     => md5_file($chunkFile),
        'part_content'    => new CURLFile(
            $chunkFile,
            'application/octet-stream',
            basename($chunkFile)
        )
    ];

    $ch = curl_init($request_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120
    ]);

    $response = curl_exec($ch);
    curl_close($ch);

    $decoded = json_decode($response, true);

    if (!empty($decoded['error'])) {
        echo json_encode([
            'error' => 'Erro no upload_video_part',
            'part'  => $part['part_seq'],
            'shopee'=> $decoded
        ]);
        exit;
    }
}

$uploadCostMs = (int) round((microtime(true) - $uploadStart) * 1000);// Finaliza cálculo do tempo de upload

$uploadParttResp = json_decode($response, true);

// ===============================
// 3. COMPLETE VIDEO UPLOAD
// ===============================

// Retorna tokens da Shopee
$tokens = getShopeeTokensByShopId($shop_id);

$access_token = $tokens['access_token'];
$partner_id   = $tokens['partner_id'];
$partner_key  = $tokens['partner_key'];
$host         = $tokens['host'];

// =================== DECLARA VARIÁVEIS E ENVIA REQUISIÇÃO ===================
$api_path = "/api/v2/media_space/complete_video_upload";
$timestamp = time();

$baseString = $partner_id . $api_path . $timestamp;
$sign = hash_hmac('sha256', $baseString, $partner_key);

$params_url = "?partner_id=" . $partner_id . "&timestamp=" . $timestamp . "&sign=" . $sign;
$request_url = $host . $api_path . $params_url;

$payload = [
    'video_upload_id' => $videoUploadId,
    'part_seq_list'   => array_column($parts, 'part_seq'),
    'report_data'     => [
        'upload_cost' => $uploadCostMs
    ]
];

$ch = curl_init($request_url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);

$response = curl_exec($ch);
curl_close($ch);

$completeUploadResp = [
    'status_code' => http_response_code(),
    'response' => $response
];

// ===============================
// 4. GET VIDEO UPLOAD RESULT
// ===============================

// Retorna tokens da Shopee
$tokens = getShopeeTokensByShopId($shop_id);

$access_token = $tokens['access_token'];
$partner_id   = $tokens['partner_id'];
$partner_key  = $tokens['partner_key'];
$host         = $tokens['host'];

// =================== DECLARA VARIÁVEIS E ENVIA REQUISIÇÃO ===================
$api_path = "/api/v2/media_space/get_video_upload_result";
$timestamp = time();

$baseString = $partner_id . $api_path . $timestamp . $access_token . $shop_id;
$sign = hash_hmac('sha256', $baseString, $partner_key);

$params_url = "?partner_id=" . $partner_id . "&timestamp=" . $timestamp . "&access_token=" . $access_token . "&shop_id=" . $shop_id . "&sign=" . $sign;
$request_url = $host . $api_path . $params_url;

$payload = [
    'video_upload_id' => $videoUploadId,
];

$ch = curl_init($request_url);
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json']
]);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
$status = $result['response']['status'] ?? null;

$getVideoResp = json_decode($response, true);

echo json_encode([
    'init'     => $initResp,
    'upload'   => $uploadParttResp,
    'complete' => $completeUploadResp,
    'result'   => $getVideoResp,
], JSON_PRETTY_PRINT);

/**
 * ===============================
 * APAGAR VIDEO
 * ===============================
 */