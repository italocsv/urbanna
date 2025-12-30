<?php
header('Content-Type: application/json');

echo json_encode([
  'status' => 'ok',
  'message' => 'Servidor PHP rodando via Coolify'
]);
