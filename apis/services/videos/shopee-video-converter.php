<?php
header('Content-Type: application/json; charset=utf-8');

// ================= CONFIG =================
$ffmpegBin  = 'ffmpeg';
$ffprobeBin = 'ffprobe';

$baseDir = __DIR__;
$tempDir = $baseDir . '/temp/';
$outDir = $baseDir . '/runtime/videos/';
$publicBaseUrl = 'https://services.urbanna.com.br/apps/runtime/videos/';

$maxDownloadBytes = 500 * 1024 * 1024; // 500MB
$maxDuration = 60; // segundos
$maxOutputBytes = 30 * 1024 * 1024; // 30MB

// ================= HELPERS =================
function respond($code, $data) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function ensureDir($dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
}

function isValidHttpUrl($url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) return false;
    $p = parse_url($url);
    return isset($p['scheme']) && in_array(strtolower($p['scheme']), ['http','https']);
}

function safeFilename($name) {
    return preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
}

function getCodecInfo($ffprobeBin, $file) {
    $cmd = "$ffprobeBin -v error -select_streams v:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($file);
    $videoCodec = trim(shell_exec($cmd));

    $cmd = "$ffprobeBin -v error -select_streams a:0 -show_entries stream=codec_name -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($file);
    $audioCodec = trim(shell_exec($cmd));

    return [
        'video' => strtolower($videoCodec),
        'audio' => strtolower($audioCodec)
    ];
}

function getDuration($ffprobeBin, $file) {
    $cmd = "$ffprobeBin -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . escapeshellarg($file);
    return floatval(shell_exec($cmd));
}

// ================= INIT =================
ensureDir($tempDir);
ensureDir($outDir);

// Checa ffmpeg
exec("$ffmpegBin -version 2>&1", $checkOut, $checkCode);
if ($checkCode !== 0) {
    respond(500, ['success'=>false,'error'=>'ffmpeg não encontrado']);
}

// ================= INPUT =================
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['url'])) {
    respond(400, ['success'=>false,'error'=>'Envie {"url":"..."}']);
}

$url = trim($input['url']);

if (!isValidHttpUrl($url)) {
    respond(400, ['success'=>false,'error'=>'URL inválida']);
}

// ================= DOWNLOAD =================
$uid = date('Ymd_His') . '_' . bin2hex(random_bytes(4));
$originalName = safeFilename(basename(parse_url($url, PHP_URL_PATH)));
if (!$originalName) $originalName = "video";

$inFile = $tempDir . $uid . "_" . $originalName;
$outFile = $outDir . $uid . ".mp4";

$fp = fopen($inFile, 'wb');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_FILE => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 300,
    CURLOPT_SSL_VERIFYPEER => true
]);

curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

if ($http < 200 || $http >= 300) {
    @unlink($inFile);
    respond(400, ['success'=>false,'error'=>'Falha no download']);
}

// ================= ANALISA =================
$duration = getDuration($ffprobeBin, $inFile);
$cutOption = '';

if ($duration > $maxDuration) {
    $cutOption = "-t $maxDuration";
    $duration = $maxDuration;
}

// ================= CONVERSÃO =================
$videoBitrate = 2000; // kbps inicial

$cmd = "$ffmpegBin -y -i " . escapeshellarg($inFile) .
       " $cutOption" .
       " -c:v libx264 -preset veryfast -b:v {$videoBitrate}k" .
       " -c:a aac -b:a 128k -movflags +faststart " .
       escapeshellarg($outFile);

exec($cmd . " 2>&1", $convOut, $convCode);

if ($convCode !== 0) {
    @unlink($inFile);
    respond(500, [
        'success'=>false,
        'error'=>'Erro na conversão',
        'debug'=>$convOut
    ]);
}

// ================= VERIFICA TAMANHO =================
$finalSize = filesize($outFile);

if ($finalSize > $maxOutputBytes) {

    @unlink($outFile);

    $targetBits = $maxOutputBytes * 8;
    $newBitrate = intval(($targetBits / $duration) / 1000);

    if ($newBitrate < 300) $newBitrate = 300;

    $cmd = "$ffmpegBin -y -i " . escapeshellarg($inFile) .
           " $cutOption" .
           " -c:v libx264 -preset veryfast -b:v {$newBitrate}k" .
           " -c:a aac -b:a 96k -movflags +faststart " .
           escapeshellarg($outFile);

    exec($cmd . " 2>&1", $convOut, $convCode);

    if ($convCode !== 0) {
        @unlink($inFile);
        respond(500, [
            'success'=>false,
            'error'=>'Erro ao ajustar tamanho',
            'debug'=>$convOut
        ]);
    }
}

@unlink($inFile);

// ================= RESPONSE =================
respond(200, [
    'success' => true,
    'file' => basename($outFile),
    'public_url' => $publicBaseUrl . basename($outFile),
    'duration_seconds' => $duration,
    'size_bytes' => filesize($outFile)
]);