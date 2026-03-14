<?php
// webhook_receiver_bling.php

header("Content-Type: application/json");

$tentativas_conexao = 5; // Número máximo de tentativas
$espera_conexao = 30; // Tempo de espera entre tentativas em segundos
$pdo = null;

// Loop para tentar a conexão várias vezes em caso de erro "Too many connections"
for ($i = 0; $i < $tentativas_conexao; $i++) {
    try {
        $pdo = new PDO("mysql:host=br952.hostgator.com.br;dbname=lojaur05_webhooks;charset=utf8", "lojaur05_admin", "M2emsvjmt*20", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_PERSISTENT => false // Evita conexões persistentes
        ]);
        break; // Sai do loop se a conexão for bem-sucedida
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), "Too many connections") !== false) {
            if ($i < $tentativas_conexao - 1) {
                error_log("Tentativa de conexão falhou: Too many connections. Tentativa " . ($i + 1) . " de $tentativas_conexao. Aguardando $espera_conexao segundos...");
                sleep($espera_conexao); // Aguarda antes de tentar novamente
            } else {
                error_log("Erro crítico: Não foi possível conectar ao banco após $tentativas_conexao tentativas.");
                http_response_code(500);
                echo json_encode(["status" => "error", "message" => "Serviço temporariamente indisponível."]);
                exit;
            }
        } else {
            error_log("Erro inesperado ao conectar ao banco: " . $e->getMessage());
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Erro interno do servidor."]);
            exit;
        }
    }
}

// Captura o corpo da requisição
$rawPostData = file_get_contents("php://input");

// Substitui "data=" por '"data":' para transformar em JSON válido
$jsonString = str_replace("data=", '{"data":', $rawPostData) . "}";

// Agora decodificamos o JSON corretamente
$data = json_decode($jsonString, true);

// Se o JSON não for válido, retorna erro
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode([
        "status" => "error",
        "message" => "Invalid JSON format",
        "json_error" => json_last_error_msg(),
        "raw_data" => $rawPostData
    ]);
    exit;
}

// Gerar ID baseado no timestamp UNIX seguido de um numerador
$timestamp = time(); // Timestamp UNIX
$contador = 1;
do {
    $id = $timestamp . '-' . str_pad($contador, 2, '0', STR_PAD_LEFT);
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM webhooks_bling WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $existe = $stmt->fetchColumn();
    $contador++;
} while ($existe);

// Extrair os dados do webhook
$tipo_evento = $_GET['tipo_evento'] ?? ''; // Pegando da query string
$cnpj_origem = $_GET['origem_cnpj'] ?? ''; // Pegando da query string
$uid = ''; // UID sempre vazio
$headers = json_encode(getallheaders());
$payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT); // JSON limpo e formatado

// Tentar inserir no banco até 6 vezes
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
            'sistema' => $cnpj_origem, // CNPJ armazenado em 'sistema'
            'tipo_evento' => $tipo_evento,
            'uid' => $uid,
            'headers' => $headers,
            'data' => $payload
        ]);
        $sucesso = true;
        break; // Sai do loop se a inserção for bem-sucedida
    } catch (PDOException $e) {
        if ($i < $tentativas - 1) {
            sleep(10); // Espera 10s antes de tentar de novo
        } else {
            http_response_code(500);
            echo json_encode(["status" => "error", "message" => "Database insert failed", "error" => $e->getMessage()]);
        }
    }
}

// Se deu certo, retorna sucesso
if ($sucesso) {
    http_response_code(200);
    echo json_encode(["status" => "success", "id" => $id]);
}
?>
