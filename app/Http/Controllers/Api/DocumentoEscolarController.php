<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documentos\AcessoAlunoService;
use App\Services\Documentos\DocumentosEscolaresService;
use App\Services\Documentos\EscolaConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Documentos da secretaria além dos cinco originais (DocumentoController):
 * declarações (frequência, quitação, nada consta, transferência,
 * escolaridade), relatório de ocorrências, carteirinha, documentos da turma
 * (ata de resultados, listas) e o atestado de vaga.
 *
 * Por aluno: quem emite documentos (documentos.emitir) ou o responsável pelo aluno
 * (AcessoAlunoService). Da turma e atestado de vaga: só documentos.emitir
 * (rota com max.role).
 */
class DocumentoEscolarController extends Controller
{
    public function __construct(
        private DocumentosEscolaresService $docs,
        private AcessoAlunoService $acesso,
        private EscolaConfigService $escola,
    ) {}

    public function declaracaoFrequencia(Request $request): JsonResponse
    {
        if ($erro = $this->validarAluno($request, $aluno)) {
            return $erro;
        }
        $ano = (int) ($request->query('ano') ?: ($aluno->ano_letivo ?? now()->year));
        [$inicioPadrao, $fimPadrao] = $this->docs->periodoLetivo($ano);
        $inicio = $this->data($request->query('data_inicio')) ?? $inicioPadrao;
        $fim = $this->data($request->query('data_fim')) ?? $fimPadrao;

        return $this->resposta([
            'aluno' => $aluno,
            'frequencia' => $this->docs->frequencia($aluno->aluno_id, min($inicio, $fim), max($inicio, $fim)),
        ]);
    }

    /** Lei 12.007/2009: declaração anual de quitação — só se o ano estiver todo pago. */
    public function declaracaoQuitacao(Request $request): JsonResponse
    {
        if ($erro = $this->validarAluno($request, $aluno)) {
            return $erro;
        }
        $ano = (int) ($request->query('ano') ?: now()->year - 1);
        $situacao = $this->docs->situacaoFinanceira($aluno->aluno_id, $ano);

        if (!$situacao['total_parcelas'] && !$situacao['dispensado']) {
            return response()->json(['error' => "Não há parcelas de $ano para este aluno."], 422);
        }
        if ($situacao['vencidas'] || $situacao['a_vencer']) {
            $n = count($situacao['vencidas']) + count($situacao['a_vencer']);

            return response()->json([
                'error' => "Ainda há $n parcela(s) de $ano em aberto — a declaração de quitação só pode ser emitida com o ano todo pago.",
                'situacao' => $situacao,
            ], 422);
        }

        return $this->resposta([
            'aluno' => $aluno,
            'responsavel' => $this->docs->responsavelPrincipal($aluno->aluno_id),
            'ano' => $ano,
            'situacao' => $situacao,
        ]);
    }

    /** Nada consta financeiro: sem parcelas vencidas em aberto até hoje. */
    public function nadaConsta(Request $request): JsonResponse
    {
        if ($erro = $this->validarAluno($request, $aluno)) {
            return $erro;
        }
        $situacao = $this->docs->situacaoFinanceira($aluno->aluno_id);

        if ($situacao['vencidas']) {
            return response()->json([
                'error' => count($situacao['vencidas']) . ' parcela(s) vencida(s) em aberto, somando R$ '
                    . number_format($situacao['total_vencido'], 2, ',', '.') . ' — regularize antes de emitir o Nada Consta.',
                'situacao' => $situacao,
            ], 422);
        }

        return $this->resposta([
            'aluno' => $aluno,
            'responsavel' => $this->docs->responsavelPrincipal($aluno->aluno_id),
            'situacao' => $situacao,
        ]);
    }

    public function declaracaoTransferencia(Request $request): JsonResponse
    {
        if ($erro = $this->validarAluno($request, $aluno)) {
            return $erro;
        }
        $vida = $this->docs->vidaEscolar($aluno->aluno_id);

        return $this->resposta([
            'aluno' => $aluno,
            'motivo' => $this->docs->motivo($aluno->motivo_encerramento),
            'ultimo_ano' => $vida ? end($vida) : null,
            'situacao_financeira' => $this->docs->situacaoFinanceira($aluno->aluno_id),
        ]);
    }

