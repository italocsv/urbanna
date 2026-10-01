<?php

header('Content-Type: application/json; charset=utf-8');
header('Vary: Origin');

// =====================================================
// CORS
// =====================================================

$allowed = [
    'https://urbanna.com.br',
    'https://www.urbanna.com.br',
    'https://admin.shopify.com',
    'https://nwkg4f-p0.myshopify.com',
    'https://interno.urbanna.com.br'
];

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
}

header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: GET, OPTIONS');

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function responderErro($status, $mensagem)
{
    http_response_code($status);

    echo json_encode(
        ['error' => $mensagem],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

if ($method !== 'GET') {
    header('Allow: GET, OPTIONS');
    responderErro(405, 'Método não permitido');
}

// =====================================================
// VALIDA TAG
// =====================================================

$tag = $_GET['tag'] ?? null;

if (!is_string($tag) || trim($tag) === '') {
    responderErro(400, "Parâmetro 'tag' é obrigatório");
}

$tag = trim($tag);

if (strlen($tag) > 250) {
    responderErro(400, 'Tag muito longa');
}

// =====================================================
// MONTA GRAPHQL
// =====================================================

$tagEscapada = str_replace(
    ['\\', '"'],
    ['\\\\', '\\"'],
    $tag
);

$filtro = json_encode(
    'tag:"' . $tagEscapada . '"',
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

if ($filtro === false) {
    responderErro(400, 'Tag inválida');
}

$query = <<<GQL
query {
  products(
    first: 50,
    sortKey: CREATED_AT,
    reverse: true,
    query: $filtro
  ) {
    edges {
      node {
        title
        handle
        featuredImage {
          url
        }
        variants(first: 100) {
          edges {
            node {
              inventoryQuantity
            }
          }
        }
      }
    }
  }
}
GQL;

// =====================================================
// CHAMA N8N
// =====================================================

$endpoint = 'https://flow.urbanna.com.br/webhook/shopify-graphql';
$apiKey = '66deee7ae55f6ca08a1345103c291794';

$payload = json_encode([
    'store_name' => 'nwkg4f-p0',
    'query' => $query
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

if ($payload === false) {
    responderErro(500, 'Erro ao preparar consulta');
}

$ch = curl_init($endpoint);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'apikey: ' . $apiKey
    ],
    CURLOPT_POSTFIELDS => $payload
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
$curlErrno = curl_errno($ch);

curl_close($ch);

if ($response === false) {
    error_log('Erro ao chamar n8n: ' . $curlError);

    responderErro(
        $curlErrno === CURLE_OPERATION_TIMEDOUT ? 504 : 502,
        'Não foi possível consultar os produtos'
    );
}

if ($httpCode < 200 || $httpCode >= 300) {
    error_log('Webhook Shopify retornou HTTP ' . $httpCode);
    responderErro(502, 'Erro no serviço de consulta de produtos');
}

// =====================================================
// VALIDA RESPOSTA
// =====================================================

$data = json_decode($response, true);

if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
    responderErro(502, 'Resposta inválida do serviço de produtos');
}

// Aceita resposta do n8n envolvida em um array.
if (isset($data[0]) && is_array($data[0])) {
    $data = $data[0];
}

if (!empty($data['errors'])) {
    error_log(
        'Erro GraphQL Shopify: ' .
        json_encode($data['errors'], JSON_UNESCAPED_UNICODE)
    );

    responderErro(502, 'Erro ao consultar produtos na Shopify');
}

$edges = $data['data']['products']['edges'] ?? null;

if (!is_array($edges)) {
    responderErro(502, 'Serviço não retornou a lista de produtos');
}

// =====================================================
// FILTRA PRODUTOS COM ESTOQUE
// =====================================================

$produtos = [];

foreach ($edges as $edge) {
    $node = $edge['node'] ?? null;

    if (!is_array($node)) {
        continue;
    }

    $hasStock = false;

    foreach (($node['variants']['edges'] ?? []) as $variantEdge) {
        $quantidade = $variantEdge['node']['inventoryQuantity'] ?? 0;

        if (is_numeric($quantidade) && (float) $quantidade > 0) {
            $hasStock = true;
            break;
        }
    }

    if ($hasStock) {
        $produtos[] = [
            'title' => $node['title'] ?? '',
            'handle' => $node['handle'] ?? '',
            'featured_image' => $node['featuredImage']['url'] ?? null
        ];
    }
}

echo json_encode(
    ['products' => $produtos],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_INVALID_UTF8_SUBSTITUTE
);