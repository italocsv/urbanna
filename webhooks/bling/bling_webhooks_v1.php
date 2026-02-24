<?php
// bling_webhooks_v1.php

header("Content-Type: application/json");

$tentativas_conexao = 5;
$espera_conexao = 30;
$pdo = null;

// Tentativa de conexão com o banco
for ($i = 0; $i < $tentativas_conexao; $i++) {
    try {
        $pdo = new PDO("mysql:host=br952.hostgator.com.br;dbname=lojaur05_webhooks;charset=utf8", "lojaur05_admin", "M2emsvjmt*20", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false
        ]);
        break;
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Too many connections") !== false && $i < $tentativas_conexao - 1) {
            error_log("Tentativa de conexão falhou: Too many connections. Tentativa " . ($i + 1) . " de $tentativas_conexao. Aguardando $espera_conexao segundos...");
            sleep($espera_conexao);
        } else {
            error_log("Erro ao conectar ao banco: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Erro ao conectar ao banco de dados."]);
            exit;
        }
    }
}

// Captura o JSON puro
$rawPostData = file_get_contents("php://input");
$data = json_decode($rawPostData, true);

// Verificação de erro no JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Formato JSON inválido",
        "json_error" => json_last_error_msg(),
        "raw_data" => $rawPostData
    ]);
    exit;
}

// Extração de informações principais
$tipo_evento = $data['event'] ?? '';
$cnpj_origem = $_GET['origem_cnpj'] ?? ''; // Pegando da query string
$uid = $data['eventId'] ?? '';
$headers = json_encode(getallheaders(), JSON_UNESCAPED_UNICODE);
$payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// Geração de ID único
$timestamp = time();
$contador = 1;
do {
    $id = $timestamp . '-' . str_pad($contador, 2, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM webhooks_bling WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $existe = $stmt->fetchColumn();
    $contador++;
} while ($existe);

// Tentativa de inserção com até 6 tentativas
$tentativas = 6;
$sucesso = false;

for ($i = 0; $i < $tentativas; $i++) {
    try {
        $stmt = $pdo->prepare("
            INSERT INTO webhooks_bling 
            (id, processado, sistema, tipo_evento, uid, headers, data, recebido_em) 
            VALUES 
            (:id, :processado, :sistema, :tipo_evento, :uid, :headers, :data, NOW())
        ");
        $stmt->execute([
            'id' => $id,
            'processado' => 0,
            'sistema' => $cnpj_origem,
            'tipo_evento' => $tipo_evento,
            'uid' => $uid,
            'headers' => $headers,
            'data' => $payload
        ]);
        $sucesso = true;
        break;
    } catch (PDOException $e) {
        if ($i < $tentativas - 1) {
            sleep(10);
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Falha ao salvar no banco", "error" => $e->getMessage()]);
            exit;
        }
    }
}

// Resposta final
if ($sucesso) {
    http_response_code(200);
    echo json_encode(["status" => "success", "id" => $id]);
}
?>