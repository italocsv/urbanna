<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| URBANNA SQL API — VPS
|--------------------------------------------------------------------------
|
| Endpoint HTTP protegido para execução de SQL no MySQL.
|
| Objetivos:
| - Não deixar requisições acumularem.
| - Não fazer retry interno.
| - Limitar concorrência.
| - Limitar conexão e execução.
| - Limitar tamanho do request.
| - Limitar quantidade de registros retornados.
| - Evitar queries conhecidamente perigosas.
| - Registrar erros e queries lentas.
| - Retornar 503 rapidamente em problemas temporários.
| - Manter contrato JSON previsível para n8n / UiPath.
|
*/


// ==========================================================================
// CONFIGURAÇÃO
// ==========================================================================

// --------------------------------------------------------------------------
// SEGREDOS
// --------------------------------------------------------------------------
// Configure no ambiente:
//
// URBANNA_SQL_API_KEY
// URBANNA_DB_USER
// URBANNA_DB_PASS
//
// NÃO coloque senha diretamente neste arquivo.
// --------------------------------------------------------------------------

$API_KEY = getenv('URBANNA_SQL_API_KEY') ?: '';
$DB_USER = getenv('URBANNA_DB_USER') ?: '';
$DB_PASS = getenv('URBANNA_DB_PASS') ?: '';


// --------------------------------------------------------------------------
// MYSQL
// --------------------------------------------------------------------------

const DB_HOST = '148.230.72.178';
const DB_PORT = 3306;


// --------------------------------------------------------------------------
// SEGURANÇA
// --------------------------------------------------------------------------

// false mantém compatibilidade com seu sistema atual:
// SELECT / INSERT / UPDATE / DELETE.
//
// Se quiser transformar essa API em somente leitura:
// true
const READ_ONLY = false;


// --------------------------------------------------------------------------
// LIMITES
// --------------------------------------------------------------------------

// Mesmo que PHP-FPM aceite mais requisições,
// somente esta quantidade poderá executar SQL simultaneamente.
const MAX_CONCURRENT_REQUESTS = 5;

// Conexão MySQL.
const DB_CONNECT_TIMEOUT_SECONDS = 3;

// Query.
const QUERY_TIMEOUT_SECONDS = 10;

// Espera por lock do InnoDB.
const INNODB_LOCK_WAIT_TIMEOUT = 5;

// Resultado máximo.
//
// Recomendo paginação para resultados maiores.
const MAX_RESULT_ROWS = 1000;

// Query acima disso entra no log.
const SLOW_QUERY_MS = 1500;

// Limite geral do PHP.
const PHP_MAX_EXECUTION_SECONDS = 20;

// Body HTTP: 1 MB.
const MAX_BODY_BYTES = 1048576;

// Tamanho máximo da própria query SQL: 256 KB.
const MAX_QUERY_BYTES = 262144;


// ==========================================================================
// BANCOS PERMITIDOS
// ==========================================================================

$DATABASES = [

    'lojaur05_tagplus',

    'lojaur05_webhooks',

    'lojaur05_ecommerce',

    'lojaur05_app_midias_produtos',

    'lojaur05_app_crm',

    'lojaur05_marketing',

];


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

header('X-Content-Type-Options: nosniff');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

header('Pragma: no-cache');


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
// VARIÁVEIS DE CONTROLE
// ==========================================================================

$concurrencyHandle = null;
$concurrencySlot   = null;
$conn              = null;


// ==========================================================================
// FUNÇÕES
// ==========================================================================

function respond(int $code, array $payload): never
{
    global $REQUEST_ID;

    if (!isset($payload['request_id'])) {
        $payload['request_id'] = $REQUEST_ID;
    }

    http_response_code($code);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}


function apiError(
    int $code,
    string $message,
    array $extra = []
): never {

    respond(
        $code,
        array_merge(
            [
                'success' => false,
                'error'   => $message,
            ],
            $extra
        )
    );
}


function elapsedMs(float $start): float
{
    return round(
        (microtime(true) - $start) * 1000,
        2
    );
}


function logMessage(string $message): void
{
    global $REQUEST_ID;

    error_log(
        '[URBANNA-SQL-API]' .
        '[request=' . $REQUEST_ID . '] ' .
        $message
    );
}


