<?php

function getJetTokensByCnpj(string $cnpj): array
{
    switch ($cnpj) {
        case '23927120000134':
            return [
                'customer_code' => 'J0086034625',
                'password'      => 'EHsk67f1',
                'api_account'   => '858701266681824512',
                'private_key'   => 'e80f3ec7ff714aea9ea3e9b5f8f71d9e',
            ];
        break;

        default:
            throw new Exception(
                "CNPJ {$cnpj} não cadastrado na base de tokens da Jet Transportadora"
            );
    }
}