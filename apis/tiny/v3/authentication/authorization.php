<?php

// =================== CONFIGURAÇÕES ===================
$client_id = 'tiny-api-f5cc88c3977b0e6002b8ccf9318dee94ca6339d5-1776916347';
$client_secret = 'CDLZmD334DmY3sSZ4pH1eZPqIzC0ky5M';
$redirect_uri = 'https://core.urbanna.com.br/tiny/authentication_v3/urbanna/authorization.php';
$cnpj = '23927120000134';
$slug = 'urbanna';

// Dados de conexão MySQL
$host = 'localhost';
$db   = 'lojaur05_tagplus';
$user = 'lojaur05_admin'; // coloque seu usuário
$pass = 'M2emsvjmt*20';   // coloque sua senha

// =================== ETAPA 1 - OBTER TOKEN ===================
if (!isset($_GET['code'])) {
    die('Código de autorização não encontrado.');
}

$code = $_GET['code'];

$data = http_build_query([
    'grant_type' => 'authorization_code',
    'code' => $code,
    'redirect_uri' => $redirect_uri,
    'client_id' => $client_id,
    'client_secret' => $client_secret
]);


$ch = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_errno = curl_errno($ch);
$curl_error = curl_error($ch);
curl_close($ch);

$result = json_decode($response, true);

if (!isset($result['access_token'])) {
    echo "<pre>";
    print_r([
        'http_code' => $http_code,
        'curl_errno' => $curl_errno,
        'curl_error' => $curl_error,
        'raw_response' => $response,
        'result' => $result,
        'json_error' => json_last_error_msg(),
    ]);
    echo "</pre>";
    exit;
}

$access_token = $result['access_token'];
$refresh_token = $result['refresh_token'];
$data_atualizacao = date('Y-m-d H:i:s');

// =================== ETAPA 2 - CONEXÃO MYSQL ===================
$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die('Erro ao conectar no MySQL: ' . $conn->connect_error);
}

// =================== ETAPA 3 - VERIFICAR E SALVAR ===================
$cnpj_escape = $conn->real_escape_string($cnpj);
$sql_check = "SELECT * FROM apikey_tiny_v3 WHERE cnpj = '$cnpj_escape'";
$res = $conn->query($sql_check);

if ($res && $res->num_rows > 0) {
    // Atualiza
    $stmt = $conn->prepare("UPDATE apikey_tiny_v3 SET access_token=?, refresh_token=?, data_atualizacao=? WHERE cnpj=?");
    $stmt->bind_param('ssss', $access_token, $refresh_token, $data_atualizacao, $cnpj);
    $stmt->execute();
    $msg = 'Registro atualizado.';
} else {
    // Insere
    $stmt = $conn->prepare("INSERT INTO apikey_tiny_v3 (cnpj, slug, access_token, refresh_token, data_atualizacao) VALUES (?, ?, ?, ?, ?)");
    $stmt->bind_param('sssss', $cnpj, $slug, $access_token, $refresh_token, $data_atualizacao);
    $stmt->execute();
    $msg = 'Registro inserido.';
}

$stmt->close();
$conn->close();

// =================== ETAPA 4 - RESPOSTA FINAL ===================
echo json_encode([
    'status' => 'OK',
    'mensagem' => $msg,
    'access_token' => $access_token,
    'refresh_token' => $refresh_token,
    'atualizado_em' => $data_atualizacao
]);
