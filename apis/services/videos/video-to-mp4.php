<?php
header('Content-Type: application/json; charset=utf-8');

// ================= CONFIG =================
$ffmpegBin  = 'ffmpeg';
$ffprobeBin = 'ffprobe';

$baseDir = rtrim($_SERVER['DOCUMENT_ROOT'], '/');
$tempDir = $baseDir . '/temp/';
$outDir  = $baseDir . '/runtime/videos/';
$publicBaseUrl = 'https://services.urbanna.com.br/runtime/videos/';

$maxDownloadBytes = 500 * 1024 * 1024; // 500MB

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
    CURLOPT_TIMEOUT => 120,
    CURLOPT_SSL_VERIFYPEER => true
]);

curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
fclose($fp);

if ($http < 200 || $http >= 300) {
    unlink($inFile);
    respond(400, ['success'=>false,'error'=>'Falha no download']);
}

// ================= ANALISA =================
$ext = strtolower(pathinfo($inFile, PATHINFO_EXTENSION));
$needsConversion = true;

if ($ext === 'mp4') {
    $codec = getCodecInfo($ffprobeBin, $inFile);

    if ($codec['video'] === 'h264' && $codec['audio'] === 'aac') {
        $needsConversion = false;
    }
}

// ================= PROCESSA =================
if ($needsConversion) {

    $cmd = "$ffmpegBin -y -i " . escapeshellarg($inFile) .
           " -c:v libx264 -preset veryfast -crf 23" .
           " -c:a aac -b:a 128k -movflags +faststart " .
           escapeshellarg($outFile);

    exec($cmd . " 2>&1", $convOut, $convCode);

    if ($convCode !== 0) {
        unlink($inFile);
        respond(500, [
            'success'=>false,
            'error'=>'Erro na conversão',
            'debug'=>$convOut
        ]);
    }

    unlink($inFile);

} else {
    // Apenas move
    rename($inFile, $outFile);
}

// ================= RESPONSE =================
respond(200, [
    'success' => true,
    'converted' => $needsConversion,
    'file' => basename($outFile),
    'public_url' => $publicBaseUrl . basename($outFile),
    'size_bytes' => filesize($outFile)
]);