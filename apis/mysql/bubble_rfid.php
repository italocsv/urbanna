<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| URBANNA - RECEPTOR GERAL DE WEBHOOKS
|--------------------------------------------------------------------------
| APENAS ENQUANTO NÃO RESOLVO QUESTÃO RFID INVENTARIO DE OUTRA FORMA
| Exemplo:
| POST /apis/webhooks/geral.php?origem=bubble&tipo_evento=rfid_inventario
|
| Body:
| qualquer JSON válido
|
*/

// ==========================================================================
// CONFIGURAÇÃO
// ==========================================================================

const DB_HOST = '148.230.72.178';
const DB_PORT = 3306;
const DB_NAME = 'lojaur05_webhooks';

const DB_CONNECT_TIMEOUT_SECONDS = 3;
const INNODB_LOCK_WAIT_TIMEOUT = 5;
const PHP_MAX_EXECUTION_SECONDS = 15;

const MAX_BODY_BYTES = 1048576; // 1 MB

$DB_USER = getenv('URBANNA_DB_USER') ?: '';
$DB_PASS = getenv('URBANNA_DB_PASS') ?: '';


// ==========================================================================
// PHP
// ==========================================================================

@set_time_limit(PHP_MAX_EXECUTION_SECONDS);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

mysqli_report(MYSQLI_REPORT_OFF);


// ==========================================================================
// HEADERS
// ==========================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');


// ==========================================================================
// REQUEST ID
// ==========================================================================

try {

    $REQUEST_ID = bin2hex(random_bytes(8));

} catch (Throwable $e) {

    $REQUEST_ID = uniqid('', true);
}

header('X-Request-Id: ' . $REQUEST_ID);


// ==========================================================================
// FUNÇÕES
// ==========================================================================

function respond(
    int $statusCode,
    array $payload
): never {

    global $REQUEST_ID;

    $payload['request_id'] ??= $REQUEST_ID;

    http_response_code($statusCode);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


function logWebhook(string $message): void
{
    global $REQUEST_ID;

    error_log(
        '[URBANNA-WEBHOOK]' .
        '[request=' . $REQUEST_ID . '] ' .
        $message
    );
}


// ==========================================================================
// OPTIONS
// ==========================================================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS'
) {

    http_response_code(204);
    exit;
}


// ==========================================================================
// SOMENTE POST
// ==========================================================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {

    header('Allow: POST');

    respond(
        405,
        [
            'success' => false,
            'error'   => 'Metodo nao permitido. Use POST.'
        ]
    );
}


// ==========================================================================
// CREDENCIAIS MYSQL
// ==========================================================================

if (
    $DB_USER === '' ||
    $DB_PASS === ''
) {

    logWebhook(
        'CONFIG_ERROR credenciais MySQL ausentes'
    );

    respond(
        500,
        [
            'success' => false,
            'error'   => 'Servico nao configurado corretamente.'
        ]
    );
}


// ==========================================================================
// ORIGEM / TIPO EVENTO
// ==========================================================================

$origem = trim(
    (string)($_GET['origem'] ?? '')
);

$tipoEvento = trim(
    (string)($_GET['tipo_evento'] ?? '')
);


if ($origem === '') {

    respond(
        400,
        [
            'success' => false,
            'error'   => 'Parametro "origem" ausente.'
        ]
    );
}


if ($tipoEvento === '') {

    respond(
        400,
        [
            'success' => false,
            'error'   => 'Parametro "tipo_evento" ausente.'
        ]
    );
}


// ==========================================================================
// TAMANHO BODY
// ==========================================================================

$contentLength =
    isset($_SERVER['CONTENT_LENGTH'])
        ? (int)$_SERVER['CONTENT_LENGTH']
        : 0;


if ($contentLength > MAX_BODY_BYTES) {

    respond(
        413,
        [
            'success' => false,
            'error'   => 'Body excede o tamanho permitido.'
        ]
    );
}


// ==========================================================================
// BODY
// ==========================================================================

$rawBody = file_get_contents(
    'php://input',
    false,
    null,
    0,
    MAX_BODY_BYTES + 1
);


if (
    $rawBody === false ||
    trim($rawBody) === ''
) {

    respond(
        400,
        [
            'success' => false,
            'error'   => 'Body vazio.'
        ]
    );
}


if (strlen($rawBody) > MAX_BODY_BYTES) {

    respond(
        413,
        [
            'success' => false,
            'error'   => 'Body excede o tamanho permitido.'
        ]
    );
}


// ==========================================================================
// VALIDA JSON
// ==========================================================================

