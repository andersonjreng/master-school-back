<?php

namespace App\Services\Comunicacao;

use Illuminate\Support\Facades\DB;

/**
 * Ocorrências e anotações por aluno.
 *
 * - interna: anotação da equipe (a família não vê);
 * - familia: compartilhada com os responsáveis. Atenção/grave exigem que a
 *   família confirme ciência; em elogio/informativo, só se o autor pedir.
 * Professor registra para alunos das turmas dele; a gestão para qualquer um.
 * Ocorrências graves aparecem no resumo da direção.
 */
class OcorrenciaService
{
    public const TIPOS = ['elogio', 'comportamento', 'tarefa', 'atraso', 'saude', 'disciplinar', 'outro'];
    public const NIVEIS = ['info', 'atencao', 'grave'];

    /** @return array lista (filtros: aluno_id, turma_id, nivel, pendentes) */
    public function listar(PerfilComunicacao $p, array $f): array
    {
        $q = DB::table('ocorrencias as o')
            ->join('alunos as a', 'a.id', '=', 'o.aluno_id')->join('usuarios as ua', 'ua.id', '=', 'a.usuario_id')
            ->leftJoin('turmas as t', 't.id', '=', 'o.turma_id')
            ->leftJoin('usuarios as au', 'au.id', '=', 'o.autor_usuario_id')
            ->leftJoin('usuarios as cu', 'cu.id', '=', 'o.ciente_por')
            ->select('o.*', 'ua.nome_completo as aluno', 'a.foto_path', 't.nome_turma as turma', 'au.nome_completo as autor', 'cu.nome_completo as ciente_nome');

        // Escopo por perfil
        if ($p->gestor) {
            // tudo
        } elseif ($p->professorId) {
            $q->where(function ($x) use ($p) {
                $x->whereIn('o.aluno_id', DB::table('turma_aluno')->whereIn('turma_id', $p->turmasProfessor ?: [0])->select('aluno_id'))
                    ->orWhere('o.autor_usuario_id', $p->usuarioId);
            });
        } elseif ($p->ehFamilia()) {
            $q->whereIn('o.aluno_id', $p->dependentes ?: [0])->where('o.visibilidade', 'familia');
        } else {
            return [];
        }

        if (!empty($f['aluno_id'])) $q->where('o.aluno_id', (int) $f['aluno_id']);
        if (!empty($f['turma_id'])) $q->where('o.turma_id', (int) $f['turma_id']);
        if (!empty($f['nivel']) && in_array($f['nivel'], self::NIVEIS, true)) $q->where('o.nivel', $f['nivel']);
        if (!empty($f['tipo']) && in_array($f['tipo'], self::TIPOS, true)) $q->where('o.tipo', $f['tipo']);
        if (!empty($f['pendentes'])) $q->where('o.exige_ciencia', 1)->whereNull('o.ciente_em')->where('o.visibilidade', 'familia');

        return $q->orderByDesc('o.data')->orderByDesc('o.id')->limit((int) ($f['limite'] ?? 200))->get()
            ->map(fn ($o) => $this->formatar($o, $p))->values()->all();
    }

    /** @return array|string ocorrência salva, ou erro */
    public function salvar(PerfilComunicacao $p, array $d, ?int $id = null): array|string
    {
        if (!$p->ehEquipe()) {
            return 'Somente a equipe registra ocorrências.';
        }
        $alunoId = (int) ($d['aluno_id'] ?? 0);
        $tipo = (string) ($d['tipo'] ?? '');
        $nivel = (string) ($d['nivel'] ?? 'info');
        $titulo = trim((string) ($d['titulo'] ?? ''));
        $data = (string) ($d['data'] ?? now()->toDateString());
        $visibilidade = ($d['visibilidade'] ?? 'interna') === 'familia' ? 'familia' : 'interna';

        if (!$alunoId || !in_array($tipo, self::TIPOS, true) || !in_array($nivel, self::NIVEIS, true) || $titulo === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
            return 'Informe aluno, tipo, nível, data e um título.';
        }
        if (!$p->equipeDoAluno($alunoId)) {
            return 'Você só registra ocorrências de alunos das suas turmas.';
        }
        $atual = $id ? DB::table('ocorrencias')->where('id', $id)->first() : null;
        if ($id && (!$atual || ((int) $atual->autor_usuario_id !== $p->usuarioId && !$p->gestor))) {
            return 'Ocorrência não encontrada.';
        }

        // Atenção/grave compartilhadas sempre pedem ciência; nas demais, o autor escolhe.
        $exigeCiencia = $visibilidade === 'familia' && (in_array($nivel, ['atencao', 'grave'], true) || !empty($d['exige_ciencia']));

        $campos = [
            'aluno_id' => $alunoId,
            'turma_id' => DB::table('turma_aluno')->where('aluno_id', $alunoId)->value('turma_id'),
            'tipo' => $tipo, 'nivel' => $nivel, 'data' => $data,
            'titulo' => mb_substr($titulo, 0, 150),
            'descricao' => trim((string) ($d['descricao'] ?? '')) ?: null,
            'visibilidade' => $visibilidade,
            'exige_ciencia' => $exigeCiencia ? 1 : 0,
        ];
        if ($atual) {
            // Mudou o que a família vê: a ciência anterior deixa de valer.
            if ($atual->ciente_em && ($atual->titulo !== $campos['titulo'] || $atual->descricao !== $campos['descricao'] || $atual->nivel !== $nivel)) {
                $campos += ['ciente_em' => null, 'ciente_por' => null];
            }
            DB::table('ocorrencias')->where('id', $id)->update($campos);
        } else {
            $id = DB::table('ocorrencias')->insertGetId($campos + ['autor_usuario_id' => $p->usuarioId]);
        }
        return $this->buscar($id, $p);
    }