function closeConnection(?mysqli $conn): void
{
    if ($conn instanceof mysqli) {

        try {
            @$conn->close();
        } catch (Throwable $e) {
            // Nada a fazer.
        }

    }
}


function releaseConcurrency(): void
{
    global $concurrencyHandle;

    if (is_resource($concurrencyHandle)) {

        @flock(
            $concurrencyHandle,
            LOCK_UN
        );

        @fclose(
            $concurrencyHandle
        );

    }

    $concurrencyHandle = null;
}


function shutdownCleanup(): void
{
    global $conn;

    if ($conn instanceof mysqli) {
        closeConnection($conn);
    }

    releaseConcurrency();
}


function logSlowQuery(
    string $database,
    string $query,
    float $durationMs
): void {

    /*
     * Não registra a query completa.
     *
     * Mantemos somente pequeno preview + hash.
     */

    $normalized = preg_replace(
        '/\s+/',
        ' ',
        trim($query)
    );

    $preview = substr(
        (string)$normalized,
        0,
        250
    );

    $hash = hash(
        'sha256',
        $query
    );

    logMessage(
        'SLOW_QUERY ' .
        'db=' . $database .
        ' duration_ms=' . $durationMs .
        ' hash=' . substr($hash, 0, 16) .
        ' preview=' . $preview
    );
}


function getFirstSqlWord(string $query): string
{
    $clean = ltrim($query);

    /*
     * Remove comentários iniciais.
     */

    $clean = preg_replace(
        '/^(?:\s*(?:--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$)|\/\*.*?\*\/))+/s',
        '',
        $clean
    );

    if ($clean === null) {
        return '';
    }

    $token = strtok(
        ltrim($clean),
        " \t\n\r("
    );

    if ($token === false) {
        return '';
    }

    return strtoupper($token);
}


function isTemporaryMysqlError(
    int $errno,
    string $error
): bool {

    /*
     * Alguns códigos comuns:
     *
     * 1040 Too many connections
     * 1205 Lock wait timeout
     * 1213 Deadlock
     * 2002 Connection error
     * 2006 Server gone away
     * 2013 Lost connection
     */

    $temporaryCodes = [
        1040,
        1205,
        1213,
        2002,
        2006,
        2013,
    ];

    if (in_array($errno, $temporaryCodes, true)) {
        return true;
    }

    $error = strtolower($error);

    $patterns = [

        'too many connections',

        'max_user_connections',

        'lock wait timeout',

        'deadlock',

        'server has gone away',

        'lost connection',

        'maximum statement execution time',

        'query execution was interrupted',

        'connection refused',

        'timed out',

    ];

    foreach ($patterns as $pattern) {

        if (str_contains($error, $pattern)) {
            return true;
        }

    }

    return false;
}


// ==========================================================================
// CLEANUP GARANTIDO
// ==========================================================================

register_shutdown_function(
    'shutdownCleanup'
);


// ==========================================================================
// MÉTODO HTTP
// ==========================================================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    header('Allow: POST');

    apiError(
        405,
        'Metodo nao permitido. Use POST.'
    );

}


// ==========================================================================
// AUTENTICAÇÃO
// ==========================================================================

if ($API_KEY === '') {

    logMessage(
        'CONFIG_ERROR API_KEY ausente'
    );

    apiError(
        500,
        'API nao configurada corretamente.'
    );

}


$receivedKey = isset($_SERVER['HTTP_X_API_KEY'])
    ? trim((string)$_SERVER['HTTP_X_API_KEY'])
    : '';


if (
    $receivedKey === '' ||
    !hash_equals($API_KEY, $receivedKey)
) {

    /*
     * Pequeno atraso dificulta brute force sem
     * prejudicar significativamente uso normal.
     */

    usleep(100000);

    apiError(
        401,
        'Chave de API invalida ou ausente.'
    );

}


// ==========================================================================
// CONFIGURAÇÃO MYSQL
// ==========================================================================

if (
    $DB_USER === '' ||
    $DB_PASS === ''
) {

    logMessage(
        'CONFIG_ERROR credenciais MySQL ausentes'
    );

    apiError(
        500,
        'API nao configurada corretamente.'
    );

}


