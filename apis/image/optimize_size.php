<?php
if (!isset($_FILES['image'])) {
  http_response_code(400);
  echo 'Arquivo não enviado';
  exit;
}

$tmpIn  = $_FILES['image']['tmp_name'];
$tmpOut = tempnam(sys_get_temp_dir(), 'img_') . '.jpg';

$cmd = "ffmpeg -y -i {$tmpIn} -vf \"scale='min(2048,iw)':-2:flags=lanczos\" -q:v 2 -map_metadata -1 {$tmpOut}";
exec($cmd);

header('Content-Type: image/jpeg');
readfile($tmpOut);

unlink($tmpOut);
