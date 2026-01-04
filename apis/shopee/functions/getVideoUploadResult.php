<?php

function shopeeGetVideoUploadResult(array $params): array
{
    // você decide o contrato
    // exemplo de parâmetros esperados:
    // shop_id, video_path, product_id, etc.

    $shopId    = $params['shop_id'] ?? null;
    $videoPath = $params['video_path'] ?? null;

    if (!$shopId || !$videoPath) {
        return [
            'success' => false,
            'error'   => 'Parâmetros obrigatórios ausentes'
        ];
    }

    // ---- aqui entra TODA sua lógica ----
    // ffmpeg
    // split
    // init_video_upload
    // upload_video_part
    // complete_video_upload
    // polling até SUCCEEDED
    // -----------------------------------

    return [
        'success' => true,
        'status'  => 'SUCCEEDED',
        'video'   => [
            'video_url'     => 'https://...',
            'thumbnail_url' => 'https://...',
            'duration'      => 15
        ]
    ];
}
