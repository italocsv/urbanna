<?php
if (!isset($_FILES['image'])) {
  http_response_code(400);
  echo 'Arquivo não enviado';
  exit;
}

$limite = 160000; // 150 KB
$tmpIn  = $_FILES['image']['tmp_name'];
$tmpOut = tempnam(sys_get_temp_dir(), 'img_') . '.jpg';

// 1️⃣ Se já é leve o suficiente, só retorna
if (filesize($tmpIn) <= $limite) {
    header('Content-Type: image/jpeg');
    readfile($tmpIn);
    exit;
}

// 2️⃣ Se já é leve o suficiente, só retorna
$cmd = "ffmpeg -y -i {$tmpIn} -vf \"scale='min(2048,iw)':-2:flags=lanczos\" -q:v 2 -map_metadata -1 {$tmpOut}";
exec($cmd);

// 3️⃣ Se ainda passou do limite, cai a qualidade
if (filesize($tmpOut) > $limite) {
    $cmd = "ffmpeg -y -i {$tmpIn} -vf \"scale='min(2048,iw)':-2:flags=lanczos\" -q:v 3 -map_metadata -1 {$tmpOut}";
    exec($cmd);
}

header('Content-Type: image/jpeg');
readfile($tmpOut);

unlink($tmpOut);