    public function excluir(int $id, PerfilComunicacao $p): ?string
    {
        $o = DB::table('ocorrencias')->where('id', $id)->first();
        if (!$o || ((int) $o->autor_usuario_id !== $p->usuarioId && !$p->gestor)) {
            return 'Ocorrência não encontrada.';
        }
        DB::table('ocorrencias')->where('id', $id)->delete();
        return null;
    }

    /** Família confirma que está ciente. */
    public function ciente(int $id, PerfilComunicacao $p): array|string
    {
        $o = DB::table('ocorrencias')->where('id', $id)->first();
        if (!$o || $o->visibilidade !== 'familia' || !in_array((int) $o->aluno_id, $p->dependentes, true)) {
            return 'Ocorrência não encontrada.';
        }
        if (!$o->ciente_em) {
            DB::table('ocorrencias')->where('id', $id)->update(['ciente_em' => now(), 'ciente_por' => $p->usuarioId]);
        }
        return $this->buscar($id, $p);
    }

    public function buscar(int $id, PerfilComunicacao $p): ?array
    {
        $lista = $this->listar($p, ['limite' => 1000]);
        foreach ($lista as $o) {
            if ($o['id'] === $id) {
                return $o;
            }
        }
        return null;
    }

    /** Alunos que o usuário pode citar numa ocorrência/recado (equipe). */
    public function alunosDaEquipe(PerfilComunicacao $p, ?string $busca = null): array
    {
        if (!$p->ehEquipe()) {
            return [];
        }
        return DB::table('turma_aluno as ta')
            ->join('turmas as t', 't.id', '=', 'ta.turma_id')->join('alunos as a', 'a.id', '=', 'ta.aluno_id')
            ->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('t.status', 'Ativa')->where('u.ativo', 1)
            ->when(!$p->gestor, fn ($q) => $q->whereIn('ta.turma_id', $p->turmasProfessor ?: [0]))
            ->when($busca, fn ($q) => $q->where('u.nome_completo', 'like', '%' . $busca . '%'))
            ->orderBy('u.nome_completo')->limit(60)
            ->get(['a.id', 'u.nome_completo', 't.id as turma_id', 't.nome_turma', 'a.foto_path'])
            ->map(fn ($a) => [
                'aluno_id' => (int) $a->id, 'nome' => $a->nome_completo, 'turma_id' => (int) $a->turma_id, 'turma' => $a->nome_turma,
                'foto_url' => \App\Services\Alunos\FotoAlunoService::url($a->foto_path),
            ])->values()->all();
    }

    private function formatar(object $o, PerfilComunicacao $p): array
    {
        $autor = (int) $o->autor_usuario_id === $p->usuarioId;
        return [
            'id' => (int) $o->id, 'aluno_id' => (int) $o->aluno_id, 'aluno' => $o->aluno, 'turma' => $o->turma,
            'foto_url' => \App\Services\Alunos\FotoAlunoService::url($o->foto_path),
            'tipo' => $o->tipo, 'nivel' => $o->nivel, 'data' => substr((string) $o->data, 0, 10),
            'titulo' => $o->titulo, 'descricao' => $o->descricao, 'visibilidade' => $o->visibilidade,
            'exige_ciencia' => (bool) $o->exige_ciencia, 'ciente_em' => $o->ciente_em,
            'ciente_nome' => $o->ciente_nome, 'autor' => $o->autor, 'criado_em' => $o->criado_em,
            'pode_gerir' => $autor || $p->gestor,
        ];
    }
}
