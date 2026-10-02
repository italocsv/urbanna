<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| URBANNA SQL API — VPS
|--------------------------------------------------------------------------
|
| SEM AUTENTICAÇÃO TEMPORARIAMENTE.
|
| Proteções:
| - Máximo de queries simultâneas.
| - Sem retry interno.
| - Timeout de conexão.
| - Timeout de query.
| - Timeout de lock.
| - Limite de body.
| - Limite de tamanho da query.
| - Limite de registros configurável (0 = ilimitado).
| - Whitelist de bancos.
| - Bloqueio de comandos administrativos/perigosos.
| - Log de erros.
| - Log de queries lentas.
| - Retorno 503 em problemas temporários.
|
*/


// ==========================================================================
// MYSQL
// ==========================================================================

const DB_HOST = '148.230.72.178';
const DB_PORT = 3306;

/*
|--------------------------------------------------------------------------
| CREDENCIAIS
|--------------------------------------------------------------------------
|
| Configure no Coolify:
|
| URBANNA_DB_USER
| URBANNA_DB_PASS
|
*/

$DB_USER = getenv('URBANNA_DB_USER') ?: '';
$DB_PASS = getenv('URBANNA_DB_PASS') ?: '';


// ==========================================================================
// CONFIGURAÇÕES
// ==========================================================================

/*
|--------------------------------------------------------------------------
| READ ONLY
|--------------------------------------------------------------------------
|
| false:
| SELECT / INSERT / UPDATE / DELETE
|
| true:
| somente leitura
|
*/

const READ_ONLY = false;


/*
|--------------------------------------------------------------------------
| LIMITES
|--------------------------------------------------------------------------
*/

// Máximo de queries SQL executando simultaneamente.
const MAX_CONCURRENT_REQUESTS = 5;

// Timeout para abrir conexão MySQL.
const DB_CONNECT_TIMEOUT_SECONDS = 3;

// Timeout desejado para query.
const QUERY_TIMEOUT_SECONDS = 10;

// Timeout esperando lock InnoDB.
const INNODB_LOCK_WAIT_TIMEOUT = 5;

// Máximo de registros retornados.
// 0 = ilimitado (compatibilidade com rotinas antigas).
// ATENÇÃO: respostas muito grandes ainda ficam limitadas por memória/tempo do PHP e infraestrutura.
const MAX_RESULT_ROWS = 0;

// Considera query lenta a partir de:
const SLOW_QUERY_MS = 1500;

// Tempo máximo geral do PHP.
const PHP_MAX_EXECUTION_SECONDS = 20;

// Máximo do body HTTP: 1 MB.
const MAX_BODY_BYTES = 1048576;

// Máximo da string SQL: 256 KB.
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

    $REQUEST_ID = bin2hex(
        random_bytes(8)
    );

} catch (Throwable $e) {

    $REQUEST_ID = uniqid(
        '',
        true
    );

}

header(
    'X-Request-Id: ' . $REQUEST_ID
);


// ==========================================================================
// VARIÁVEIS DE CONTROLE
// ==========================================================================

$concurrencyHandle = null;

$concurrencySlot = null;

$conn = null;


// ==========================================================================
// FUNÇÕES
// ==========================================================================

function respond(
    int $code,
    array $payload
): never {

    global $REQUEST_ID;

    if (
        !isset(
            $payload['request_id']
        )
    ) {

        $payload['request_id'] =
            $REQUEST_ID;

    }


    http_response_code(
        $code
    );


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
                'error'   => $message
            ],

            $extra

        )

    );
}


function elapsedMs(
    float $start
): float {

    return round(

        (
            microtime(true) -
            $start
        ) * 1000,

        2

    );
}


function logMessage(
    string $message
): void {

    global $REQUEST_ID;


    error_log(

        '[URBANNA-SQL-API]' .

        '[request=' .
        $REQUEST_ID .
        '] ' .

        $message

    );
}


function closeConnection(
    ?mysqli $conn
): void {

    if (
        $conn instanceof mysqli
    ) {

        try {

            @$conn->close();

        } catch (Throwable $e) {

            // Ignora erro no fechamento.

        }

    }
}


