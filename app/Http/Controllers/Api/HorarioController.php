<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Horarios\HorarioService;
use App\Services\Sistema\LogSistemaService;
use App\Support\Papeis;
use App\Support\Permissoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Quadro de horários: todos consultam (a família vê as turmas dos filhos);
 * montar a grade e os horários exige horarios.editar. Regras em HorarioService.
 */
class HorarioController extends Controller
{
    public function __construct(private HorarioService $svc, private LogSistemaService $log)
    {
    }

    /** Turmas que o usuário vê, horários (modelos) e o que ele pode fazer. */
    public function inicio(Request $request): JsonResponse
    {
        if (!$this->svc->disponivel()) {
            return response()->json(['data' => ['indisponivel' => true]]);
        }
        [$usuarioId, $funcoes] = $this->usuario($request);
        $equipe = Papeis::ehEquipe($funcoes);
        $editar = Permissoes::pode($funcoes, Permissoes::HORARIOS_EDITAR);
        if ($editar) {
            $this->svc->criarModelosSugeridos();
        }
        return response()->json(['data' => [
            'turmas' => $this->svc->turmas($this->svc->turmasDoUsuario($usuarioId, $equipe)),
            'modelos' => $equipe ? $this->svc->modelos() : [],
            'pode_editar' => $editar,
            'equipe' => $equipe,
            'professor_id' => Papeis::ehProfessor($funcoes) ? $this->professorId($usuarioId) : null,
            'dias' => HorarioService::DIAS,
        ]]);
    }

    public function grade(Request $request, int $turmaId): JsonResponse
    {
        if ($erro = $this->podeVer($request, $turmaId)) {
            return $erro;
        }
        $g = $this->svc->grade($turmaId);
        return $g ? response()->json(['data' => $g]) : response()->json(['error' => 'Turma não encontrada.'], 404);
    }

    public function configurar(Request $request, int $turmaId): JsonResponse
    {
        return $this->editar($request, fn ($u) => $this->svc->configurar($turmaId, (int) $request->input('modelo_id'), (array) $request->input('dias', []), $u)
            ?? $this->svc->grade($turmaId), "Horário da turma #$turmaId configurado");
    }

    public function salvarAula(Request $request, int $turmaId): JsonResponse
    {
        return $this->editar($request, fn ($u) => $this->svc->salvarAula($turmaId, (int) $request->input('dia'), (int) $request->input('faixa_id'),
            (int) $request->input('disciplina_id'), $request->input('professor_id') ? (int) $request->input('professor_id') : null,
            $request->input('sala'), $u));
    }

    public function removerAula(Request $request, int $turmaId): JsonResponse
    {
        return $this->editar($request, fn ($u) => $this->svc->removerAula($turmaId, (int) $request->query('dia'), (int) $request->query('faixa_id'), $u));
    }

    public function mover(Request $request, int $turmaId): JsonResponse
    {
        return $this->editar($request, fn ($u) => $this->svc->mover($turmaId, (array) $request->input('de'), (array) $request->input('para'), $u));
    }

    public function carga(Request $request, int $turmaId): JsonResponse
    {
        return $this->editar($request, fn () => $this->svc->definirCarga($turmaId, (int) $request->input('disciplina_id'), (int) $request->input('carga')));
    }

    public function avisar(Request $request, int $turmaId): JsonResponse
    {
        return $this->editar($request, fn () => ['familias' => $this->svc->avisar($turmaId)], "Famílias avisadas do horário da turma #$turmaId");
    }

    /** Arquivo .ics para adicionar as aulas à agenda do celular. */
    public function ics(Request $request, int $turmaId): Response|JsonResponse
    {
        if ($erro = $this->podeVer($request, $turmaId)) {
            return $erro;
        }
        $ics = $this->svc->ics($turmaId);
        if (!$ics) {
            return response()->json(['error' => 'Esta turma ainda não tem horário.'], 404);
        }
        return response($ics, 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="aulas-turma-' . $turmaId . '.ics"',
        ]);
    }

    // ── Horários (modelos) ──────────────────────────────────────────────

    public function salvarModelo(Request $request, ?int $id = null): JsonResponse
    {
        return $this->editar($request, fn () => ['id' => $this->svc->salvarModelo($id, $request->all()), 'modelos' => $this->svc->modelos()],
            'Horário (faixas) ' . ($id ? "#$id alterado" : 'criado'));
    }

    public function excluirModelo(Request $request, int $id): JsonResponse
    {
        return $this->editar($request, fn () => $this->svc->excluirModelo($id) ?? ['modelos' => $this->svc->modelos()], "Horário (faixas) #$id excluído");
    }

    // ── Leituras da equipe ──────────────────────────────────────────────

    public function professores(Request $request): JsonResponse
    {
        if (!Papeis::ehEquipe($this->usuario($request)[1])) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }
        return response()->json(['data' => $this->svc->professores()]);
    }

    public function professor(Request $request, int $id): JsonResponse
    {
        if (!Papeis::ehEquipe($this->usuario($request)[1])) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }
        return response()->json(['data' => $this->svc->agendaProfessor($id)]);
    }

    public function agora(Request $request): JsonResponse
    {
        if (!Papeis::ehEquipe($this->usuario($request)[1])) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }
        return response()->json(['data' => ['momento' => now()->format('H:i'), 'turmas' => $this->svc->agora()]]);
    }

    /** Card da home: a aula de agora e a próxima (família: de cada filho; professor: as dele). */
    public function hoje(Request $request): JsonResponse
    {
        if (!$this->svc->disponivel()) {
            return response()->json(['data' => null]);
        }
        [$usuarioId, $funcoes] = $this->usuario($request);
        return response()->json(['data' => $this->svc->hoje($usuarioId, Papeis::ehProfessor($funcoes), Papeis::ehResponsavel($funcoes))]);
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    /** @return array{0: int, 1: array} */
    private function usuario(Request $request): array
    {
        $u = $request->attributes->get('max_user');
        return [(int) ($u->id ?? 0), (array) ($u->funcoes ?? $u->roles ?? [])];
    }

    private function professorId(int $usuarioId): ?int
    {
        $id = \Illuminate\Support\Facades\DB::table('professores')->where('usuario_id', $usuarioId)->value('id');
        return $id ? (int) $id : null;
    }

    private function podeVer(Request $request, int $turmaId): ?JsonResponse
    {
        [$usuarioId, $funcoes] = $this->usuario($request);
        $visiveis = $this->svc->turmasDoUsuario($usuarioId, Papeis::ehEquipe($funcoes));
        return $visiveis === null || in_array($turmaId, $visiveis, true) ? null : response()->json(['error' => 'Turma não encontrada.'], 404);
    }

    /** Executa uma alteração (exige horarios.editar), devolve o resultado e registra no log quando há descrição. */
    private function editar(Request $request, callable $acao, ?string $log = null): JsonResponse
    {
        [$usuarioId, $funcoes] = $this->usuario($request);
        if (!Permissoes::pode($funcoes, Permissoes::HORARIOS_EDITAR)) {
            return response()->json(['error' => 'Seu perfil não pode alterar o quadro de horários.'], 403);
        }
        try {
            $dados = $acao($usuarioId);
        } catch (\DomainException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        if ($log) {
            $u = $request->attributes->get('max_user');
            $this->log->registrar($request, $usuarioId, $u->nome ?? 'desconhecido', 'HORARIO', '/' . $request->path(), $request->method(), $log, 200, []);
        }
        return response()->json(['success' => true, 'data' => $dados]);
    }
}
