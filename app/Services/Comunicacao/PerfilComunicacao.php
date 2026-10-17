<?php

namespace App\Services\Comunicacao;

use App\Support\Papeis;
use Illuminate\Support\Facades\DB;

/**
 * Quem é o usuário na Central de Comunicação (montado uma vez por requisição):
 * gestão (Administrador/Diretoria), professor (e as turmas dele) e/ou família
 * (dependentes e as turmas deles). Um usuário pode ter mais de um papel.
 */
class PerfilComunicacao
{
    public int $usuarioId;
    public string $nome;
    public bool $gestor;
    public ?int $professorId = null;
    /** @var int[] */
    public array $turmasProfessor = [];
    public ?int $responsavelId = null;
    /** @var int[] alunos dependentes */
    public array $dependentes = [];
    /** @var int[] turmas atuais dos dependentes */
    public array $turmasFamilia = [];

    public static function de(object $jwtUser): self
    {
        $p = new self();
        $p->usuarioId = (int) ($jwtUser->id ?? 0);
        $p->nome = (string) ($jwtUser->nome ?? '');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        $p->gestor = Papeis::ehGestor($funcoes);

        $prof = DB::table('professores')->where('usuario_id', $p->usuarioId)->value('id');
        if ($prof) {
            $p->professorId = (int) $prof;
            $p->turmasProfessor = DB::table('turma_professor_disciplina as tpd')
                ->join('turmas as t', 't.id', '=', 'tpd.turma_id')->where('t.status', 'Ativa')
                ->where('tpd.professor_id', $p->professorId)->distinct()->pluck('tpd.turma_id')
                ->map(fn ($id) => (int) $id)->all();
        }

        $resp = DB::table('responsaveis')->where('usuario_id', $p->usuarioId)->value('id');
        if ($resp) {
            $p->responsavelId = (int) $resp;
            $p->dependentes = DB::table('aluno_responsavel')->where('responsavel_id', $p->responsavelId)
                ->pluck('aluno_id')->map(fn ($id) => (int) $id)->all();
            $p->turmasFamilia = $p->dependentes ? DB::table('turma_aluno')->whereIn('aluno_id', $p->dependentes)
                ->distinct()->pluck('turma_id')->map(fn ($id) => (int) $id)->all() : [];
        }
        return $p;
    }

    public function ehEquipe(): bool
    {
        return $this->gestor || $this->professorId !== null;
    }

    public function ehFamilia(): bool
    {
        return $this->responsavelId !== null;
    }

    /** O professor leciona para o aluno (alguma turma atual do aluno é dele)? */
    public function lecionaPara(int $alunoId): bool
    {
        if (!$this->turmasProfessor) {
            return false;
        }
        return DB::table('turma_aluno')->where('aluno_id', $alunoId)->whereIn('turma_id', $this->turmasProfessor)->exists();
    }

    /** Pode registrar/ver ocorrências e conversar sobre o aluno pelo lado da escola? */
    public function equipeDoAluno(int $alunoId): bool
    {
        return $this->gestor || $this->lecionaPara($alunoId);
    }
}