function releaseConcurrency(): void
{
    global $concurrencyHandle;


    if (
        is_resource(
            $concurrencyHandle
        )
    ) {

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


    if (
        $conn instanceof mysqli
    ) {

        closeConnection(
            $conn
        );

    }


    releaseConcurrency();
}


function logSlowQuery(
    string $database,
    string $query,
    float $durationMs
): void {

    /*
     * Não registra a query inteira.
     *
     * Guarda preview + hash.
     */

    $normalized =
        preg_replace(
            '/\s+/',
            ' ',
            trim($query)
        );


    $preview =
        substr(
            (string)$normalized,
            0,
            250
        );


    $hash =
        hash(
            'sha256',
            $query
        );


    logMessage(

        'SLOW_QUERY ' .

        'db=' .
        $database .

        ' duration_ms=' .
        $durationMs .

        ' hash=' .
        substr(
            $hash,
            0,
            16
        ) .

        ' preview=' .
        $preview

    );
}


function getFirstSqlWord(
    string $query
): string {

    $clean =
        ltrim(
            $query
        );


    /*
     * Remove comentários iniciais.
     */

    $clean =
        preg_replace(

            '/^(?:\s*(?:--[^\r\n]*(?:\r?\n|$)|#[^\r\n]*(?:\r?\n|$)|\/\*.*?\*\/))+/s',

            '',

            $clean

        );


    if (
        $clean === null
    ) {

        return '';

    }


    $token =
        strtok(

            ltrim(
                $clean
            ),

            " \t\n\r("

        );


    if (
        $token === false
    ) {

        return '';

    }


    return strtoupper(
        $token
    );
}


function isTemporaryMysqlError(
    int $errno,
    string $error
): bool {

    /*
     * Erros normalmente temporários.
     */

    $temporaryCodes = [

        1040, // Too many connections

        1205, // Lock wait timeout

        1213, // Deadlock

        2002, // Connection error

        2006, // Server gone away

        2013, // Lost connection

    ];


    if (
        in_array(
            $errno,
            $temporaryCodes,
            true
        )
    ) {

        return true;

    }


    $error =
        strtolower(
            $error
        );


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


    foreach (
        $patterns as $pattern
    ) {

        if (
            str_contains(
                $error,
                $pattern
            )
        ) {

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
// SOMENTE POST
// ==========================================================================

if (
    $_SERVER['REQUEST_METHOD']
    !==
    'POST'
) {

    header(
        'Allow: POST'
    );


    apiError(

        405,

        'Metodo nao permitido. Use POST.'

    );
}


// ==========================================================================
// VERIFICA CONFIGURAÇÃO MYSQL
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


if (
    !is_dir(
        $lockDirectory
    )
) {

    if (

        !@mkdir(
            $lockDirectory,
            0700,
            true
        )

        &&

        !is_dir(
            $lockDirectory
        )

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


    $handle =
        @fopen(
            $lockFile,
            'c'
        );


    if (
        $handle === false
    ) {

        continue;

    }


    if (

        @flock(
            $handle,
            LOCK_EX | LOCK_NB
        )

    ) {

        $concurrencyHandle =
            $handle;


        $concurrencySlot =
            $i;


        break;

    }


    @fclose(
        $handle
    );
}


if (
    $concurrencyHandle === null
) {

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
// BODY SIZE
// ==========================================================================

$contentLength =

    isset(
        $_SERVER['CONTENT_LENGTH']
    )

        ? (int)$_SERVER['CONTENT_LENGTH']

        : 0;


if (
    $contentLength >
    MAX_BODY_BYTES
) {

    apiError(

        413,

        'Body da requisicao excede o tamanho permitido.'

    );
}


// ==========================================================================
// LÊ BODY
// ==========================================================================

$raw =
    file_get_contents(

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

        'Body vazio. Envie JSON com query e database.'

    );
}


if (
    strlen($raw) >
    MAX_BODY_BYTES
) {

    apiError(

        413,

        'Body da requisicao excede o tamanho permitido.'

    );
}


// ==========================================================================
// JSON
// ==========================================================================

$body =
    json_decode(
        $raw,
        true
    );


if (

    json_last_error()
    !==
    JSON_ERROR_NONE

    ||

    !is_array(
        $body
    )

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

    isset(
        $body['query']
    )

        ? trim(
            (string)$body['query']
        )

        : '';


if (
    $query === ''
) {

    apiError(

        400,

        'Campo "query" ausente ou vazio.'

    );
}


if (
    strlen($query) >
    MAX_QUERY_BYTES
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

    isset(
        $body['database']
    )

        ? trim(
            (string)$body['database']
        )

        : '';


if (
    $dbKey === ''
) {

    $dbKey =
        $DATABASES[0];

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
            'available_databases'
                => $DATABASES
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


if (
    $firstWord === ''
) {

    apiError(

        400,

        'Nao foi possivel identificar o comando SQL.'

    );
}


// ==========================================================================
// COMANDOS ADMINISTRATIVOS BLOQUEADOS
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
// READ ONLY OPCIONAL
// ==========================================================================

if (
    READ_ONLY
) {

    $allowedReadCommands = [

        'SELECT',

        'SHOW',

        'DESCRIBE',

        'DESC',

        'EXPLAIN',

    ];


    if (

        !in_array(
            $firstWord,
            $allowedReadCommands,
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


$conn =
    mysqli_init();


if (
    !$conn
) {

    apiError(

        503,

        'Nao foi possivel inicializar conexao MySQL.'

    );
}


// ==========================================================================
// TIMEOUT DA CONEXÃO
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
// CONECTA MYSQL
// ==========================================================================

$connected =
    @$conn->real_connect(

        DB_HOST,

        $DB_USER,

        $DB_PASS,

        $dbKey,

        DB_PORT

    );


if (
    !$connected
) {

    $mysqlError =
        (string)$conn->connect_error;


    $mysqlCode =
        (int)$conn->connect_errno;


    logMessage(

        'MYSQL_CONNECTION_ERROR ' .

        'db=' .
        $dbKey .

        ' errno=' .
        $mysqlCode .

        ' error=' .
        $mysqlError

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
            'retry_after_seconds'
                => 2
        ]

    );
}


// ==========================================================================
// TEMPO DE CONEXÃO
// ==========================================================================

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

        'db=' .
        $dbKey .

        ' error=' .
        $mysqlError

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


// ==========================================================================
// MYSQL OU MARIADB
// ==========================================================================

$serverInfo =
    (string)$conn->server_info;


$isMariaDB =

    stripos(
        $serverInfo,
        'MariaDB'
    )

    !== false;


// ==========================================================================
// TIMEOUT DA QUERY
// ==========================================================================

if (
    $isMariaDB
) {

    @$conn->query(

        'SET SESSION max_statement_time = ' .

        (float)QUERY_TIMEOUT_SECONDS

    );

}

else {

    @$conn->query(

        'SET SESSION MAX_EXECUTION_TIME = ' .

        (
            (int)QUERY_TIMEOUT_SECONDS
            *
            1000
        )

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
// LOG QUERY LENTA
// ==========================================================================

if (
    $queryMs >=
    SLOW_QUERY_MS
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

if (
    !$executed
) {

    $mysqlError =
        (string)$conn->error;


    $mysqlCode =
        (int)$conn->errno;


    logMessage(

        'QUERY_ERROR ' .

        'db=' .
        $dbKey .

        ' errno=' .
        $mysqlCode .

        ' duration_ms=' .
        $queryMs .

        ' error=' .
        $mysqlError

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

                'database'
                    => $dbKey,

                'duration_ms'
                    => $queryMs,

                'retry_after_seconds'
                    => 2,

                'affected_rows'
                    => 0,

                'insert_id'
                    => null,

                'rows'
                    => [],

                'row_count'
                    => 0

            ]

        );
    }


    apiError(

        400,

        'Erro ao executar a query.',

        [

            'database'
                => $dbKey,

            'mysql_errno'
                => $mysqlCode,

            'duration_ms'
                => $queryMs,

            'affected_rows'
                => 0,

            'insert_id'
                => null,

            'rows'
                => [],

            'row_count'
                => 0

        ]

    );
}


// ==========================================================================
// PAYLOAD PADRÃO
// ==========================================================================

$payload = [

    'success'
        => true,

    'database'
        => $dbKey,

    'query_type'
        => $firstWord,

    'connection_ms'
        => $connectionMs,

    'duration_ms'
        => $queryMs,

    'affected_rows'
        => 0,

    'insert_id'
        => null,

    'rows'
        => [],

    'row_count'
        => 0,

];


// ==========================================================================
// TENTA OBTER RESULTADO
// ==========================================================================

$result =
    @$conn->use_result();


// ==========================================================================
// SELECT / SHOW / DESCRIBE / EXPLAIN
// ==========================================================================

if (
    $result instanceof mysqli_result
) {

    $rows = [];

    $count = 0;


    while (
        $row =
            $result->fetch_assoc()
    ) {

        $count++;


        /*
         * Não deixa carregar resultado gigante.
         */

        if (
            MAX_RESULT_ROWS > 0 &&
            $count > MAX_RESULT_ROWS
        ) {

            @$result->free();


            logMessage(

                'RESULT_LIMIT_EXCEEDED ' .

                'db=' .
                $dbKey .

                ' max_rows=' .
                MAX_RESULT_ROWS

            );


            apiError(

                413,

                'A consulta retornou registros demais. Utilize LIMIT/paginacao.',

                [

                    'database'
                        => $dbKey,

                    'max_rows'
                        => MAX_RESULT_ROWS,

                    'duration_ms'
                        => $queryMs,

                    'affected_rows'
                        => 0,

                    'insert_id'
                        => null,

                    'rows'
                        => [],

                    'row_count'
                        => 0

                ]

            );
        }


        $rows[] =
            $row;

    }


    @$result->free();


    $payload['rows'] =
        $rows;


    $payload['row_count'] =
        count(
            $rows
        );

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
// LIBERA SLOT
// ==========================================================================

releaseConcurrency();


// ==========================================================================
// RETORNA
// ==========================================================================

respond(
    200,
    $payload
);