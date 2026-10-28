<?php

namespace App\Services\Documentos;

use App\Services\Alunos\FotoAlunoService;
use App\Services\Avaliacoes\MediaBimestralService;
use Illuminate\Support\Facades\DB;

/**
 * Dados dos documentos da secretaria (declarações, atas, listas, carteirinha).
 * Como nos demais documentos, aqui só saem os dados — a montagem visual e a
 * impressão ficam no front.
 */
class DocumentosEscolaresService
{
    private const MOTIVOS = [
        'transferencia_externa' => 'transferência para outra instituição',
        'evasao'                => 'abandono',
        'conclusao'             => 'conclusão',
        'solicitacao_familia'   => 'solicitação da família',
        'outro'                 => 'outro motivo',
    ];

    public function __construct(private MediaBimestralService $medias) {}

    /** Dados básicos do aluno + turma atual (ou a da última matrícula). */
    public function aluno(int $alunoId): ?object
    {
        $aluno = DB::selectOne("
            SELECT a.id AS aluno_id, u.nome_completo AS nome_aluno, u.ativo, a.matricula, a.cpf,
                   a.data_nascimento, a.sexo, a.status_matricula, a.foto_path,
                   a.endereco, a.numero, a.bairro, a.cidade, a.uf
            FROM alunos a JOIN usuarios u ON u.id = a.usuario_id
            WHERE a.id = ?
        ", [$alunoId]);
        if (!$aluno) {
            return null;
        }

        $turma = DB::selectOne("
            SELECT m.id AS matricula_id, m.turma_id, t.nome_turma, t.turno, m.ano_letivo, m.status,
                   m.data_inicio, m.data_fim, m.motivo_encerramento, m.motivo_detalhe
            FROM matriculas m JOIN turmas t ON t.id = m.turma_id
            WHERE m.aluno_id = ?
            ORDER BY m.ano_letivo DESC, (m.status = 'ativa') DESC, m.id DESC
            LIMIT 1
        ", [$alunoId]) ?? DB::selectOne("
            SELECT NULL AS matricula_id, t.id AS turma_id, t.nome_turma, t.turno, t.ano_letivo, 'ativa' AS status,
                   NULL AS data_inicio, NULL AS data_fim, NULL AS motivo_encerramento, NULL AS motivo_detalhe
            FROM turma_aluno ta JOIN turmas t ON t.id = ta.turma_id
            WHERE ta.aluno_id = ? ORDER BY t.ano_letivo DESC LIMIT 1
        ", [$alunoId]);

        foreach (['turma_id', 'nome_turma', 'turno', 'ano_letivo', 'status', 'data_inicio', 'data_fim', 'motivo_encerramento', 'motivo_detalhe'] as $c) {
            $aluno->{$c === 'status' ? 'status_matricula_turma' : $c} = $turma->{$c} ?? null;
        }
        $aluno->ativo = (bool) $aluno->ativo;
        $aluno->foto_url = FotoAlunoService::url($aluno->foto_path);
        unset($aluno->foto_path);

        return $aluno;
    }

    public function responsavelPrincipal(int $alunoId): ?object
    {
        return DB::selectOne("
            SELECT u.nome_completo AS nome_responsavel, r.cpf, r.telefone, ar.parentesco
            FROM aluno_responsavel ar
            JOIN responsaveis r ON r.id = ar.responsavel_id
            JOIN usuarios u ON u.id = r.usuario_id
            WHERE ar.aluno_id = ?
            ORDER BY ar.principal DESC, u.nome_completo
            LIMIT 1
        ", [$alunoId]);
    }

    /** Frequência no período: totais, faltas justificadas e quebra por mês. */
    public function frequencia(int $alunoId, string $inicio, string $fim): array
    {
        $meses = DB::select("
            SELECT DATE_FORMAT(data, '%Y-%m') AS mes, COUNT(*) AS dias,
                   SUM(presente = 1) AS presencas, SUM(presente = 0) AS faltas,
                   SUM(presente = 0 AND justificativa IS NOT NULL AND justificativa <> '') AS justificadas
            FROM frequencia
            WHERE aluno_id = ? AND data BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(data, '%Y-%m')
            ORDER BY mes
        ", [$alunoId, $inicio, $fim]);

        $tot = ['dias' => 0, 'presencas' => 0, 'faltas' => 0, 'justificadas' => 0];
        $linhas = [];
        foreach ($meses as $m) {
            $linha = [
                'mes' => $m->mes,
                'dias' => (int) $m->dias,
                'presencas' => (int) $m->presencas,
                'faltas' => (int) $m->faltas,
                'justificadas' => (int) $m->justificadas,
            ];
            $linha['percentual'] = $linha['dias'] ? round($linha['presencas'] / $linha['dias'] * 100, 1) : null;
            foreach ($tot as $k => $_) {
                $tot[$k] += $linha[$k];
            }
            $linhas[] = $linha;
        }
        $tot['percentual'] = $tot['dias'] ? round($tot['presencas'] / $tot['dias'] * 100, 1) : null;

        return ['inicio' => $inicio, 'fim' => $fim, 'totais' => $tot, 'meses' => $linhas];
    }

    /**
     * Situação financeira: parcelas em aberto (vencidas e a vencer) e o que foi
     * pago. $ano limita às parcelas com vencimento naquele ano.
     */
    public function situacaoFinanceira(int $alunoId, ?int $ano = null): array
    {
        $q = DB::table('parcelas')->where('aluno_id', $alunoId)->whereNotIn('status', ['cancelado', 'negociado']);
        if ($ano) {
            $q->whereYear('data_vencimento', $ano);
        }
        $parcelas = $q->orderBy('data_vencimento')->get([
            'id', 'numero_parcela', 'valor_final', 'data_vencimento', 'status', 'data_pagamento', 'valor_pago',
        ]);

        $hoje = now()->toDateString();
        $vencidas = $parcelas->filter(fn ($p) => $p->status !== 'pago' && $p->data_vencimento < $hoje)->values();
        $aVencer = $parcelas->filter(fn ($p) => $p->status !== 'pago' && $p->data_vencimento >= $hoje)->values();
        $pagas = $parcelas->where('status', 'pago')->values();

        $fmt = fn ($p) => [
            'numero_parcela' => (int) $p->numero_parcela,
            'data_vencimento' => $p->data_vencimento,
            'valor' => (float) $p->valor_final,
        ];

        return [
            'total_parcelas' => $parcelas->count(),
            'vencidas' => $vencidas->map($fmt)->all(),
            'total_vencido' => round($vencidas->sum('valor_final'), 2),
            'a_vencer' => $aVencer->map($fmt)->all(),
            'total_a_vencer' => round($aVencer->sum('valor_final'), 2),
            'pagas' => $pagas->count(),
            'total_pago' => round($pagas->sum(fn ($p) => (float) ($p->valor_pago ?? $p->valor_final)), 2),
            'dispensado' => $ano ? DB::table('financeiro_dispensas')->where('aluno_id', $alunoId)->where('ano_letivo', $ano)->exists() : false,
        ];
    }

    /** Anos letivos cursados, com média, frequência e resultado de cada um. */
    public function vidaEscolar(int $alunoId): array
    {
        $numero = app(\App\Services\Matriculas\NumeroMatriculaService::class)->sqlNumeroDoAno();
        $matriculas = DB::select("
            SELECT m.ano_letivo, m.turma_id, t.nome_turma, t.turno, t.status AS status_turma, m.status, m.data_inicio, m.data_fim,
                   $numero AS numero
            FROM matriculas m JOIN turmas t ON t.id = m.turma_id JOIN alunos a ON a.id = m.aluno_id
            WHERE m.aluno_id = ?
            ORDER BY m.ano_letivo, m.id
        ", [$alunoId]);

        return array_map(function ($m) use ($alunoId) {
            $ata = $this->mediasDaTurma((int) $m->turma_id, [$alunoId]);
            $notas = $ata['alunos'][$alunoId] ?? null;

            return [
                'ano_letivo' => (int) $m->ano_letivo,
                'turma_id' => (int) $m->turma_id,
                'nome_turma' => $m->nome_turma,
                'turno' => $m->turno,
                'numero' => $m->numero,
                'status' => $m->status,
                'data_inicio' => $m->data_inicio,
                'data_fim' => $m->data_fim,
                'media_geral' => $notas['media_geral'] ?? null,
                'resultado' => $this->resultado($m->status, $m->status_turma, $notas, $ata['media_minima']),
            ];
        }, $matriculas);
    }

    /**
     * Médias anuais por disciplina de cada aluno da turma (média das médias
     * bimestrais, regra do boletim) + média geral.
     *
     * @return array{disciplinas: array, media_minima: float, alunos: array<int, array>}
     */
    public function mediasDaTurma(int $turmaId, ?array $somenteAlunos = null): array
    {
        $q = DB::table('avaliacoes as av')
            ->join('disciplinas as d', 'd.id', '=', 'av.disciplina_id')
            ->join('tipos_avaliacao as ta', 'ta.id', '=', 'av.tipo_avaliacao_id')
            ->join('notas as n', 'n.avaliacao_id', '=', 'av.id')
            ->where('av.turma_id', $turmaId);
        if ($somenteAlunos) {
            $q->whereIn('n.aluno_id', $somenteAlunos);
        }
        $linhas = $q->get(['n.aluno_id', 'd.id as disciplina_id', 'd.nome_disciplina', 'av.unidade_letiva_id',
            'av.valor_maximo', 'ta.peso', 'n.nota_valor']);

        $disciplinas = [];
        $grupos = [];
        foreach ($linhas as $l) {
            $disciplinas[$l->disciplina_id] = $l->nome_disciplina;
            $grupos[$l->aluno_id][$l->disciplina_id][$l->unidade_letiva_id ?? 0][] = [
                'nota' => $l->nota_valor !== null ? (float) $l->nota_valor : null,
                'valor_maximo' => (float) $l->valor_maximo,
                'peso' => (float) $l->peso,
            ];
        }
        asort($disciplinas, SORT_LOCALE_STRING);

        $minima = $this->medias->mediaMinima($turmaId);
        $alunos = [];
        foreach ($grupos as $alunoId => $porDisciplina) {
            $anuais = [];
            foreach ($porDisciplina as $discId => $porUnidade) {
                $bimestrais = array_filter(array_map(fn ($avs) => $this->medias->media($avs), $porUnidade), fn ($m) => $m !== null);
                $anuais[$discId] = $bimestrais ? round(array_sum($bimestrais) / count($bimestrais), 1) : null;
            }
            $validas = array_filter($anuais, fn ($m) => $m !== null);
            $alunos[$alunoId] = [
                'medias' => $anuais,
                'media_geral' => $validas ? round(array_sum($validas) / count($validas), 1) : null,
                'abaixo' => count(array_filter($validas, fn ($m) => $m < $minima)),
            ];
        }

        return [
            'disciplinas' => collect($disciplinas)->map(fn ($n, $id) => ['id' => $id, 'nome' => $n])->values()->all(),
            'media_minima' => $minima,
            'alunos' => $alunos,
        ];
    }

    /** Ata de resultados finais: aluno × disciplina + frequência + resultado. */
    public function ataResultados(int $turmaId): ?array
    {
        $turma = DB::table('turmas')->where('id', $turmaId)->first(['id', 'nome_turma', 'turno', 'ano_letivo', 'status']);
        if (!$turma) {
            return null;
        }

        $alunos = $this->alunosDaTurma($turmaId, (int) $turma->ano_letivo, true);
        $medias = $this->mediasDaTurma($turmaId);
        $freq = DB::table('frequencia')
            ->whereIn('aluno_id', array_column($alunos, 'aluno_id') ?: [0])
            ->whereYear('data', $turma->ano_letivo)
            ->selectRaw('aluno_id, COUNT(*) AS dias, SUM(presente = 1) AS presencas')
            ->groupBy('aluno_id')->get()->keyBy('aluno_id');

        $linhas = array_map(function ($a) use ($medias, $freq, $turma) {
            $n = $medias['alunos'][$a['aluno_id']] ?? null;
            $f = $freq[$a['aluno_id']] ?? null;

            return $a + [
                'medias' => $n['medias'] ?? [],
                'media_geral' => $n['media_geral'] ?? null,
                'frequencia' => $f && $f->dias ? round($f->presencas / $f->dias * 100, 1) : null,
                // O resultado registrado no encerramento do ano (conselho de classe) vale mais que a conta pelas médias.
                'resultado' => ['promovido' => 'Aprovado', 'concluiu' => 'Aprovado', 'retido' => 'Reprovado'][$a['resultado_registrado'] ?? '']
                    ?? $this->resultado($a['status'], $turma->status, $n, $medias['media_minima']),
            ];
        }, $alunos);

        $resumo = array_count_values(array_column($linhas, 'resultado'));

        return [
            'turma' => $turma,
            'disciplinas' => $medias['disciplinas'],
            'media_minima' => $medias['media_minima'],
            'definitiva' => $turma->status === 'Encerrada',
            'alunos' => $linhas,
            'resumo' => $resumo,
        ];
    }

    /** Alunos da turma (pelas matrículas do ano; cai para turma_aluno se não houver). */
    public function alunosDaTurma(int $turmaId, int $ano, bool $incluirSaidas = false, bool $comResponsavel = false): array
    {
        $numeros = app(\App\Services\Matriculas\NumeroMatriculaService::class);
        $numero = $numeros->sqlNumeroDoAno();
        $resultadoSql = $numeros->porAno() ? 'm.resultado' : 'NULL';
        $rows = DB::select("
            SELECT a.id AS aluno_id, u.nome_completo AS nome_aluno, $numero AS matricula, a.data_nascimento, a.sexo,
                   m.status, m.data_fim, $resultadoSql AS resultado_registrado
            FROM matriculas m
            JOIN alunos a ON a.id = m.aluno_id
            JOIN usuarios u ON u.id = a.usuario_id
            WHERE m.turma_id = ? AND m.ano_letivo = ?" . ($incluirSaidas ? '' : " AND m.status IN ('ativa', 'concluida')") . "
            ORDER BY u.nome_completo
        ", [$turmaId, $ano]);

        if (!$rows) {
            $rows = DB::select("
                SELECT a.id AS aluno_id, u.nome_completo AS nome_aluno, a.matricula, a.data_nascimento, a.sexo,
                       'ativa' AS status, NULL AS data_fim, NULL AS resultado_registrado
                FROM turma_aluno ta JOIN alunos a ON a.id = ta.aluno_id JOIN usuarios u ON u.id = a.usuario_id
                WHERE ta.turma_id = ?" . ($incluirSaidas ? '' : ' AND u.ativo = 1') . "
                ORDER BY u.nome_completo
            ", [$turmaId]);
        }

        // Um aluno pode ter mais de uma matrícula na turma (saiu e voltou): fica a mais "viva".
        $porAluno = [];
        foreach ($rows as $r) {
            $atual = $porAluno[$r->aluno_id] ?? null;
            if (!$atual || $r->status === 'ativa') {
                $porAluno[$r->aluno_id] = (array) $r;
            }
        }
        $alunos = array_values($porAluno);

        if ($comResponsavel && $alunos) {
            $resp = DB::table('aluno_responsavel as ar')
                ->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
                ->join('usuarios as u', 'u.id', '=', 'r.usuario_id')
                ->whereIn('ar.aluno_id', array_column($alunos, 'aluno_id'))
                ->orderByDesc('ar.principal')
                ->get(['ar.aluno_id', 'u.nome_completo', 'r.telefone'])
                ->groupBy('aluno_id');
            foreach ($alunos as &$a) {
                $r = $resp[$a['aluno_id']][0] ?? null;
                $a['responsavel'] = $r->nome_completo ?? null;
                $a['telefone'] = $r->telefone ?? null;
            }
            unset($a);
        }

        return $alunos;
    }

    public function ocorrencias(int $alunoId, ?int $ano, bool $somenteFamilia): array
    {
        $q = DB::table('ocorrencias as o')
            ->leftJoin('usuarios as u', 'u.id', '=', 'o.autor_usuario_id')
            ->leftJoin('turmas as t', 't.id', '=', 'o.turma_id')
            ->where('o.aluno_id', $alunoId);
        if ($ano) {
            $q->whereYear('o.data', $ano);
        }
        if ($somenteFamilia) {
            $q->where('o.visibilidade', 'familia');
        }

        return $q->orderBy('o.data')->orderBy('o.id')->get([
            'o.id', 'o.data', 'o.tipo', 'o.nivel', 'o.titulo', 'o.descricao', 'o.visibilidade',
            'o.exige_ciencia', 'o.ciente_em', 'u.nome_completo as autor', 't.nome_turma',
        ])->all();
    }

    public function motivo(?string $codigo): ?string
    {
        return $codigo ? (self::MOTIVOS[$codigo] ?? $codigo) : null;
    }

    /** Período padrão da declaração de frequência: do início do ano letivo até hoje. */
    public function periodoLetivo(int $ano): array
    {
        // Há bimestres com data zerada (0000-00-00) em bancos antigos: ficam de fora.
        $unidades = fn () => DB::table('unidades_letivas')->where('ano_letivo', $ano);
        $inicio = $unidades()->where('data_inicio', '>', '1900-01-01')->min('data_inicio');
        $fimAno = $unidades()->where('data_fim', '>', '1900-01-01')->max('data_fim');
        $hoje = now()->toDateString();
        $fim = $fimAno && $fimAno < $hoje ? $fimAno : $hoje;

        return [$inicio ?: "$ano-01-01", $ano < now()->year ? ($fimAno ?: "$ano-12-31") : $fim];
    }

    private function resultado(?string $statusMatricula, ?string $statusTurma, ?array $notas, float $minima): string
    {
        return match (true) {
            $statusMatricula === 'transferida' => 'Transferido',
            $statusMatricula === 'cancelada' => 'Desistente',
            !$notas || $notas['media_geral'] === null => 'Sem notas',
            $statusTurma !== 'Encerrada' && $statusMatricula !== 'concluida' => 'Em curso',
            $notas['abaixo'] > 0 => 'Reprovado',
            default => 'Aprovado',
        };
    }
}
