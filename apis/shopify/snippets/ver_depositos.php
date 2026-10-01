<?php

header("Content-Type: application/json; charset=utf-8");

// =====================================================
// CORS
// =====================================================

$origensPermitidas = [
    "https://urbanna.com.br",
    "https://www.urbanna.com.br",
    "https://interno.urbanna.com.br",
    "https://www.interno.urbanna.com.br"
];

$origem = $_SERVER["HTTP_ORIGIN"] ?? "";

if (in_array($origem, $origensPermitidas, true)) {
    header("Access-Control-Allow-Origin: " . $origem);
}

header("Vary: Origin");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Allow-Methods: POST, OPTIONS");

// Responde ao preflight da Shopify
if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(204);
    exit;
}

// =====================================================
// SOMENTE POST
// =====================================================

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);

    echo json_encode([
        "success" => false,
        "error" => "Método não permitido"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// LÊ JSON
// =====================================================

$rawInput = file_get_contents("php://input");

if ($rawInput === false || trim($rawInput) === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "error" => "Corpo da requisição vazio"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$input = json_decode($rawInput, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "error" => "JSON inválido",
        "json_error" => json_last_error_msg()
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// SKU PAI
// =====================================================

$skuPai = $input["sku_pai"] ?? null;

if ($skuPai === null || trim((string) $skuPai) === "") {
    http_response_code(400);

    echo json_encode([
        "success" => false,
        "error" => "Parâmetro sku_pai ausente"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$skuPai = trim((string) $skuPai);

// =====================================================
// CONEXÃO MYSQL
// =====================================================

try {
    $pdo = new PDO(
        "mysql:host=148.230.72.178;port=3306;dbname=lojaur05_tagplus;charset=utf8mb4",
        "lojaur05_admin",
        "M2emsvjmt*20",
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    error_log("Erro conexão MySQL: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => "Erro ao conectar ao banco de dados"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// CONSULTA
// =====================================================

try {
    $sql = "
        SELECT *
        FROM produtos
        WHERE codigo_pai = :sku_pai
        ORDER BY codigo
    ";

    $stmt = $pdo->prepare($sql);

    $stmt->execute([
        ":sku_pai" => $skuPai
    ]);

    $resultados = $stmt->fetchAll();

    http_response_code(200);

    echo json_encode(
        $resultados,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

} catch (PDOException $e) {
    error_log("Erro consulta produtos: " . $e->getMessage());

    http_response_code(500);

    echo json_encode([
        "success" => false,
        "error" => "Erro na consulta ao banco de dados"
    ], JSON_UNESCAPED_UNICODE);

} finally {
    $stmt = null;
    $pdo = null;
}