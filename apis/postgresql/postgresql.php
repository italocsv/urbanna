<?php

// ============================================================
//  CONFIGURACOES
// ============================================================

define('API_KEY', 'vrmmoRdeZEzqncnbvgrU1bo7gwTej4nu');
define('MAX_RETRIES', 5);
define('RETRY_DELAY_MS', 500);
define('READ_ONLY', false);

$DATABASES = array(
    'urbanna_postgre' => array(
        'host' => 'i8kow444gss4gwosogkscg4o',
        'port' => '5432',
        'user' => 'postgres',
        'pass' => 'LeZMUTSHH8xpKoOCbsH34qtjYnTKIHl72ecZNOR9c6n4HupT6lLikN0vPNAsegFr',
        'name' => 'postgres',
    ),
);

// ============================================================

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Api-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function respond($code, $payload)
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function apierror($code, $message, $extra = array())
{
    respond($code, array_merge(array('success' => false, 'error' => $message), $extra));
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    apierror(405, 'Metodo nao permitido. Use POST.');
}

if (API_KEY !== '') {
    $receivedKey = isset($_SERVER['HTTP_X_API_KEY']) ? $_SERVER['HTTP_X_API_KEY'] : '';
    if (!hash_equals(API_KEY, $receivedKey)) {
        apierror(401, 'Chave de API invalida ou ausente.');
    }
}

$raw = file_get_contents('php://input');
if (empty($raw)) {
    apierror(400, 'Body vazio. Envie JSON com a chave "query".');
}

$body = json_decode($raw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    apierror(400, 'JSON invalido: ' . json_last_error_msg());
}

$query = trim(isset($body['query']) ? $body['query'] : '');
if ($query === '') {
    apierror(400, 'Campo "query" ausente ou vazio.');
}

global $DATABASES;
$dbKey = trim(isset($body['database']) ? $body['database'] : '');

if ($dbKey === '') {
    reset($DATABASES);
    $dbKey = key($DATABASES);
}

if (!isset($DATABASES[$dbKey])) {
    $available = implode(', ', array_keys($DATABASES));
    apierror(400, 'Banco "' . $dbKey . '" nao encontrado. Disponiveis: ' . $available);
}

$cfg = $DATABASES[$dbKey];

if (READ_ONLY) {
    $firstWord = strtoupper(strtok($query, " \t\n\r"));
    $allowed = array('SELECT', 'SHOW', 'DESCRIBE', 'EXPLAIN');
    if (!in_array($firstWord, $allowed, true)) {
        apierror(403, 'Modo somente-leitura: apenas SELECT/SHOW/DESCRIBE/EXPLAIN sao permitidos.');
    }
}

$pdo      = null;
$attempt  = 0;
$lastError = '';

while ($attempt < MAX_RETRIES) {
    $attempt++;

    try {
        $dsn = 'pgsql:host=' . $cfg['host'] . ';port=' . $cfg['port'] . ';dbname=' . $cfg['name'];
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5,
        ));
        break;

    } catch (PDOException $e) {
        $lastError = $e->getMessage();

        if (strpos($lastError, 'too many connections') !== false ||
            strpos($lastError, 'remaining connection slots') !== false) {
            $pdo = null;
            usleep(RETRY_DELAY_MS * 1000);
            continue;
        }

        apierror(500, 'Falha na conexao (tentativa ' . $attempt . '): ' . $lastError);
    }
}

if ($pdo === null) {
    apierror(503, 'Servidor ocupado apos ' . MAX_RETRIES . ' tentativas. Tente novamente.', array(
        'retries'    => MAX_RETRIES,
        'last_error' => $lastError,
    ));
}

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute();

} catch (PDOException $e) {
    apierror(400, 'Erro na query: ' . $e->getMessage());
}

$payload = array(
    'success'       => true,
    'database'      => $dbKey,
    'attempt'       => $attempt,
    'affected_rows' => $stmt->rowCount(),
    'insert_id'     => null,
);

// Tenta pegar o último insert_id (só funciona se a tabela tiver sequence/serial)
try {
    $payload['insert_id'] = $pdo->lastInsertId() ?: null;
} catch (Exception $e) {
    $payload['insert_id'] = null;
}

// SELECT retorna rows
$firstWord = strtoupper(strtok(trim($query), " \t\n\r"));
if (in_array($firstWord, array('SELECT', 'SHOW', 'EXPLAIN', 'WITH'), true)) {
    $rows = $stmt->fetchAll();
    $payload['rows']      = $rows;
    $payload['row_count'] = count($rows);
} else {
    $payload['rows']      = [];
    $payload['row_count'] = 0;
}

respond(200, $payload);