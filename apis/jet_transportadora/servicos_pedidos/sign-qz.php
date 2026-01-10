<?php
// sign-qz.php
// Assinatura digital para QZ Tray
// Uso interno – impressão automática

header('Content-Type: text/plain');

// Lê o JSON enviado pelo QZ Tray
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['data'])) {
    http_response_code(400);
    echo 'No data to sign';
    exit;
}

$dataToSign = $input['data'];

// 🔐 PRIVATE KEY (embutida no PHP)
$privateKey = <<<KEY
-----BEGIN PRIVATE KEY-----
MIIEvwIBADANBgkqhkiG9w0BAQEFAASCBKkwggSlAgEAAoIBAQC08WPZgWkGRGck
YvSn1BE6LCvKc4uCFiQfRfm6wK4hiP/RItGZ8/sb48Pwr6TuhpDOls0Z4DvPVVyq
uRT/DSuxxnRiD6lONbIij2R6CkfYFUfKX1Dsda6pqROxvkZicHTWY11OcoSBIfrX
UU0wWO6toyjfqomyY1gvj4vKSCMLIkV7SqDSHX35PSXblEUs9wJ+LfNE4DkR9+01
EVhaazLgvQrlRAKIYKEdUTNMCIoRALvI+FME8U2oWsVqIFvAZKtfBMTrYbqXAVdw
kVxVoJt2guBoN85uXRLhWNQzJU/z/lk5Nk50a3SANOL2u+XxbqTZdF8AT01ToupY
K6ei57+LAgMBAAECggEAGJfMjfYIW8k1SZ1Hin4I32K8ivY8anBu9W8x25+vqzAv
MPIuEeI26ZoB+jctUBwrF2EovFEdX/dGso+YWngkTbPfAqsFRHOI5CigK/Q0wppV
2RwuaA0wsa+g1SI698s4HiGAP5bSCqkfKl/LAXy86A3KkuffckcNZ913TwWR+cOy
TgU7Cc54rFyznsohhlfC308B2/AC1VAGzCczxz7HW5PAFSgC8nAvpft91vUCjYXr
4ag8QXHgYz2YfNPWinDcLCYGCSTstKgRVF5rmI/zYvtZXUAO9o+62RyT8RRPyZvO
mhG1vnGSIZRcuhFOx4EtN0etl07EnNU5W2Z89ZrvVQKBgQDxx3G+35TlRgwUZa3Y
WiFArf90GrQ2lPCgkYKU25JijPF3mLvhskvVSbyG34YuPrmF6e12gQIB6KUkJH5F
R6mV/HtjJqfL2aqBjYvnuFJZ0K9fAdfPz2d+PNB8h2dtVG+XWa37Hry4gpu+LKZl
E1hwjnU7gR7aqnPYeEY3q9LxPwKBgQC/lenlF+UjqWx5U5eGddMfB2FCxQLuxycN
wEEf/wKy+Uap2Rh06oLsuo8IATpOwhkvtE2N/jRczXZENvO9zR0gTkinju0uFHEW
+E9yfPyFlzSJGW0jD0Eu7+BMlk8VKh++YoUKSbtruvcSLq/P2ZcYlslewj5J1ULW
XX5lDkNStQKBgQDmDgeOPkH4MsGVuvZC8efIGogCWtJ1SRz5O9uLdq4ANeohCWRk
qfl8NlA76X5MjISNBnxcEP7vAAX6sPqxQzH7NCXXv1VUI4YZBa1EzF8XdPkZprBJ
3Si1tnoOs+xW3EveMIfadXHPAv/cYbHmZRT27KZh+0d3e08Ff9QYbtclkwKBgQCY
jy0ok8WQh0pstqbzmIGctMi7XZx/PbEYnx589xlUIXImsExsVY4aKljZW/jtXFyo
AyC60FEsESR7H3Mqkdn+rrfmTccKqZaAXw0MswB29LgN8GRaxbv3P2bSNeMVjGyo
s1UTozEOkVxLa0fu8GsEVpZV0cG+E4dcoiiTGi97/QKBgQCk+Jo5ZQ3zKSARce7G
oHZFb7UofyOYYbpddax85gfSCaf7eOQTnCmnWLKWb0aL6EmSMnqzaMSlBZfvGzRW
G5oa9gQU+ee0+Gw4WJcCT14LD0qqFO6jeX9bzSMymIYYtihEhidCqwRfqbszoZD+
bdUBp9LMP50wgpQlgOXBJ94+KQ==
-----END PRIVATE KEY-----
KEY;

// Assina usando SHA512 (padrão QZ Tray)
$signature = '';
openssl_sign($dataToSign, $signature, $privateKey, OPENSSL_ALGO_SHA512);

// Retorna a assinatura em Base64
echo base64_encode($signature);
