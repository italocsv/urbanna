<?php
// webhook_receiver_bling.php

header("Content-Type: application/json; charset=utf-8");

// Evita uma requisição ficar presa indefinidamente.
// OBS: no Linux, chamadas bloqueantes externas podem não contar da mesma
// forma para max_execution_time, por isso também usamos timeout no PDO.
set_time_limit(15);

$pdo = null;

// =====================================================
// 1. CONEXÃO MYSQL
// =====================================================
//
// Antes:
// 5 tentativas x 30 segundos = até 2 minutos esperando.
//
// Agora:
// no máximo 2 tentativas, com apenas 1 segundo entre elas.
//
$tentativasConexao = 2;

for ($i = 0; $i < $tentativasConexao; $i++) {
    try {
        $pdo = new PDO(
            "mysql:host=br952.hostgator.com.br;dbname=lojaur05_webhooks;charset=utf8mb4",
            "lojaur05_admin",
            "M2emsvjmt*20",
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_PERSISTENT => false,

                // Timeout curto para comunicação/conexão.
                PDO::ATTR_TIMEOUT => 5,

                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        break;

    } catch (PDOException $e) {

        error_log(
            "Erro conexão MySQL tentativa "
            . ($i + 1)
            . "/"
            . $tentativasConexao
            . ": "
            . $e->getMessage()
        );

        if ($i >= $tentativasConexao - 1) {
            http_response_code(503);

            echo json_encode([
                "status" => "error",
                "message" => "Serviço temporariamente indisponível."
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        // Não deixa o PHP parado 30 segundos.
        usleep(1000000); // 1 segundo
    }
}

// =====================================================
// 2. CAPTURA BODY
// =====================================================

$rawPostData = file_get_contents("php://input");

if ($rawPostData === false || $rawPostData === '') {
    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Empty request body"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// 3. CONVERSÃO DO FORMATO DO BLING
// =====================================================
//
// Mantém o comportamento do PHP antigo:
//
// data={"retorno":...}
//
// vira:
//
// {"data":{"retorno":...}}
//

if (strncmp($rawPostData, 'data=', 5) !== 0) {
    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid body format",
        "raw_data" => $rawPostData
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$jsonString = '{"data":' . substr($rawPostData, 5) . '}';

$data = json_decode($jsonString, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);

    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON format",
        "json_error" => json_last_error_msg(),
        "raw_data" => $rawPostData
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// 4. DADOS DO WEBHOOK
// =====================================================

$tipoEvento = $_GET['tipo_evento'] ?? '';
$cnpjOrigem = $_GET['origem_cnpj'] ?? '';

$uid = '';

$headers = json_encode(
    function_exists('getallheaders') ? getallheaders() : [],
    JSON_UNESCAPED_UNICODE
);

$payload = json_encode(
    $data,
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);

// =====================================================
// 5. GERAÇÃO DO ID
// =====================================================
//
// Mantém seu formato:
// timestamp-01
// timestamp-02
// ...
//
// Mas limita a quantidade de tentativas para impedir loop infinito.
//

$timestamp = time();
$id = null;

for ($contador = 1; $contador <= 99; $contador++) {

    $idCandidato =
        $timestamp
        . '-'
        . str_pad($contador, 2, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        SELECT 1
        FROM webhooks_bling
        WHERE id = :id
        LIMIT 1
    ");

    $stmt->execute([
        'id' => $idCandidato
    ]);

    if (!$stmt->fetchColumn()) {
        $id = $idCandidato;
        break;
    }
}

if ($id === null) {
    http_response_code(503);

    echo json_encode([
        "status" => "error",
        "message" => "Não foi possível gerar ID do webhook."
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

// =====================================================
// 6. INSERT
// =====================================================
//
// Antes:
// 6 tentativas x 10 segundos.
//
// Agora:
// 2 tentativas, 1 segundo entre elas.
//
// Webhook deve responder rápido.
// Se o banco estiver indisponível, melhor responder erro
// e permitir nova tentativa do remetente.
//

$tentativasInsert = 2;

for ($i = 0; $i < $tentativasInsert; $i++) {

    try {

        $stmt = $pdo->prepare("
            INSERT INTO webhooks_bling
            (
                id,
                processado,
                sistema,
                tipo_evento,
                uid,
                headers,
                data,
                recebido_em
            )
            VALUES
            (
                :id,
                :processado,
                :sistema,
                :tipo_evento,
                :uid,
                :headers,
                :data,
                NOW()
            )
        ");

        $stmt->execute([
            'id' => $id,
            'processado' => 0,
            'sistema' => $cnpjOrigem,
            'tipo_evento' => $tipoEvento,
            'uid' => $uid,
            'headers' => $headers,
            'data' => $payload
        ]);

        // Fecha explicitamente referências ao statement/conexão
        // ao final da requisição.
        $stmt = null;
        $pdo = null;

        http_response_code(200);

        echo json_encode([
            "status" => "success",
            "id" => $id
        ], JSON_UNESCAPED_UNICODE);

        exit;

    } catch (PDOException $e) {

        error_log(
            "Erro INSERT webhook "
            . $id
            . " tentativa "
            . ($i + 1)
            . "/"
            . $tentativasInsert
            . ": "
            . $e->getMessage()
        );

        if ($i >= $tentativasInsert - 1) {

            $pdo = null;

            http_response_code(503);

            echo json_encode([
                "status" => "error",
                "message" => "Database insert failed"
            ], JSON_UNESCAPED_UNICODE);

            exit;
        }

        usleep(1000000); // 1 segundo
    }
}