<?php

/**
 * Multi-tenant por banco de dados: cada escola tem seu próprio banco MySQL
 * (mesmo padrão do legado), e o mesmo código Laravel atende todas — resolvido
 * pelo subdomínio da requisição em App\Http\Middleware\ResolveTenantDatabase.
 *
 * Um host sem TENANT_*_DB configurado (variável vazia) simplesmente não entra
 * no mapa — a requisição cai no banco padrão do .env (DB_DATABASE), então
 * nada quebra enquanto uma escola ainda não tem variável de ambiente definida
 * no servidor (ex: Criarte, antes de existir hospedagem pra ela).
 */
return [
    'hosts' => array_filter([
        env('TENANT_CEPELC_HOST', 'cepelc.portalmasterschool.com.br') => env('TENANT_CEPELC_DB'),
        env('TENANT_CRIARTE_HOST', 'criarte.portalmasterschool.com.br') => env('TENANT_CRIARTE_DB'),
        env('TENANT_TESTE_HOST', 'teste.portalmasterschool.com.br') => env('TENANT_TESTE_DB'),
    ]),
];
