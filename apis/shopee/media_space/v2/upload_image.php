<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bootstrap.php';
$api_path = '/api/v2/media_space/upload_image';

// =================== VALIDAÇÃO DA REQUISIÇÃO ===================

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

// Valida image_url
if (!isset($data['image_url'])) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'error' => 'Parametro image_url obrigatório'
    ]);
    exit;
}

$shopId = $data['shop_id'];
$image_url = $data['image_url'];

// =================== RECUPERA ACCESS TOKEN ===================
$tokens = require BASE_PATH . '/apis/shopee/auth/v2/read_tokens.php';

$access_token = $tokens['access_token'];
$partner_id   = $tokens['partner_id'];
$partner_key  = $tokens['partner_key'];
$host         = $tokens['host'];

// =================== VALIDA RETORNO ===================
if (!isset($access_token)) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400);
    echo json_encode([
        'error' => 'Access token não encontrado para o shop_id: ' . $shopId
    ]);
    exit;
}

// =================== DECLARA VARIÁVEIS ===================
$timestamp = time();
$base_string = $partner_id . $api_path . $timestamp;
$sign = hash_hmac(
    'sha256',
    $base_string,
    $partner_key
);

$params_url = "?partner_id=" . $partner_id . "&timestamp=" . $timestamp . "&sign=" . $sign;
$request_url = $host . $api_path . $params_url;

// ======================================
// ENVIAR REQUISIÇÃO DE UPLOAD DE IMAGEM
// ======================================

// CRIA DIRETÓRIOS TEMPORÁRIOS
$baseTmp = sys_get_temp_dir() . '/shopee';

$inputDir  = $baseTmp . '/input';
$outputDir = $baseTmp . '/output';

@mkdir($inputDir, 0777, true);
@mkdir($outputDir, 0777, true);

// BAIXA ARQUIVO PARA SERVIDOR
$originalFile = $inputDir . '/' . uniqid('file_');

$fp = fopen($originalFile, 'w');

$ch = curl_init($image_url);
curl_setopt_array($ch, [
    CURLOPT_FILE => $fp,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 120
]);

curl_exec($ch);
curl_close($ch);
fclose($fp);

if (!file_exists($originalFile) || filesize($originalFile) === 0) {
    throw new Exception('Falha ao baixar arquivo');
}

// IDENTIFICA TIPO DE ARQUIVO
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $originalFile);
finfo_close($finfo);

// CONVERTE IMAGEM PARA JPEG SE NECESSÁRIO
$finalFile = $originalFile;

if (str_starts_with($mime, 'image/') && $mime !== 'image/jpeg') {

    $image = match ($mime) {
        'image/png'  => imagecreatefrompng($originalFile),
        'image/webp' => imagecreatefromwebp($originalFile),
        default      => null
    };

    if (!$image) {
        throw new Exception('Formato de imagem não suportado');
    }

    $finalFile = $outputDir . '/' . uniqid('img_') . '.jpg';

    imagejpeg($image, $finalFile, 90);
    imagedestroy($image);

    unlink($originalFile); // apaga original
}

// MONTAR MULTIPART
$fileForRequest = new CURLFile(
    $finalFile,
    mime_content_type($finalFile),
    basename($finalFile)
);

// ENVIAR REQUISIÇÃO
$postFields = [
    'file' => $fileForRequest
];

$ch = curl_init($request_url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_RETURNTRANSFER => true,    
]);

$response = curl_exec($ch);

if ($response === false) {
    throw new Exception('cURL error: ' . curl_error($ch));
}

curl_close($ch);

echo $response;

// APAGAR ARQUIVO
if (file_exists($finalFile)) {
    unlink($finalFile);
}


echo json_encode([
        'partner_id' => $partner_id,
        'partner_key' => $partner_key,
        'host' => $host,
        'access_token' => $access_token
    ]);