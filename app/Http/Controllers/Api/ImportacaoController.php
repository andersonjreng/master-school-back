<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Importacao\ImportacaoService;
use App\Services\Sistema\LogSistemaService;
use App\Support\Permissoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Importação de alunos e responsáveis por planilha (implantação da escola).
 * Exige pessoas.gerenciar; criar turmas novas exige também turmas.gerenciar.
 * Regras em ImportacaoService.
 */
class ImportacaoController extends Controller
{
    /** Uma escola média tem algumas centenas de alunos; acima disso, em partes. */
    private const MAX_LINHAS = 3000;

    public function __construct(private ImportacaoService $svc, private LogSistemaService $log)
    {
    }

    /** O que a tela precisa: colunas reconhecidas, anos e as últimas importações. */
    public function inicio(Request $request): JsonResponse
    {
        if ($erro = $this->acesso($request)) {
            return $erro;
        }
        if (!$this->svc->disponivel()) {
            return response()->json(['data' => ['indisponivel' => true]]);
        }
        $anos = DB::table('turmas')->distinct()->orderByDesc('ano_letivo')->pluck('ano_letivo')->map(fn ($a) => (int) $a)->all();
        $atual = (int) now()->year;
        return response()->json(['data' => [
            'campos' => collect(ImportacaoService::CAMPOS)->map(fn ($c, $k) => ['chave' => $k, 'rotulo' => $c[0], 'grupo' => $c[1], 'nomes' => $c[2]])->values(),
            'grupos' => ImportacaoService::GRUPOS,
            'anos' => array_values(array_unique(array_merge([$atual + 1, $atual], $anos))),
            'ano_sugerido' => $atual,
            'cria_turmas' => $this->podeCriarTurmas($request),
            'historico' => $this->svc->historico(),
        ]]);
    }

    public function analisar(Request $request): JsonResponse
    {
        if ($erro = $this->acesso($request) ?? $this->validar($request)) {
            return $erro;
        }
        $r = $this->svc->analisar((array) $request->input('linhas'), (int) $request->input('ano'), $this->escolhas($request));
        $r['linhas'] = array_map(fn ($l) => array_diff_key($l, ['_dados' => 1, '_resps' => 1]), $r['linhas']);
        return response()->json(['data' => $r]);
    }

    public function executar(Request $request): JsonResponse
    {
        if ($erro = $this->acesso($request) ?? $this->validar($request)) {
            return $erro;
        }
        $ano = (int) $request->input('ano');
        $escolhas = $this->escolhas($request);
        if (!$this->podeCriarTurmas($request)) {
            $plano = $this->svc->analisar((array) $request->input('linhas'), $ano, $escolhas);
            if ($plano['resumo']['turmas_novas'] > 0) {
                return response()->json(['error' => 'Seu perfil não cria turmas. Escolha uma turma existente para cada turma da planilha.'], 403);
            }
        }
        $u = $request->attributes->get('max_user');
        $r = $this->svc->executar((array) $request->input('linhas'), $ano, $escolhas, $request->boolean('senha_cpf', true),
            $request->input('arquivo'), (int) ($u->id ?? 0));
        $this->log->registrar($request, (int) ($u->id ?? 0), $u->nome ?? 'desconhecido', 'IMPORTACAO', '/' . $request->path(), 'POST',
            "Importação #{$r['id']}: {$r['alunos']} alunos, {$r['responsaveis']} responsáveis, {$r['turmas']} turmas novas", 200,
            ['arquivo' => $request->input('arquivo'), 'ano' => $ano, 'falhas' => count($r['falhas'])]);
        return response()->json(['success' => true, 'data' => $r]);
    }

    public function desfazer(Request $request, int $id): JsonResponse
    {
        if ($erro = $this->acesso($request)) {
            return $erro;
        }
        try {
            $r = $this->svc->desfazer($id);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $u = $request->attributes->get('max_user');
        $this->log->registrar($request, (int) ($u->id ?? 0), $u->nome ?? 'desconhecido', 'IMPORTACAO', '/' . $request->path(), 'DELETE',
            "Importação #$id desfeita ({$r['alunos']} alunos removidos)", 200, []);
        return response()->json(['success' => true, 'data' => ['historico' => $this->svc->historico()]]);
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    private function acesso(Request $request): ?JsonResponse
    {
        return Permissoes::pode($this->funcoes($request), Permissoes::PESSOAS_GERENCIAR) ? null
            : response()->json(['error' => 'Seu perfil não cadastra alunos e responsáveis.'], 403);
    }

    private function validar(Request $request): ?JsonResponse
    {
        $linhas = $request->input('linhas');
        $ano = (int) $request->input('ano');
        return match (true) {
            !is_array($linhas) || !$linhas => response()->json(['error' => 'A planilha não tem linhas.'], 422),
            count($linhas) > self::MAX_LINHAS => response()->json(['error' => 'Planilha grande demais: importe em partes de até ' . self::MAX_LINHAS . ' alunos.'], 422),
            $ano < 2000 || $ano > (int) now()->year + 2 => response()->json(['error' => 'Ano letivo inválido.'], 422),
            default => null,
        };
    }

    /** Turma escolhida para cada turma da planilha; id de turma que não é do ano volta a ser "decidir sozinho". */
    private function escolhas(Request $request): array
    {
        $validas = DB::table('turmas')->where('ano_letivo', (int) $request->input('ano'))->where('status', 'Ativa')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $out = [];
        foreach ((array) $request->input('turmas', []) as $chave => $escolha) {
            $escolha = (int) $escolha;
            if (in_array($escolha, [ImportacaoService::TURMA_NOVA, ImportacaoService::TURMA_IGNORAR], true) || in_array($escolha, $validas, true)) {
                $out[(string) $chave] = $escolha;
            }
        }
        return $out;
    }

    private function podeCriarTurmas(Request $request): bool
    {
        return Permissoes::pode($this->funcoes($request), Permissoes::TURMAS_GERENCIAR);
    }
}