$data = json_decode(
    $rawBody,
    true
);


if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($data)
) {

    respond(
        400,
        [
            'success' => false,
            'error'   => 'JSON invalido.',
            'json_error' => json_last_error_msg()
        ]
    );
}


// ==========================================================================
// NORMALIZA JSON
// ==========================================================================

$payload = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_INVALID_UTF8_SUBSTITUTE
);


if ($payload === false) {

    respond(
        500,
        [
            'success' => false,
            'error'   => 'Falha ao preparar payload.'
        ]
    );
}


// ==========================================================================
// HEADERS DA REQUISIÇÃO
// ==========================================================================

$requestHeaders =
    function_exists('getallheaders')
        ? getallheaders()
        : [];


$headersJson = json_encode(
    $requestHeaders,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_INVALID_UTF8_SUBSTITUTE
);


if ($headersJson === false) {
    $headersJson = '{}';
}


// ==========================================================================
// ID ÚNICO
// ==========================================================================

try {

    $webhookId =
        time() .
        '-' .
        random_int(100000, 999999);

} catch (Throwable $e) {

    $webhookId =
        time() .
        '-' .
        mt_rand(100000, 999999);
}


// ==========================================================================
// MYSQL
// ==========================================================================

$conn = mysqli_init();


if (!$conn) {

    respond(
        503,
        [
            'success' => false,
            'error'   => 'Nao foi possivel inicializar MySQL.'
        ]
    );
}


@$conn->options(
    MYSQLI_OPT_CONNECT_TIMEOUT,
    DB_CONNECT_TIMEOUT_SECONDS
);


$connected = @$conn->real_connect(
    DB_HOST,
    $DB_USER,
    $DB_PASS,
    DB_NAME,
    DB_PORT
);


if (!$connected) {

    logWebhook(
        'MYSQL_CONNECTION_ERROR errno=' .
        (int)$conn->connect_errno
    );

    @$conn->close();

    header('Retry-After: 2');

    respond(
        503,
        [
            'success' => false,
            'error'   => 'Banco temporariamente indisponivel.',
            'retry_after_seconds' => 2
        ]
    );
}


if (!@$conn->set_charset('utf8mb4')) {

    logWebhook('MYSQL_CHARSET_ERROR');

    @$conn->close();

    respond(
        500,
        [
            'success' => false,
            'error'   => 'Falha ao configurar conexao.'
        ]
    );
}


@$conn->query(
    'SET SESSION innodb_lock_wait_timeout = ' .
    (int)INNODB_LOCK_WAIT_TIMEOUT
);


// ==========================================================================
// INSERT
// ==========================================================================

$sql = '
    INSERT INTO webhooks_geral
    (
        id,
        processado,
        tipo_evento,
        origem,
        headers,
        data,
        recebido_em
    )
    VALUES
    (
        ?,
        0,
        ?,
        ?,
        ?,
        ?,
        NOW()
    )
';


$stmt = @$conn->prepare($sql);


if (!$stmt) {

    logWebhook(
        'PREPARE_ERROR errno=' .
        (int)$conn->errno
    );

    @$conn->close();

    respond(
        500,
        [
            'success' => false,
            'error'   => 'Falha ao preparar gravacao.'
        ]
    );
}


$stmt->bind_param(
    'sssss',
    $webhookId,
    $tipoEvento,
    $origem,
    $headersJson,
    $payload
);


$inserted = @$stmt->execute();


if (!$inserted) {

    $mysqlCode = (int)$stmt->errno;

    logWebhook(
        'INSERT_ERROR errno=' .
        $mysqlCode
    );

    @$stmt->close();
    @$conn->close();


    // Deadlock / lock timeout / conexão perdida
    if (
        in_array(
            $mysqlCode,
            [1205, 1213, 2006, 2013],
            true
        )
    ) {

        header('Retry-After: 2');

        respond(
            503,
            [
                'success' => false,
                'error'   => 'Banco temporariamente ocupado.',
                'retry_after_seconds' => 2
            ]
        );
    }


    respond(
        500,
        [
            'success' => false,
            'error'   => 'Falha ao gravar webhook.'
        ]
    );
}


// ==========================================================================
// FINALIZA
// ==========================================================================

@$stmt->close();
@$conn->close();


// ==========================================================================
// RETORNO
// ==========================================================================

respond(
    200,
    [
        'success'     => true,
        'status'      => 'received',
        'id'          => $webhookId,
        'tipo_evento' => $tipoEvento,
        'origem'      => $origem
    ]
);