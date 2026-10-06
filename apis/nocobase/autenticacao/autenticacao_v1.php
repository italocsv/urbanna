<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/**
 * ============================================================
 * CONFIG
 * ============================================================
 */

$nocobaseUrl   = rtrim((string) getenv('NOCOBASE_URL'), '/');
$nocobaseToken = trim((string) getenv('NOCOBASE_TOKEN'));

if ($nocobaseUrl === '' || $nocobaseToken === '') {
    responder(500, [
        'error' => 'Configuração do NocoBase não encontrada.'
    ]);
}

/**
 * ============================================================
 * ENTRADA
 * ============================================================
 *
 * Aceita:
 *
 * POST JSON:
 * {
 *   "identificador_externo": "53073691000110"
 * }
 *
 * Também aceita:
 * {
 *   "cnpj": "53073691000110"
 * }
 *
 * E query string, caso necessário.
 */

$bodyRaw = file_get_contents('php://input');
$body = [];

if ($bodyRaw !== false && trim($bodyRaw) !== '') {
    $decoded = json_decode($bodyRaw, true);

    if (is_array($decoded)) {
        $body = $decoded;
    }
}

$identificador =
    $body['identificador_externo']
    ?? $body['cnpj']
    ?? $_POST['identificador_externo']
    ?? $_POST['cnpj']
    ?? $_GET['identificador_externo']
    ?? $_GET['cnpj']
    ?? null;

if ($identificador === null || trim((string) $identificador) === '') {
    responder(400, [
        'error' => 'identificador_externo é obrigatório.'
    ]);
}

/**
 * Mesmo comportamento do fluxo n8n:
 * remove pontuação do CNPJ/identificador.
 */
$identificadorExterno = preg_replace(
    '/\D+/',
    '',
    (string) $identificador
);

$identificadorExterno = trim((string) $identificadorExterno);

if ($identificadorExterno === '') {
    responder(400, [
        'error' => 'identificador_externo inválido.'
    ]);
}

/**
 * ============================================================
 * 1. BUSCAR SISTEMA BLING
 * ============================================================
 */

$sistema = nocobaseGet(
    $nocobaseUrl,
    $nocobaseToken,
    '/api/integracoes_sistemas:get',
    [
        'filter' => json_encode([
            'codigo' => [
                '$eq' => 'bling'
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'fields' => 'id,codigo'
    ]
);

$sistema = extrairRegistro($sistema);

if (
    !is_array($sistema)
    || empty($sistema['id'])
) {
    responder(404, [
        'error' => 'Sistema Bling não encontrado em integracoes_sistemas.'
    ]);
}

$sistemaId = $sistema['id'];

/**
 * ============================================================
 * 2. BUSCAR INSTÂNCIA BLING
 * ============================================================
 */

$instancia = nocobaseGet(
    $nocobaseUrl,
    $nocobaseToken,
    '/api/integracoes_instancias:get',
    [
        'filter' => json_encode([
            'sistema_id' => [
                '$eq' => $sistemaId
            ],
            'identificador_externo' => [
                '$eq' => $identificadorExterno
            ],
            'ativo' => [
                '$eq' => true
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),

        // Só traz o necessário.
        'fields' => 'id,identificador_externo,configuracoes'
    ]
);

$instancia = extrairRegistro($instancia);

if (
    !is_array($instancia)
    || empty($instancia['id'])
) {
    responder(404, [
        'error' =>
            "Instância Bling {$identificadorExterno} não encontrada."
    ]);
}

/**
 * ============================================================
 * 3. EXTRAIR CONFIGURAÇÕES
 * ============================================================
 */

$configuracoes = $instancia['configuracoes'] ?? [];

if (is_string($configuracoes)) {
    $configuracoesDecodificadas = json_decode(
        $configuracoes,
        true
    );

    if (!is_array($configuracoesDecodificadas)) {
        responder(500, [
            'error' =>
                'configuracoes da instância não contém JSON válido.'
        ]);
    }

    $configuracoes = $configuracoesDecodificadas;
}

if (!is_array($configuracoes)) {
    responder(500, [
        'error' => 'configuracoes da instância inválida.'
    ]);
}

$accessToken = trim(
    (string) ($configuracoes['access_token'] ?? '')
);

if ($accessToken === '') {
    responder(404, [
        'error' =>
            "access_token não configurado para a instância {$identificadorExterno}."
    ]);
}

/**
 * ============================================================
 * SUCESSO
 * ============================================================
 *
 * Mantém a saída simples:
 *
 * {
 *   "access_token": "..."
 * }
 */

responder(200, [
    'access_token' => $accessToken
]);


/**
 * ============================================================
 * FUNÇÕES
 * ============================================================
 */

function nocobaseGet(
    string $baseUrl,
    string $token,
    string $endpoint,
    array $query = []
): array {

    $url = $baseUrl . $endpoint;

    if (!empty($query)) {
        $url .= '?' . http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );
    }

    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,

        // Queremos que essa API seja rápida.
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,

        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]
    ]);

    $response = curl_exec($ch);

    if ($response === false) {
        $erro = curl_error($ch);
        curl_close($ch);

        responder(502, [
            'error' => 'Falha ao consultar NocoBase.',
            'detail' => $erro
        ]);
    }

    $httpCode = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    $json = json_decode($response, true);

    if ($httpCode < 200 || $httpCode >= 300) {
        responder(502, [
            'error' => 'NocoBase retornou erro.',
            'status' => $httpCode,
            'response' => $json ?? $response
        ]);
    }

    if (!is_array($json)) {
        responder(502, [
            'error' => 'Resposta inválida do NocoBase.'
        ]);
    }

    return $json;
}


function extrairRegistro(array $resposta): ?array
{
    $data = $resposta['data'] ?? $resposta;

    /*
     * Alguns endpoints podem retornar:
     *
     * data: {...}
     *
     * ou:
     *
     * data: [{...}]
     */

    if (
        is_array($data)
        && array_is_list($data)
    ) {
        return isset($data[0]) && is_array($data[0])
            ? $data[0]
            : null;
    }

    return is_array($data)
        ? $data
        : null;
}


function responder(
    int $status,
    array $dados
): never {

    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
    );

    exit;
}