// ==========================================================================
// CONTROLE DE CONCORRÊNCIA
// ==========================================================================

$lockDirectory =
    sys_get_temp_dir() .
    '/urbanna_sql_api_locks';


if (!is_dir($lockDirectory)) {

    if (
        !@mkdir(
            $lockDirectory,
            0700,
            true
        ) &&
        !is_dir($lockDirectory)
    ) {

        logMessage(
            'LOCK_DIRECTORY_ERROR'
        );

        apiError(
            500,
            'Falha interna no controle de concorrencia.'
        );

    }

}


for (
    $i = 1;
    $i <= MAX_CONCURRENT_REQUESTS;
    $i++
) {

    $lockFile =
        $lockDirectory .
        '/slot_' .
        $i .
        '.lock';


    $handle = @fopen(
        $lockFile,
        'c'
    );


    if ($handle === false) {
        continue;
    }


    if (
        @flock(
            $handle,
            LOCK_EX | LOCK_NB
        )
    ) {

        $concurrencyHandle = $handle;

        $concurrencySlot = $i;

        break;

    }


    @fclose($handle);

}


if ($concurrencyHandle === null) {

    header(
        'Retry-After: 2'
    );

    apiError(
        503,
        'Servidor ocupado. Limite interno de consultas simultaneas atingido.',
        [
            'retry_after_seconds' => 2
        ]
    );

}


// ==========================================================================
// TAMANHO DO BODY
// ==========================================================================

$contentLength =
    isset($_SERVER['CONTENT_LENGTH'])
        ? (int)$_SERVER['CONTENT_LENGTH']
        : 0;


if (
    $contentLength > MAX_BODY_BYTES
) {

    apiError(
        413,
        'Body da requisicao excede o tamanho permitido.'
    );

}


// ==========================================================================
// BODY
// ==========================================================================

$raw = file_get_contents(
    'php://input',
    false,
    null,
    0,
    MAX_BODY_BYTES + 1
);


if (
    $raw === false ||
    $raw === ''
) {

    apiError(
        400,
        'Body vazio.'
    );

}


if (
    strlen($raw) > MAX_BODY_BYTES
) {

    apiError(
        413,
        'Body da requisicao excede o tamanho permitido.'
    );

}


$body = json_decode(
    $raw,
    true
);


if (
    json_last_error() !== JSON_ERROR_NONE ||
    !is_array($body)
) {

    apiError(
        400,
        'JSON invalido: ' .
        json_last_error_msg()
    );

}


// ==========================================================================
// QUERY
// ==========================================================================

$query =
    isset($body['query'])
        ? trim((string)$body['query'])
        : '';


if ($query === '') {

    apiError(
        400,
        'Campo "query" ausente ou vazio.'
    );

}


if (
    strlen($query) > MAX_QUERY_BYTES
) {

    apiError(
        413,
        'Query excede o tamanho maximo permitido.'
    );

}


// ==========================================================================
// DATABASE
// ==========================================================================

$dbKey =
    isset($body['database'])
        ? trim((string)$body['database'])
        : '';


if ($dbKey === '') {

    $dbKey = $DATABASES[0];

}


if (
    !in_array(
        $dbKey,
        $DATABASES,
        true
    )
) {

    apiError(
        400,
        'Banco nao permitido.',
        [
            'available_databases' => $DATABASES
        ]
    );

}


// ==========================================================================
// IDENTIFICA COMANDO
// ==========================================================================

$firstWord =
    getFirstSqlWord(
        $query
    );


if ($firstWord === '') {

    apiError(
        400,
        'Nao foi possivel identificar o comando SQL.'
    );

}


// ==========================================================================
// COMANDOS COMPLETAMENTE BLOQUEADOS
// ==========================================================================

$blockedCommands = [

    'DROP',

    'TRUNCATE',

    'ALTER',

    'CREATE',

    'RENAME',

    'GRANT',

    'REVOKE',

    'LOCK',

    'UNLOCK',

    'KILL',

    'SHUTDOWN',

];


if (
    in_array(
        $firstWord,
        $blockedCommands,
        true
    )
) {

    apiError(
        403,
        'Comando SQL nao permitido por esta API.'
    );

}


// ==========================================================================
// READ ONLY
// ==========================================================================

