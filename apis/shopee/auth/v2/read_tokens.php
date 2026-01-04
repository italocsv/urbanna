<?php

function getShopeeTokensByShopId(string $shopId): array
{
    $config = require BASE_PATH . '/config/db_mysql_hostgator.php';

    $conn = new mysqli(
        $config['host'],
        $config['user'],
        $config['pass'],
        $config['db']
    );

    $stmt = $conn->prepare("SELECT partner_id, partner_key, host, access_token
        FROM lojaur05_tagplus.apikey_shopee
        WHERE shop_id = ?;
    ");

    $stmt->bind_param("s", $shopId);
    $stmt->execute();
    $stmt->bind_result($partner_id, $partner_key, $host, $access_token);
    $stmt->fetch();
    $stmt->close();

    if (!$partner_id || !$partner_key || !$host || !$access_token) {
        throw new Exception("Credenciais Shopee não encontradas para shop_id {$shopId}");
    }

    return [
        'partner_id'   => $partner_id,
        'partner_key'  => $partner_key,
        'host'         => $host,
        'access_token' => $access_token
    ];
}