    /** Escolaridade/conclusão: o que o aluno cursou em cada ano e o resultado. */
    public function declaracaoEscolaridade(Request $request): JsonResponse
    {
        if ($erro = $this->validarAluno($request, $aluno)) {
            return $erro;
        }
        $vida = $this->docs->vidaEscolar($aluno->aluno_id);
        if (!$vida) {
            return response()->json(['error' => 'Este aluno ainda não tem matrícula registrada em nenhum ano letivo.'], 422);
        }
        $ano = (int) $request->query('ano_letivo');
        $registro = collect($vida)->firstWhere('ano_letivo', $ano) ?? end($vida);
        // A declaração é de um ano: vale o número de matrícula daquele ano.
        if (!empty($registro['numero'])) {
            $aluno->matricula = $registro['numero'];
        }

        return $this->resposta(['aluno' => $aluno, 'registro' => $registro, 'anos' => $vida]);
    }

    public function relatorioOcorrencias(Request $request): JsonResponse
    {
        if ($erro = $this->validarAluno($request, $aluno)) {
            return $erro;
        }
        $ano = (int) $request->query('ano') ?: null;
        $funcoes = (array) ($request->attributes->get('max_user')->funcoes ?? []);
        $somenteFamilia = !\App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::DOCUMENTOS_EMITIR);

        return $this->resposta([
            'aluno' => $aluno,
            'ano' => $ano,
            'ocorrencias' => $this->docs->ocorrencias($aluno->aluno_id, $ano, $somenteFamilia),
        ]);
    }

    /** Carteirinha de um aluno (aluno_id) ou de toda a turma (turma_id, só secretaria). */
    public function carteirinha(Request $request): JsonResponse
    {
        if ($turmaId = (int) $request->query('turma_id')) {
            if (!$this->ehSecretaria($request)) {
                return response()->json(['error' => 'Acesso negado.'], 403);
            }
            $turma = DB::table('turmas')->where('id', $turmaId)->first(['ano_letivo']);
            if (!$turma) {
                return response()->json(['error' => 'Turma não encontrada.'], 404);
            }
            $alunos = array_values(array_filter(array_map(
                fn ($a) => $this->docs->aluno((int) $a['aluno_id']),
                $this->docs->alunosDaTurma($turmaId, (int) $turma->ano_letivo)
            )));

            return $this->resposta(['alunos' => $alunos]);
        }

        if ($erro = $this->validarAluno($request, $aluno)) {
            return $erro;
        }
        if (!$aluno->ativo) {
            return response()->json(['error' => 'A carteirinha só pode ser emitida para alunos matriculados.'], 422);
        }

        return $this->resposta(['alunos' => [$aluno]]);
    }

    public function ataResultados(Request $request): JsonResponse
    {
        $ata = $this->docs->ataResultados((int) $request->query('turma_id'));
        if (!$ata) {
            return response()->json(['error' => 'Turma não encontrada.'], 404);
        }
        if (!$ata['alunos']) {
            return response()->json(['error' => 'Esta turma não tem alunos matriculados.'], 422);
        }

        return $this->resposta($ata);
    }

    public function listaAlunos(Request $request): JsonResponse
    {
        $turma = DB::table('turmas')->where('id', (int) $request->query('turma_id'))
            ->first(['id', 'nome_turma', 'turno', 'ano_letivo', 'status']);
        if (!$turma) {
            return response()->json(['error' => 'Turma não encontrada.'], 404);
        }
        $alunos = $this->docs->alunosDaTurma($turma->id, (int) $turma->ano_letivo, false, true);
        if (!$alunos) {
            return response()->json(['error' => 'Esta turma não tem alunos matriculados.'], 422);
        }

        return $this->resposta(['turma' => $turma, 'alunos' => $alunos]);
    }

    /** Atestado de vaga: o candidato ainda não tem cadastro — só os dados da escola. */
    public function atestadoVaga(): JsonResponse
    {
        return $this->resposta([]);
    }

    // ------------------------------------------------------------------

    private function resposta(array $dados): JsonResponse
    {
        return response()->json($dados + [
            'success' => true,
            'escola' => $this->escola->paraDocumento(),
            'data_emissao' => now()->format('Y-m-d'),
        ]);
    }

    private function validarAluno(Request $request, ?object &$aluno): ?JsonResponse
    {
        $alunoId = (int) $request->query('aluno_id', 0);
        if (!$alunoId) {
            return response()->json(['error' => 'aluno_id é obrigatório.'], 400);
        }
        if (!$this->acesso->alunoAcessivel($request->attributes->get('max_user'), $alunoId)) {
            return response()->json(['error' => 'Acesso negado a este aluno.'], 403);
        }
        $aluno = $this->docs->aluno($alunoId);

        return $aluno ? null : response()->json(['error' => 'Aluno não encontrado.'], 404);
    }

    private function ehSecretaria(Request $request): bool
    {
        $funcoes = (array) ($request->attributes->get('max_user')->funcoes ?? []);

        return \App\Support\Permissoes::pode($funcoes, \App\Support\Permissoes::DOCUMENTOS_EMITIR);
    }

    private function data(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
    }
}
