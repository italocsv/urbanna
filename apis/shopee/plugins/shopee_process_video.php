<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bootstrap.php';

header('Content-Type: application/json');

/**
 * ===============================
 * 1. Validação básica da requisiçãoa
 * ===============================
 */

// Aceita apenas POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(405);
    echo json_encode([
        'error' => 'Method not allowed'
    ]);
    exit;
}

// Lê JSON do body
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Valida JSON inválido
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

$shopId   = $data['shop_id'];
$videoUrl = $data['video_url'];


// =================== CONEXÃO MYSQL ===================
$config = require BASE_PATH . '/config/db_mysql_hostgator.php';

$conn = new mysqli(
    $config['host'],
    $config['user'],
    $config['pass'],
    $config['db']
);

if ($conn->connect_error) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'error' => 'Erro de conexão com banco de dados: ' . $conn->connect_error
    ]);
    exit;
}

// ============ BUSCA DADOS NO BANCO DE DADOS ============

$stmt = $conn->prepare("SELECT partner_id, partner_key, host FROM lojaur05_tagplus.apikey_shopee WHERE shop_id = ?");
$stmt->bind_param("s", $shopId);
$stmt->execute();
$stmt->bind_result($partner_id, $partner_key, $host);
$stmt->fetch();
$stmt->close();

if (!$partner_id || !$partner_key || !$host) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400) ;
    echo json_encode([
        'error' => "Nenhum partner_id ou partner_key ou host encontrado para o shop_id: $shopId"]);
    exit;
}

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
$fp = fopen($inputFile, 'w');

$ch = curl_init($videoUrl);
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
 * 7. Dividir o vídeo em partes de 4MB
 * ===============================
 */

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

echo json_encode([
    'status'        => 'ok',
    'message'       => 'Vídeo processado e particionado com sucesso',

    'video' => [
        'file_name' => basename($outputFile),
        'file_size' => $fileSize,
        'file_md5'  => $fileMd5,
        'duration'  => $duration
    ],

    'chunk_config' => [
        'chunk_size_bytes' => $chunkSize,
        'total_parts'      => count($parts)
    ],

    'part_seq_list' => array_column($parts, 'part_seq'),

    'parts' => $parts
]);
