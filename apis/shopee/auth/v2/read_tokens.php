<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bootstrap.php';
header('Content-Type: application/json');

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

$shopId = $data['shop_id'];

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

$stmt = $conn->prepare("SELECT partner_id, partner_key, host, access_token FROM lojaur05_tagplus.apikey_shopee WHERE shop_id = ?");
$stmt->bind_param("s", $shopId);
$stmt->execute();
$stmt->bind_result($partner_id, $partner_key, $host, $access_token);
$stmt->fetch();
$stmt->close();

if (!$partner_id || !$partner_key || !$host || !$access_token) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(400) ;
    echo json_encode([
        'error' => "Nenhum partner_id ou partner_key ou host encontrado para o shop_id: $shopId"]);
    exit;
}

echo json_encode([
        'partner_id' => $partner_id,
        'partner_key' => $partner_key,
        'host' => $host,
        'access_token' => $access_token
    ]);

return json_encode([
    'partner_id' => $partner_id,
    'partner_key' => $partner_key,
    'host' => $host,
    'access_token' => $access_token
]);