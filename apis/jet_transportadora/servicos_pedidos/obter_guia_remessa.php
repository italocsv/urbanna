<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bootstrap.php';
require BASE_PATH . '/apis/jet_transportadora/auth/read_tokens.php';

// ================================
// RECUPERA CNPJ E BILLCODE
// ================================
header('Content-Type: application/json; charset=utf-8');

try {
    // 1️⃣ Ler parâmetros GET
    $cnpj     = $_GET['cnpj']     ?? null;
    $billCode = $_GET['billCode'] ?? null;

    // 2️⃣ Validar parâmetros
    if (!$cnpj || !$billCode) {
        http_response_code(400);
        echo json_encode([
            'error' => 'Parâmetros obrigatórios: cnpj e billCode'
        ]);
        exit;
    }

    // 3️⃣ Buscar tokens da Jet
    $tokens = getJetTokensByCnpj($cnpj);

    $customer_code = $tokens['customer_code'];
    $password = $tokens['password'];
    $api_account = $tokens['api_account'];
    $private_key = $tokens['private_key'];


    // Gerar body digest
    $bodyDigest = gerarBodyDigest(
        $customer_code,
        $private_key
    );

    // 4️⃣ Montar payload (array)
    $payload = [
        'customerCode' => $customer_code,
        'digest'       => $bodyDigest,
        'billCode'     => $billCode,
        'printSize'    => 1
    ];

    $bodyJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
    
    // Gerar header digest
    $headerDigest = gerarHeaderDigest(
        $bodyJson,
        $tokens['private_key']
    );

    $postFields = http_build_query([
        'bizContent' => $bodyJson
    ]);

    $timestamp = (string) round(microtime(true) * 1000);

    // 5️⃣ Enviar Requisiçaõo HTTP para Jet Transportadora
    
    $ch = curl_init('https://demogw.jtjms-br.com/webopenplatformapi/api/order/printOrder');

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'apiAccount: ' . $api_account,
            'digest: ' . $headerDigest,
            'timestamp: ' . $timestamp
        ],
        CURLOPT_TIMEOUT        => 30
    ]);

    $response = curl_exec($ch);
    
    if ($response === false) {
        throw new Exception(curl_error($ch));
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    echo $response;
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}

// ================================
// FUNÇÕES AUXILIARES
// ================================

function gerarBodyDigest(
    string $customerCode,
    string $privateKey
): string {

    // equivalente a: MD5.Create().ComputeHash(Encoding.UTF8.GetBytes("ROWjzgY7jadada236t2"))
    $md5ConstanteHex = strtoupper(md5('ROWjzgY7jadada236t2'));

    // concatenação:
    // customer_code + md5ConstanteHex + private_key
    $stringFinal = $customerCode . $md5ConstanteHex . $privateKey;

    // MD5 binário (true = raw output)
    $md5Binario = md5($stringFinal, true);

    // Base64
    return base64_encode($md5Binario);
}

function gerarHeaderDigest(string $bodyJson, string $privateKey): string
{
    // Concatena body JSON + private key
    $stringFinal = $bodyJson . $privateKey;

    // MD5 binário
    $md5Binario = md5($stringFinal, true);

    // Base64
    return base64_encode($md5Binario);
}