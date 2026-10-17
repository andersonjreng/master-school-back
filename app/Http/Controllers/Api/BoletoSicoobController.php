<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Financeiro\Sicoob\BoletoVisualService;
use App\Services\Financeiro\Sicoob\RemessaSicoobService;
use App\Services\Financeiro\Sicoob\RetornoSicoobService;
use App\Services\Sistema\LogSistemaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Equivalente a api/financeiro/convenio/{get_boleto_html,post_gerar_remessa,
 * post_importar_retorno}.php.
 */
class BoletoSicoobController extends Controller
{
    public function __construct(
        private BoletoVisualService $boletoVisual,
        private RemessaSicoobService $remessa,
        private RetornoSicoobService $retorno,
        private LogSistemaService $log,
        private \App\Services\Financeiro\FinanceiroConfigService $config,
        private \App\Services\Financeiro\Sicoob\ConvenioCobrancaService $convenios,
    ) {
    }

    public function boletoHtml(Request $request): JsonResponse
    {
        $parcelaId = (int) $request->query('parcela_id', 0);
        if (!$parcelaId) {
            return response()->json(['error' => 'Informe parcela_id.'], 400);
        }

        try {
            $html = $this->boletoVisual->gerarHtml($parcelaId);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            // TypeError/Error não são Exception — sem isso, um erro de tipo vindo da
            // biblioteca de boleto vira 500 sem corpo nenhum.
            return response()->json([
                'error' => 'Erro interno ao gerar o boleto.',
                'message' => $e->getMessage(),
                'tipo' => get_class($e),
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }

        return response()->json(['success' => true, 'html' => $html]);
    }

    public function gerarRemessa(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);

        $parcelaIds = array_map('intval', (array) $request->input('parcela_ids', []));
        $parcelaIds = array_values(array_unique(array_filter($parcelaIds)));

        if (!$parcelaIds) {
            return response()->json(['error' => 'Informe ao menos uma parcela (parcela_ids).'], 400);
        }

        try {
            $resultado = $this->remessa->gerar($parcelaIds, $usuarioId);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Erro interno ao gerar a remessa.',
                'message' => $e->getMessage(),
                'tipo' => get_class($e),
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }

        $this->log->registrar(
            $request, $usuarioId, $jwtUser->nome ?? null, 'GERAR_REMESSA_SICOOB',
            '/api/financeiro/convenio/gerar-remessa', 'POST',
            "Remessa \"{$resultado['nome_arquivo']}\" gerada com {$resultado['quantidade']} boleto(s)", 201,
            ['remessa_id' => $resultado['remessa_id'], 'quantidade' => $resultado['quantidade']]
        );

        // O conteúdo do CNAB é ISO-8859-1 (não UTF-8) — json_encode() quebraria com
        // bytes assim, por isso vai em base64. O frontend decodifica e monta o
        // arquivo pra download sem converter encoding.
        return response()->json([
            'success' => true,
            'remessa_id' => $resultado['remessa_id'],
            'nome_arquivo' => $resultado['nome_arquivo'],
            'quantidade' => $resultado['quantidade'],
            'conteudo_base64' => base64_encode($resultado['conteudo']),
            'avisos' => $resultado['avisos'],
            'puladas' => $resultado['puladas'],
        ], 201);
    }

    public function importarRetorno(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $usuarioId = (int) ($jwtUser->id ?? 0);

        $arquivo = $request->file('arquivo');
        if (!$arquivo || !$arquivo->isValid()) {
            return response()->json(['error' => 'Envie o arquivo de retorno (.RET) no campo "arquivo".'], 400);
        }

        // O arquivo real do Sicoob vem em ISO-8859-1 — lido como bytes crus, sem
        // qualquer conversão (a biblioteca espera exatamente esse encoding).
        $conteudo = file_get_contents($arquivo->getRealPath());
        $nomeArquivo = basename($arquivo->getClientOriginalName());

        if ($conteudo === false || $conteudo === '') {
            return response()->json(['error' => 'Arquivo vazio ou não pôde ser lido.'], 400);
        }

        try {
            $resultado = $this->retorno->aplicar($conteudo, $nomeArquivo, $usuarioId);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Erro interno ao processar o retorno.',
                'message' => $e->getMessage(),
                'tipo' => get_class($e),
                'arquivo' => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }

        $this->log->registrar(
            $request, $usuarioId, $jwtUser->nome ?? null, 'IMPORTAR_RETORNO_SICOOB',
            '/api/financeiro/convenio/importar-retorno', 'POST',
            "Retorno \"$nomeArquivo\" importado — {$resultado['resumo']['pagamentos']['aplicados']} baixa(s) aplicada(s)", 200,
            ['retorno_id' => $resultado['retorno_id']]
        );

        return response()->json(['success' => true] + $resultado);
    }

    // ── Central Sicoob ────────────────────────────────────────

    /** Painel da central: convênio, contadores de cada passo e status dos boletos. */
    public function resumo(): JsonResponse
    {
        $config = $this->config->todas();
        $convenio = $this->convenios->carregar('756');
        $candidatas = $convenio ? $this->remessa->candidatas($config['remessa_dias_antecedencia']) : [];

        $ultimoRetorno = DB::table('retornos_sicoob')->orderByDesc('processado_em')->first(['id', 'nome_arquivo', 'processado_em', 'quantidade_baixados']);
        $porStatus = DB::table('boletos_bancarios')->select('status', DB::raw('COUNT(*) AS total'))->groupBy('status')->pluck('total', 'status');

        return response()->json(['success' => true, 'data' => [
            'modo_cobranca'   => $config['modo_cobranca'],
            'dias_antecedencia' => $config['remessa_dias_antecedencia'],
            'convenio'        => $convenio ? [
                'ativo' => (bool) $convenio['ativo'], 'agencia' => $convenio['agencia'], 'codigo_cedente' => $convenio['codigo_cedente'],
                'carteira' => $convenio['carteira'], 'proximo_nosso_numero' => (int) $convenio['proximo_nosso_numero'],
            ] : null,
            'gerar' => [
                'prontas'    => count(array_filter($candidatas, fn ($c) => $c['pronta'])),
                'pendencias' => count(array_filter($candidatas, fn ($c) => !$c['pronta'])),
                'valor'      => round(array_sum(array_map(fn ($c) => $c['pronta'] ? $c['valor'] : 0, $candidatas)), 2),
            ],
            'enviar' => [
                'aguardando' => DB::table('remessas_sicoob')->where('status', 'gerado')->count(),
            ],
            'importar' => [
                'ultimo'          => $ultimoRetorno,
                'dias_sem_importar' => $ultimoRetorno ? (int) abs(round(now()->startOfDay()->diffInDays(\Carbon\Carbon::parse($ultimoRetorno->processado_em)->startOfDay()))) : null,
                'boletos_em_aberto' => (int) (($porStatus['enviado'] ?? 0) + ($porStatus['registrado'] ?? 0)),
            ],
            'boletos' => $porStatus,
        ]]);
    }

    public function candidatas(Request $request): JsonResponse
    {
        $dias = $request->has('dias') ? (int) $request->query('dias') : $this->config->todas()['remessa_dias_antecedencia'];
        return response()->json(['success' => true, 'dias' => $dias, 'data' => $this->remessa->candidatas($dias)]);
    }

    public function remessas(): JsonResponse
    {
        $remessas = DB::table('remessas_sicoob as r')
            ->leftJoin('usuarios as u', 'u.id', '=', 'r.gerado_por')
            ->leftJoin('usuarios as ue', 'ue.id', '=', 'r.enviado_por')
            ->orderByDesc('r.id')
            ->limit(100)
            ->get(['r.id', 'r.numero_remessa', 'r.nome_arquivo', 'r.quantidade_boletos', 'r.valor_total', 'r.status', 'r.criado_em',
                'r.enviado_em', 'u.nome_completo as gerado_por', 'ue.nome_completo as enviado_por',
                DB::raw('(r.conteudo_base64 IS NOT NULL) AS tem_arquivo')]);

        $porRemessa = DB::table('boletos_bancarios')->whereIn('remessa_id', $remessas->pluck('id'))
            ->select('remessa_id', 'status', DB::raw('COUNT(*) AS total'))->groupBy('remessa_id', 'status')->get()->groupBy('remessa_id');

        $data = $remessas->map(function ($r) use ($porRemessa) {
            $r->tem_arquivo = (bool) $r->tem_arquivo;
            $r->boletos = collect($porRemessa[$r->id] ?? [])->pluck('total', 'status');
            return $r;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function arquivoRemessa(int $id): JsonResponse
    {
        $r = DB::table('remessas_sicoob')->where('id', $id)->first(['nome_arquivo', 'conteudo_base64']);
        if (!$r || !$r->conteudo_base64) {
            return response()->json(['error' => 'Arquivo desta remessa não está guardado (remessas antigas não guardavam o arquivo).'], 404);
        }
        return response()->json(['success' => true, 'nome_arquivo' => $r->nome_arquivo, 'conteudo_base64' => $r->conteudo_base64]);
    }

    public function marcarRemessaEnviada(Request $request, int $id): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        try {
            $this->remessa->marcarEnviada($id, (int) ($jwtUser->id ?? 0));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 404);
        }
        $this->log->registrar($request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? null, 'REMESSA_SICOOB_ENVIADA',
            "/api/financeiro/sicoob/remessas/$id/enviada", 'POST', "Remessa #$id marcada como enviada ao banco", 200, ['remessa_id' => $id]);

        return response()->json(['success' => true]);
    }

    /** Prévia do retorno: o que vai acontecer com cada título, sem gravar. */
    public function analisarRetorno(Request $request): JsonResponse
    {
        $arquivo = $request->file('arquivo');
        if (!$arquivo || !$arquivo->isValid()) {
            return response()->json(['error' => 'Envie o arquivo de retorno (.RET) no campo "arquivo".'], 400);
        }
        $conteudo = file_get_contents($arquivo->getRealPath());
        if (!$conteudo) {
            return response()->json(['error' => 'Arquivo vazio ou não pôde ser lido.'], 400);
        }
        try {
            $analise = $this->retorno->analisar($conteudo, basename($arquivo->getClientOriginalName()));
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        return response()->json(['success' => true, 'data' => $analise]);
    }

    public function retornos(): JsonResponse
    {
        $data = DB::table('retornos_sicoob as r')
            ->leftJoin('usuarios as u', 'u.id', '=', 'r.processado_por')
            ->orderByDesc('r.id')->limit(100)
            ->get(['r.id', 'r.nome_arquivo', 'r.quantidade_registros', 'r.quantidade_baixados', 'r.valor_baixado', 'r.observacoes',
                'r.processado_em', 'u.nome_completo as processado_por']);
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function retornoItens(int $id): JsonResponse
    {
        $itens = DB::table('retorno_sicoob_itens as i')
            ->leftJoin('parcelas as p', 'p.id', '=', 'i.parcela_id')
            ->leftJoin('alunos as a', 'a.id', '=', 'p.aluno_id')
            ->leftJoin('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('i.retorno_id', $id)->orderBy('i.id')
            ->get(['i.*', 'u.nome_completo as aluno', 'p.numero_parcela as parcela']);
        return response()->json(['success' => true, 'data' => $itens]);
    }
}