if (READ_ONLY) {

    $readCommands = [

        'SELECT',

        'SHOW',

        'DESCRIBE',

        'DESC',

        'EXPLAIN',

    ];


    if (
        !in_array(
            $firstWord,
            $readCommands,
            true
        )
    ) {

        apiError(
            403,
            'API configurada em modo somente leitura.'
        );

    }

}


// ==========================================================================
// PADRÕES PERIGOSOS
// ==========================================================================

$blockedPatterns = [

    '/\bSLEEP\s*\(/i',

    '/\bBENCHMARK\s*\(/i',

    '/\bGET_LOCK\s*\(/i',

    '/\bRELEASE_LOCK\s*\(/i',

    '/\bIS_FREE_LOCK\s*\(/i',

    '/\bIS_USED_LOCK\s*\(/i',

    '/\bLOAD_FILE\s*\(/i',

    '/\bINTO\s+OUTFILE\b/i',

    '/\bINTO\s+DUMPFILE\b/i',

    '/\bLOAD\s+DATA\b/i',

];


foreach (
    $blockedPatterns as $pattern
) {

    if (
        preg_match(
            $pattern,
            $query
        )
    ) {

        apiError(
            403,
            'Comando SQL bloqueado por seguranca.'
        );

    }

}


// ==========================================================================
// MYSQL INIT
// ==========================================================================

$connectionStarted =
    microtime(true);


$conn = mysqli_init();


if (!$conn) {

    apiError(
        503,
        'Nao foi possivel inicializar conexao MySQL.'
    );

}


// ==========================================================================
// TIMEOUT CONEXÃO
// ==========================================================================

@$conn->options(
    MYSQLI_OPT_CONNECT_TIMEOUT,
    DB_CONNECT_TIMEOUT_SECONDS
);


if (
    defined(
        'MYSQLI_OPT_READ_TIMEOUT'
    )
) {

    @$conn->options(
        MYSQLI_OPT_READ_TIMEOUT,
        QUERY_TIMEOUT_SECONDS + 2
    );

}


// ==========================================================================
// CONECTA
// ==========================================================================

$connected = @$conn->real_connect(

    DB_HOST,

    $DB_USER,

    $DB_PASS,

    $dbKey,

    DB_PORT

);


if (!$connected) {

    $mysqlError =
        (string)$conn->connect_error;

    $mysqlCode =
        (int)$conn->connect_errno;


    logMessage(
        'MYSQL_CONNECTION_ERROR ' .
        'db=' . $dbKey .
        ' errno=' . $mysqlCode .
        ' error=' . $mysqlError
    );


    closeConnection(
        $conn
    );

    $conn = null;


    header(
        'Retry-After: 2'
    );


    apiError(
        503,
        'Nao foi possivel conectar ao MySQL no momento.',
        [
            'retry_after_seconds' => 2
        ]
    );

}


$connectionMs =
    elapsedMs(
        $connectionStarted
    );


// ==========================================================================
// CHARSET
// ==========================================================================

if (
    !@$conn->set_charset(
        'utf8mb4'
    )
) {

    $mysqlError =
        (string)$conn->error;


    logMessage(
        'CHARSET_ERROR ' .
        'db=' . $dbKey .
        ' error=' . $mysqlError
    );


    apiError(
        500,
        'Falha ao configurar conexao MySQL.'
    );

}


// ==========================================================================
// LIMITES DA SESSÃO MYSQL
// ==========================================================================

@$conn->query(
    'SET SESSION innodb_lock_wait_timeout = ' .
    (int)INNODB_LOCK_WAIT_TIMEOUT
);


$serverInfo =
    (string)$conn->server_info;


$isMariaDB =
    stripos(
        $serverInfo,
        'MariaDB'
    ) !== false;


if ($isMariaDB) {

    @$conn->query(
        'SET SESSION max_statement_time = ' .
        (float)QUERY_TIMEOUT_SECONDS
    );

} else {

    /*
     * MySQL.
     *
     * MAX_EXECUTION_TIME protege principalmente SELECT.
     */

    @$conn->query(
        'SET SESSION MAX_EXECUTION_TIME = ' .
        ((int)QUERY_TIMEOUT_SECONDS * 1000)
    );

}


