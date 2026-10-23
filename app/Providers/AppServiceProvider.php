<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Alguns servidores (confirmado no Hostgator/ea-php83 de produção) têm
        // serialize_precision=100 no php.ini em vez do padrão -1 do PHP 7.1+
        // ("menor string que arredonda de volta pro mesmo float"). Com 100, todo
        // float no JSON sai com lixo de ponto flutuante (ex: 4914.6999999999998...
        // em vez de 4914.7). Forçando aqui pra não depender da config do host.
        ini_set('serialize_precision', -1);

        // Uma instância por requisição: as exceções de permissão são lidas uma
        // vez por requisição (e por escola — o banco muda com o subdomínio).
        $this->app->scoped(\App\Services\Sistema\PermissaoService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
