<?php
declare(strict_types=1);

// PHP 7.4+ com pdo_mysql. Preencha os dados abaixo ou use as variáveis de ambiente.
// A tabela existente deve aceitar id textual (VARCHAR de pelo menos 15 caracteres).
// Não altera a tabela e não processa o evento: somente grava como pendente.
$config = [
    'host' => getenv('WEBHOOK_DB_HOST') ?: '148.230.72.178',
    'port' => getenv('WEBHOOK_DB_PORT') ?: '3306',
    'user' => getenv('URBANNA_DB_USER') ?: getenv('WEBHOOK_DB_USER') ?: 'lojaur05_admin',
    'password' => getenv('URBANNA_DB_PASS') ?: getenv('WEBHOOK_DB_PASSWORD') ?: 'M2emsvjmt*20',
];

ini_set('display_errors', '0');
set_time_limit(15); // Não substitui timeouts de rede do servidor/driver.
header('Content-Type: application/json; charset=utf-8');
$requestId = bin2hex(random_bytes(16));

function respond(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    respond(405, ['status' => 'error', 'message' => 'Use POST.']);
}
$origem = $_GET['origem'] ?? '';
$tipoEvento = $_GET['tipo_evento'] ?? '';
// Limita leitura a 1 MB + 1 byte, sem carregar payload ilimitado.
$raw = file_get_contents('php://input', false, null, 0, 1048577);
if ($raw !== false && strlen($raw) > 1048576) {
    respond(413, ['status' => 'error', 'message' => 'Body excede 1 MB.', 'request_id' => $requestId]);
}
if (!is_string($origem) || !is_string($tipoEvento) || trim($origem) === '' ||
    trim($tipoEvento) === '' || $raw === false || trim($raw) === '') {
    respond(400, ['status' => 'error', 'message' => 'Parâmetros origem e tipo_evento são obrigatórios e o body não pode estar vazio.']);
}

// Normaliza JSON como o n8n; aceita também POST de formulário.
$contentType = strtolower(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]);
if ($contentType === 'application/json' || substr($contentType, -5) === '+json') {
    $decoded = json_decode($raw);
    if (json_last_error() !== JSON_ERROR_NONE) {
        respond(400, ['status' => 'error', 'message' => 'Body contém JSON inválido.']);
    }
    $data = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} elseif ($contentType === 'application/x-www-form-urlencoded') {
    parse_str($raw, $decoded);
    $data = json_encode((object) $decoded, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} else {
    // Texto bruto vira uma string JSON, como JSON.stringify(body).
    $data = json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

$headers = [];
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HTTP_') === 0) {
        $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = $value;
    }
}
foreach (['CONTENT_TYPE', 'CONTENT_LENGTH'] as $key) {
    if (isset($_SERVER[$key])) {
        $headers[strtolower(str_replace('_', '-', $key))] = $_SERVER[$key];
    }
}

// Mantém formato timestamp-0000; o sufixo agora é aleatório.
// UNIQUE/PRIMARY KEY em id é necessário para detectar colisões.
$id = time() . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);
try {
    for ($connectionAttempt = 1; $connectionAttempt <= 2; $connectionAttempt++) {
      try {
        $pdo = new PDO(
        'mysql:host=' . $config['host'] . ';port=' . $config['port'] .
        ';dbname=lojaur05_webhooks;charset=utf8mb4',
        $config['user'],
        $config['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => false,
         PDO::ATTR_PERSISTENT => false,
         PDO::ATTR_TIMEOUT => 3]
        );
        break;
      } catch (PDOException $connectionError) {
        $dbCode = (int) ($connectionError->errorInfo[1] ?? 0);
        if ($connectionAttempt === 2 || !in_array($dbCode, [1040, 1203, 2002, 2003, 2006, 2013], true)) {
            throw $connectionError;
        }
        usleep(300000);
      }
    }
    // Limita espera de bloqueios InnoDB; não limita todo tipo de espera de rede.
    $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
    $stmt = $pdo->prepare(
        'INSERT INTO lojaur05_webhooks.webhooks_geral ' .
        '(id, processado, tipo_evento, origem, headers, data, recebido_em) ' .
        'VALUES (?, 0, ?, ?, ?, ?, NOW())'
    );
    for ($insertAttempt = 1; $insertAttempt <= 3; $insertAttempt++) {
        try {
            $stmt->execute([$id, $tipoEvento, $origem,
                json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE), $data]);
            break;
        } catch (PDOException $insertError) {
            $dbCode = (int) ($insertError->errorInfo[1] ?? 0);
            // Deadlock/lock timeout: statement falhou; pode repetir em autocommit.
            // Perda de conexão após execute é ambígua: NÃO repete INSERT.
            if ($insertAttempt === 3 || !in_array($dbCode, [1205, 1213], true)) {
                throw $insertError;
            }
            usleep(300000);
        }
    }
    respond(200, ['status' => 'success', 'id' => $id, 'request_id' => $requestId]);
} catch (Throwable $e) {
    // Não repete INSERT após timeout: a gravação pode ter ocorrido antes da falha.
    error_log('[geral_webhooks ' . $requestId . '] ' . $e->getMessage());
    $dbCode = $e instanceof PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
    $temporary = in_array($dbCode, [1040, 1203, 1205, 1213, 2002, 2003, 2006, 2013], true) || preg_match('/too many connections|max_user_connections|timeout|timed out|deadlock|lock wait|server has gone away|lost connection|connection refused|could not connect/i', $e->getMessage());
    $code = $temporary ? 503 : 500;
    if ($code === 503) {
        header('Retry-After: 2');
    }
    respond($code, ['status' => 'error',
        'message' => $code === 503 ? 'Banco temporariamente indisponível.' : 'Falha ao gravar webhook.',
        'retry_after_seconds' => $code === 503 ? 2 : null,
        'request_id' => $requestId]);
}