// ==========================================================================
// EXECUTA QUERY
// ==========================================================================

$queryStarted =
    microtime(true);


$executed =
    @$conn->real_query(
        $query
    );


$queryMs =
    elapsedMs(
        $queryStarted
    );


// ==========================================================================
// QUERY LENTA
// ==========================================================================

if (
    $queryMs >= SLOW_QUERY_MS
) {

    logSlowQuery(
        $dbKey,
        $query,
        $queryMs
    );

}


// ==========================================================================
// ERRO SQL
// ==========================================================================

if (!$executed) {

    $mysqlError =
        (string)$conn->error;

    $mysqlCode =
        (int)$conn->errno;


    logMessage(
        'QUERY_ERROR ' .
        'db=' . $dbKey .
        ' errno=' . $mysqlCode .
        ' duration_ms=' . $queryMs .
        ' error=' . $mysqlError
    );


    if (
        isTemporaryMysqlError(
            $mysqlCode,
            $mysqlError
        )
    ) {

        header(
            'Retry-After: 2'
        );


        apiError(
            503,
            'Consulta interrompida porque o servidor esta ocupado ou excedeu o tempo permitido.',
            [
                'database'            => $dbKey,
                'duration_ms'         => $queryMs,
                'retry_after_seconds' => 2,
                'rows'                => [],
                'row_count'           => 0,
                'affected_rows'       => 0,
                'insert_id'           => null
            ]
        );

    }


    /*
     * Não exponho mensagem completa do MySQL para clientes.
     *
     * O erro completo permanece no error_log.
     */

    apiError(
        400,
        'Erro ao executar a query.',
        [
            'database'      => $dbKey,
            'mysql_errno'   => $mysqlCode,
            'duration_ms'   => $queryMs,
            'rows'          => [],
            'row_count'     => 0,
            'affected_rows' => 0,
            'insert_id'     => null
        ]
    );

}


// ==========================================================================
// PAYLOAD BASE
// ==========================================================================

$payload = [

    'success' => true,

    'database' => $dbKey,

    'query_type' => $firstWord,

    'connection_ms' => $connectionMs,

    'duration_ms' => $queryMs,

    'affected_rows' => 0,

    'insert_id' => null,

    'rows' => [],

    'row_count' => 0,

];


// ==========================================================================
// RESULTADO
// ==========================================================================

$result =
    @$conn->use_result();


if (
    $result instanceof mysqli_result
) {

    $rows = [];

    $count = 0;


    while (
        $row = $result->fetch_assoc()
    ) {

        $count++;


        if (
            $count > MAX_RESULT_ROWS
        ) {

            @$result->free();


            logMessage(
                'RESULT_LIMIT_EXCEEDED ' .
                'db=' . $dbKey .
                ' max=' . MAX_RESULT_ROWS
            );


            apiError(
                413,
                'A consulta retornou registros demais. Utilize LIMIT/paginacao.',
                [
                    'database' => $dbKey,

                    'max_rows' =>
                        MAX_RESULT_ROWS,

                    'duration_ms' =>
                        $queryMs,

                    'rows' => [],

                    'row_count' => 0,

                    'affected_rows' => 0,

                    'insert_id' => null
                ]
            );

        }


        $rows[] = $row;

    }


    @$result->free();


    $payload['rows'] =
        $rows;


    $payload['row_count'] =
        count($rows);

}


// ==========================================================================
// INSERT / UPDATE / DELETE
// ==========================================================================

else {

    $payload['affected_rows'] =
        max(
            0,
            (int)$conn->affected_rows
        );


    $insertId =
        (int)$conn->insert_id;


    $payload['insert_id'] =
        $insertId > 0
            ? $insertId
            : null;

}


// ==========================================================================
// FECHA MYSQL
// ==========================================================================

closeConnection(
    $conn
);

$conn = null;


// ==========================================================================
// LIBERA CONCORRÊNCIA
// ==========================================================================

releaseConcurrency();


// ==========================================================================
// DEBUG OPERACIONAL
// ==========================================================================

$payload['concurrency_slot'] =
    $concurrencySlot;


// ==========================================================================
// SUCESSO
// ==========================================================================

respond(
    200,
    $payload
);