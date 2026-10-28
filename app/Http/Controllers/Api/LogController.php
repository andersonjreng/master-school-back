<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\AuditoriaCatalogo;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lado leitura da auditoria (tela "Logs do Sistema", só Administrador). A
 * escrita é feita por LogSistemaService::registrar() — chamado pelos
 * controllers, pelo middleware AuditarRequisicao e pelos eventos do front.
 *
 * Todos os endpoints aceitam os mesmos filtros (ver filtrar()).
 */
class LogController extends Controller
{
    private const COLUNAS = [
        'id', 'usuario_id', 'username', 'acao', 'categoria', 'sessao_id', 'endpoint', 'tela', 'metodo',
        'descricao', 'ip_address', 'user_agent', 'status_code', 'dados_extras', 'criado_em',
    ];

    /** Categorias que contam como "ação" (mudança de dados) nos totais. */
    private const CATEGORIAS_ACAO = "'cadastro','academico','financeiro','comunicacao','sistema'";

    public function __construct(private LogSistemaService $log) {}

    public function index(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query('pagina', 1));
        $porPagina = min(200, max(5, (int) $request->query('por_pagina', 25)));

        $query = $this->filtrar(DB::table('logs_sistema'), $request);
        $total = (clone $query)->count();
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginas);

        $asc = $request->query('ordem') === 'asc';
        $logs = $query
            ->orderBy('criado_em', $asc ? 'asc' : 'desc')->orderBy('id', $asc ? 'asc' : 'desc')
            ->limit($porPagina)->offset(($pagina - 1) * $porPagina)
            ->get(self::COLUNAS)
            ->map(fn ($row) => $this->formatar($row));

        return response()->json([
            'data' => $logs,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => $paginas,
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $row = DB::table('logs_sistema')->where('id', $id)->first(self::COLUNAS);
        if (!$row) {
            return response()->json(['error' => 'Registro não encontrado.'], 404);
        }

        $log = $this->formatar($row);
        $log['eventos_na_sessao'] = $row->sessao_id
            ? DB::table('logs_sistema')->where('sessao_id', $row->sessao_id)->count()
            : 0;

        return response()->json($log);
    }

    /** Opções dos filtros: categorias, ações já registradas e usuários. */
    public function filtros(): JsonResponse
    {
        $acoes = DB::table('logs_sistema')
            ->selectRaw('acao, MAX(categoria) as categoria, COUNT(*) as total')
            ->groupBy('acao')->orderBy('acao')->get()
            ->map(fn ($r) => [
                'acao' => $r->acao,
                'categoria' => $r->categoria ?? AuditoriaCatalogo::categoriaDaAcao($r->acao),
                'total' => (int) $r->total,
            ]);

        $usuarios = DB::table('usuarios')->orderBy('nome_completo')->get(['id', 'nome_completo as nome', 'ativo']);

        return response()->json([
            'categorias' => collect(AuditoriaCatalogo::CATEGORIAS)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'acoes' => $acoes,
            'usuarios' => $usuarios,
        ]);
    }

    /** Indicadores, gráficos e alertas de segurança do período. */
    public function resumo(Request $request): JsonResponse
    {
        [$ini, $fim] = $this->periodo($request);
        $dias = (int) round($ini->diffInDays($fim->copy()->startOfDay())) + 1;
        [$antIni, $antFim] = [$ini->copy()->subDays($dias), $ini->copy()->subSecond()];

        $base = fn () => $this->filtrar(DB::table('logs_sistema'), $request, false);
        $noPeriodo = fn () => $base()->whereBetween('criado_em', [$ini, $fim]);

        $totais = fn (Builder $q) => array_map('intval', (array) $q->selectRaw("
            COUNT(*) as eventos,
            COUNT(DISTINCT usuario_id) as usuarios,
            COUNT(DISTINCT sessao_id) as sessoes,
            COALESCE(SUM(acao = 'LOGIN_SUCESSO'), 0) as logins,
            COALESCE(SUM(acao = 'LOGIN_FALHA'), 0) as falhas_login,
            COALESCE(SUM(categoria = 'navegacao'), 0) as telas,
            COALESCE(SUM(categoria = 'exportacao'), 0) as exportacoes,
            COALESCE(SUM(categoria IN (" . self::CATEGORIAS_ACAO . ") AND metodo <> 'GET'), 0) as acoes,
            COALESCE(SUM(acao = 'ACESSO_NEGADO'), 0) as acessos_negados,
            COALESCE(SUM(status_code >= 500), 0) as erros
        ")->first());

        $serie = $noPeriodo()
            ->selectRaw('DATE(criado_em) as dia, categoria, COUNT(*) as total')
            ->groupByRaw('DATE(criado_em), categoria')->orderBy('dia')->get();

        $mapaCalor = $noPeriodo()
            ->selectRaw('DAYOFWEEK(criado_em) as dia_semana, HOUR(criado_em) as hora, COUNT(*) as total')
            ->groupByRaw('DAYOFWEEK(criado_em), HOUR(criado_em)')->get();

        $porCategoria = $noPeriodo()
            ->selectRaw('categoria, COUNT(*) as total')->groupBy('categoria')->orderByDesc('total')->get();

        $topUsuarios = $noPeriodo()->whereNotNull('usuario_id')
            ->selectRaw("usuario_id, MAX(username) as username, COUNT(*) as total,
                COALESCE(SUM(categoria = 'navegacao'), 0) as telas,
                COALESCE(SUM(categoria = 'exportacao'), 0) as exportacoes,
                COALESCE(SUM(categoria IN (" . self::CATEGORIAS_ACAO . ") AND metodo <> 'GET'), 0) as acoes,
                MAX(criado_em) as ultima")
            ->groupBy('usuario_id')->orderByDesc('total')->limit(8)->get();

        $topAcoes = $noPeriodo()->where('categoria', '<>', 'navegacao')
            ->selectRaw('acao, MAX(categoria) as categoria, COUNT(*) as total')
            ->groupBy('acao')->orderByDesc('total')->limit(10)->get();

        $topTelas = $noPeriodo()->where('categoria', 'navegacao')
            ->selectRaw("tela, MAX(JSON_UNQUOTE(JSON_EXTRACT(dados_extras, '$.titulo'))) as titulo, COUNT(*) as total, COUNT(DISTINCT usuario_id) as usuarios")
            ->groupBy('tela')->orderByDesc('total')->limit(10)->get();

        $topExportacoes = $noPeriodo()->where('categoria', 'exportacao')
            ->selectRaw('descricao, COUNT(*) as total, MAX(criado_em) as ultima')
            ->groupBy('descricao')->orderByDesc('total')->limit(8)->get();

        return response()->json([
            'periodo' => ['inicio' => $ini->toDateString(), 'fim' => $fim->toDateString(), 'dias' => $dias],
            'totais' => $totais($noPeriodo()),
            'totais_anterior' => $totais($base()->whereBetween('criado_em', [$antIni, $antFim])),
            'serie' => $serie,
            'mapa_calor' => $mapaCalor,
            'por_categoria' => $porCategoria,
            'top_usuarios' => $topUsuarios,
            'top_acoes' => $topAcoes,
            'top_telas' => $topTelas,
            'top_exportacoes' => $topExportacoes,
            'seguranca' => $this->seguranca($ini, $fim),
            'online' => $this->online(),
        ]);
    }

    /** Sessões de login (agrupadas pelo "sid" do JWT) no período. */
    public function sessoes(Request $request): JsonResponse
    {
        $pagina = max(1, (int) $request->query('pagina', 1));
        $porPagina = min(100, max(5, (int) $request->query('por_pagina', 20)));

        $query = $this->filtrar(DB::table('logs_sistema'), $request)->whereNotNull('sessao_id');
        $total = (clone $query)->distinct()->count('sessao_id');
        $paginas = max(1, (int) ceil($total / $porPagina));
        $pagina = min($pagina, $paginas);

        $limiteAtiva = now()->subMinutes(30);
        $sessoes = $query
            ->selectRaw("sessao_id, MAX(usuario_id) as usuario_id, MAX(username) as username,
                MIN(criado_em) as inicio, MAX(criado_em) as fim, COUNT(*) as eventos,
                COALESCE(SUM(categoria = 'navegacao'), 0) as telas,
                COALESCE(SUM(categoria = 'exportacao'), 0) as exportacoes,
                COALESCE(SUM(categoria IN (" . self::CATEGORIAS_ACAO . ") AND metodo <> 'GET'), 0) as acoes,
                COALESCE(SUM(acao = 'ACESSO_NEGADO'), 0) as negados,
                COALESCE(SUM(acao = 'LOGOUT'), 0) as logout,
                MAX(ip_address) as ip_address, MAX(user_agent) as user_agent")
            ->groupBy('sessao_id')->orderByDesc('fim')
            ->limit($porPagina)->offset(($pagina - 1) * $porPagina)
            ->get()
            ->map(function ($s) use ($limiteAtiva) {
                $s->duracao_min = (int) round(Carbon::parse($s->inicio)->diffInMinutes(Carbon::parse($s->fim)));
                $s->situacao = $s->logout > 0 ? 'encerrada' : (Carbon::parse($s->fim)->gte($limiteAtiva) ? 'ativa' : 'inativa');

                return $s;
            });

        return response()->json([
            'data' => $sessoes,
            'total' => $total,
            'pagina' => $pagina,
            'por_pagina' => $porPagina,
            'paginas' => $paginas,
        ]);
    }

    /** Linha do tempo completa de uma sessão. */
    public function sessao(string $sid): JsonResponse
    {
        $eventos = DB::table('logs_sistema')->where('sessao_id', $sid)
            ->orderBy('criado_em')->orderBy('id')->limit(3000)
            ->get(self::COLUNAS)->map(fn ($row) => $this->formatar($row));

        if ($eventos->isEmpty()) {
            return response()->json(['error' => 'Sessão não encontrada.'], 404);
        }

        return response()->json(['sessao_id' => $sid, 'eventos' => $eventos]);
    }

    /** CSV (Excel pt-BR: ";" e BOM UTF-8) com os eventos filtrados. */
    public function exportar(Request $request): StreamedResponse
    {
        $limite = 50000;
        $query = $this->filtrar(DB::table('logs_sistema'), $request);
        $total = (clone $query)->count();

        $user = $request->attributes->get('max_user');
        $this->log->registrar(
            $request, (int) ($user->id ?? 0), $user->nome ?? null, 'EXPORTACAO_LOGS',
            '/api/admin/logs/exportar', 'GET',
            'Exportou ' . min($total, $limite) . ' registro(s) de auditoria (CSV)', 200,
            ['filtros' => $request->query()], 'exportacao'
        );

        $nome = 'auditoria_' . now()->format('Y-m-d_His') . '.csv';
        $categorias = AuditoriaCatalogo::CATEGORIAS;

        return response()->streamDownload(function () use ($query, $limite, $categorias) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['ID', 'Data/hora', 'Usuário', 'Categoria', 'Ação', 'Descrição', 'Tela', 'Endpoint', 'Método', 'Status', 'IP', 'Sessão', 'Navegador'], ';');

            // Lotes de 1000 por keyset (id decrescente) — memória constante.
            $ultimoId = null;
            $restante = $limite;
            while ($restante > 0) {
                $lote = (clone $query)->when($ultimoId, fn ($q) => $q->where('id', '<', $ultimoId))
                    ->orderByDesc('id')->limit(min(1000, $restante))->get(self::COLUNAS);
                if ($lote->isEmpty()) {
                    break;
                }
                foreach ($lote as $r) {
                    fputcsv($out, [
                        $r->id, Carbon::parse($r->criado_em)->format('d/m/Y H:i:s'), $r->username,
                        $categorias[$r->categoria] ?? $r->categoria, $r->acao, $r->descricao, $r->tela,
                        $r->endpoint, $r->metodo, $r->status_code, $r->ip_address, $r->sessao_id, $r->user_agent,
                    ], ';');
                }
                $ultimoId = $lote->last()->id;
                $restante -= $lote->count();
            }

            fclose($out);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    // ------------------------------------------------------------------

    /**
     * Filtros comuns: busca, usuario_id, usuario (nome), acoes, categorias
     * (listas separadas por vírgula), ocultar_navegacao, resultado
     * (sucesso|falha), ip, sessao_id, tela, metodo, data_inicio, data_fim.
     */
    private function filtrar(Builder $q, Request $r, bool $comDatas = true): Builder
    {
        if ($busca = trim((string) $r->query('busca'))) {
            $like = '%' . addcslashes($busca, '%_\\') . '%';
            $q->where(fn ($w) => $w->where('descricao', 'like', $like)->orWhere('username', 'like', $like)
                ->orWhere('endpoint', 'like', $like)->orWhere('tela', 'like', $like)
                ->orWhere('acao', 'like', $like)->orWhere('ip_address', 'like', $like));
        }
        if ($id = (int) $r->query('usuario_id')) {
            $q->where('usuario_id', $id);
        }
        if ($nome = trim((string) $r->query('usuario', $r->query('username', '')))) {
            $q->where('username', 'like', '%' . addcslashes($nome, '%_\\') . '%');
        }
        if ($acoes = $this->lista($r->query('acoes', $r->query('acao')))) {
            $q->whereIn('acao', $acoes);
        }
        if ($categorias = $this->lista($r->query('categorias'))) {
            $q->whereIn('categoria', $categorias);
        }
        if ($r->boolean('ocultar_navegacao')) {
            $q->where(fn ($w) => $w->where('categoria', '<>', 'navegacao')->orWhereNull('categoria'));
        }
        match ($r->query('resultado')) {
            'sucesso' => $q->where(fn ($w) => $w->whereNull('status_code')->orWhere('status_code', '<', 400)),
            'falha' => $q->where('status_code', '>=', 400),
            default => null,
        };
        foreach (['ip' => 'ip_address', 'sessao_id' => 'sessao_id', 'metodo' => 'metodo'] as $param => $coluna) {
            if ($v = trim((string) $r->query($param))) {
                $q->where($coluna, $v);
            }
        }
        if ($tela = trim((string) $r->query('tela'))) {
            $q->where('tela', 'like', addcslashes($tela, '%_\\') . '%');
        }

        if ($comDatas) {
            if ($ini = $this->data($r->query('data_inicio'))) {
                $q->where('criado_em', '>=', $ini->startOfDay());
            }
            if ($fim = $this->data($r->query('data_fim'))) {
                $q->where('criado_em', '<=', $fim->endOfDay());
            }
        }

        return $q;
    }

    /** Período do resumo: padrão últimos 7 dias; datas invertidas são trocadas. */
    private function periodo(Request $r): array
    {
        $fim = $this->data($r->query('data_fim')) ?? now();
        $ini = $this->data($r->query('data_inicio')) ?? $fim->copy()->subDays(6);
        if ($ini->gt($fim)) {
            [$ini, $fim] = [$fim, $ini];
        }

        return [$ini->copy()->startOfDay(), $fim->copy()->endOfDay()];
    }

    private function seguranca(Carbon $ini, Carbon $fim): array
    {
        $periodo = fn () => DB::table('logs_sistema')->whereBetween('criado_em', [$ini, $fim]);

        return [
            // Muitas falhas de login vindas do mesmo IP: possível força bruta.
            'ips_suspeitos' => $periodo()->where('acao', 'LOGIN_FALHA')
                ->selectRaw('ip_address, COUNT(*) as falhas, COUNT(DISTINCT username) as contas, MAX(criado_em) as ultima')
                ->groupBy('ip_address')->havingRaw('COUNT(*) >= 5')->orderByDesc('falhas')->limit(10)->get(),
            // Contas que mais erraram a senha.
            'contas_alvo' => $periodo()->where('acao', 'LOGIN_FALHA')
                ->selectRaw('username, COUNT(*) as falhas, COUNT(DISTINCT ip_address) as ips, MAX(criado_em) as ultima')
                ->groupBy('username')->havingRaw('COUNT(*) >= 3')->orderByDesc('falhas')->limit(10)->get(),
            // Quem tentou entrar onde não tinha permissão.
            'acessos_negados' => $periodo()->where('acao', 'ACESSO_NEGADO')
                ->selectRaw('usuario_id, MAX(username) as username, COUNT(*) as total, MAX(criado_em) as ultima')
                ->groupBy('usuario_id')->orderByDesc('total')->limit(10)->get(),
            // Logins de madrugada (22h–6h) ou no fim de semana.
            'fora_horario' => $periodo()->where('acao', 'LOGIN_SUCESSO')
                ->where(fn ($w) => $w->whereRaw('HOUR(criado_em) >= 22 OR HOUR(criado_em) < 6')
                    ->orWhereRaw('DAYOFWEEK(criado_em) IN (1, 7)'))
                ->orderByDesc('criado_em')->limit(15)
                ->get(['id', 'usuario_id', 'username', 'ip_address', 'criado_em']),
        ];
    }

    /** Quem mexeu no sistema nos últimos 15 minutos e em que tela está. */
    private function online(): array
    {
        $desde = now()->subMinutes(15);
        $usuarios = DB::table('logs_sistema')->where('criado_em', '>=', $desde)->whereNotNull('usuario_id')
            ->selectRaw('usuario_id, MAX(username) as username, MAX(criado_em) as ultima, MAX(id) as ultimo_id')
            ->groupBy('usuario_id')->orderByDesc('ultima')->limit(30)->get();

        $saiu = DB::table('logs_sistema')->whereIn('id', $usuarios->pluck('ultimo_id'))->where('acao', 'LOGOUT')->pluck('id')->all();
        $telas = DB::table('logs_sistema')
            ->whereIn('id', DB::table('logs_sistema')->where('criado_em', '>=', $desde)->where('categoria', 'navegacao')
                ->whereIn('usuario_id', $usuarios->pluck('usuario_id'))->selectRaw('MAX(id)')->groupBy('usuario_id'))
            ->get(['usuario_id', 'tela', 'dados_extras'])->keyBy('usuario_id');

        return $usuarios->reject(fn ($u) => in_array($u->ultimo_id, $saiu))->map(function ($u) use ($telas) {
            $t = $telas[$u->usuario_id] ?? null;

            return [
                'usuario_id' => $u->usuario_id,
                'username' => $u->username,
                'ultima' => $u->ultima,
                'tela' => $t->tela ?? null,
                'tela_titulo' => $t && $t->dados_extras ? (json_decode($t->dados_extras, true)['titulo'] ?? null) : null,
            ];
        })->values()->all();
    }

    private function formatar(object $row): array
    {
        $arr = (array) $row;
        $arr['dados_extras'] = $row->dados_extras ? json_decode($row->dados_extras, true) : null;
        $arr['categoria'] = $row->categoria ?? AuditoriaCatalogo::categoriaDaAcao($row->acao);

        return $arr;
    }

    private function lista(mixed $valor): array
    {
        $itens = is_array($valor) ? $valor : explode(',', (string) $valor);

        return array_values(array_filter(array_map('trim', $itens), fn ($v) => $v !== ''));
    }

    private function data(mixed $valor): ?Carbon
    {
        if (!is_string($valor) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $valor)) {
            return null;
        }

        return Carbon::createFromFormat('Y-m-d', $valor)->startOfDay();
    }
}
