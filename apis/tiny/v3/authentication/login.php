<?php
session_start();

$client_id = 'tiny-api-f5cc88c3977b0e6002b8ccf9318dee94ca6339d5-1776916347';
$redirect_uri = 'https://core.urbanna.com.br/tiny/authentication_v3/urbanna/authorization.php';

$state = bin2hex(random_bytes(16));
$_SESSION['tiny_oauth_state'] = $state;

$url = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?' . http_build_query([
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'response_type' => 'code',
    'scope' => 'openid',
    'state' => $state
]);

header('Location: ' . $url);
exit;