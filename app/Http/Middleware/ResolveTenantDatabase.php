<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Multi-tenant por banco de dados: cada escola (cepelc, criarte, ambiente de
 * teste...) tem seu próprio banco MySQL, e o mesmo deploy do Laravel atende
 * todas — resolvido pelo subdomínio da requisição, mapeado num único lugar
 * (config/tenants.php, alimentado por variáveis de ambiente).
 *
 * Fora dos hosts mapeados (dev local via 127.0.0.1:8010, IP direto do
 * servidor) o banco padrão do .env (DB_DATABASE) continua valendo — nenhuma
 * mudança no fluxo de teste local já existente.
 *
 * Roda como middleware GLOBAL (registrado em bootstrap/app.php, não em
 * nenhuma rota específica), e precisa ser o primeiro da pilha — troca a
 * conexão antes de qualquer query, inclusive as feitas por outros
 * middlewares (VerifyLegacyJwt não consulta o banco, mas RequireRole/
 * controllers sim).
 */
class ResolveTenantDatabase
{
    public function handle(Request $request, Closure $next): Response
    {
        $host = $request->getHost();
        $tenants = config('tenants.hosts', []);

        if (isset($tenants[$host])) {
            $bancoAtual = config('database.connections.mysql.database');
            if ($tenants[$host] !== $bancoAtual) {
                Config::set('database.connections.mysql.database', $tenants[$host]);
                DB::purge('mysql');
            }
        }

        return $next($request);
    }
}
