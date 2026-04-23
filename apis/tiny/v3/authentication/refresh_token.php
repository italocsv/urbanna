<?php

// ============ CONFIGURAÇÕES ============
$client_id = 'tiny-api-f5cc88c3977b0e6002b8ccf9318dee94ca6339d5-1750031853';
$client_secret = '23j2uUR0eCsLyq3qSknVUyR2YlTGOC3e';

// Conexão com o banco
$host = 'localhost';
$db   = 'lojaur05_tagplus';
$user = 'lojaur05_admin'; // seu usuário
$pass = 'M2emsvjmt*20'; // sua senha

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("Erro ao conectar no banco: " . $conn->connect_error);
}

// ============ BUSCA REFRESH_TOKEN ============
$cnpj = '23927120000134'; // ou use um loop para todos os CNPJs, se necessário

$stmt = $conn->prepare("SELECT refresh_token FROM apikey_tiny_v3 WHERE cnpj = ?");
$stmt->bind_param("s", $cnpj);
$stmt->execute();
$stmt->bind_result($refresh_token);
$stmt->fetch();
$stmt->close();

if (!$refresh_token) {
    die("Nenhum refresh_token encontrado para o CNPJ $cnpj");
}

echo $refresh_token;

// ============ REQUISIÇÃO TOKEN ============
$data = http_build_query([
    'grant_type' => 'refresh_token',
    'refresh_token' => $refresh_token,
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
curl_close($ch);

$result = json_decode($response, true);

// Verifica se houve erro
if (!isset($result['access_token'])) {
    http_response_code($http_code);
    echo json_encode(['erro' => 'Falha ao renovar token', 'retorno' => $result]);
    exit;
}

$novo_access_token = $result['access_token'];
$novo_refresh_token = $result['refresh_token'];
$data_atualizacao = date('Y-m-d H:i:s');

// ============ ATUALIZA O BANCO ============
$stmt = $conn->prepare("UPDATE apikey_tiny_v3 SET access_token = ?, refresh_token = ?, data_atualizacao = ? WHERE cnpj = ?");
$stmt->bind_param("ssss", $novo_access_token, $novo_refresh_token, $data_atualizacao, $cnpj);
$stmt->execute();
$stmt->close();
$conn->close();

echo json_encode([
    'status' => 'OK',
    'access_token' => $novo_access_token,
    'refresh_token' => $novo_refresh_token,
    'atualizado_em' => $data_atualizacao
]);
