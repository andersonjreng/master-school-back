<?php

namespace App\Services\Demo;

use App\Services\Avaliacoes\ModeloAvaliacaoService;
use App\Services\Financeiro\FinanceiroHelperService;
use App\Services\Financeiro\MatriculaFinanceiraService;
use App\Services\Horarios\HorarioService;
use App\Services\Rematricula\RematriculaService;
use App\Services\Turmas\CalendarioEscolarService;
use App\Support\Papeis;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Escola de demonstração: completa o banco até uma data (normalmente hoje)
 * com dados coerentes para apresentar o sistema — e algumas pendências de
 * propósito, para os alertas aparecerem.
 *
 * Só completa o que falta (pode rodar de novo antes de outra apresentação e
 * os dados "chegam até hoje"). As escolhas são determinísticas (mesmo aluno,
 * mesmo perfil), então rodar duas vezes não bagunça o que já foi gerado.
 *
 * NUNCA rodar em banco de escola real: o comando demo:alimentar barra produção.
 */
class DemoAlimentarService
{
    public const SENHA_DEMO = 'demo1234';

    private Carbon $ate;
    private int $ano;
    /** @var callable(string): void */
    private $saida;
    private array $mapa = [];
    private array $letivos = [];
    private ?int $admin = null;

    public function __construct(
        private CalendarioEscolarService $calendario,
        private ModeloAvaliacaoService $modelos,
        private MatriculaFinanceiraService $matriculas,
        private FinanceiroHelperService $financeiro,
        private RematriculaService $rematricula,
        private HorarioService $horarios,
    ) {
    }

    /** @param callable(string): void $saida */
    public function executar(Carbon $ate, bool $nomes, callable $saida): void
    {
        $this->ate = $ate->copy()->startOfDay();
        $this->ano = $this->ate->year;
        $this->saida = $saida;
        $this->admin = (int) DB::table('usuarios as u')->join('usuario_funcao as uf', 'uf.usuario_id', '=', 'u.id')
            ->join('funcoes as f', 'f.id', '=', 'uf.funcao_id')->where('f.nome_funcao', Papeis::ADMINISTRADOR)->where('u.ativo', 1)
            ->orderBy('u.id')->value('u.id');

        if ($nomes) {
            $this->anonimizar();
        }
        $this->ajustarCalendario();
        $turmas = DB::table('turmas')->where('ano_letivo', $this->ano)->where('status', 'Ativa')->orderBy('nome_turma')->get();
        $this->quadroDeHorarios($turmas);
        $this->chamadasEConteudos($turmas);
        $this->avaliacoesENotas($turmas->whereNull('etapa_bncc'));
        $this->bncc($turmas->whereNotNull('etapa_bncc'));
        $this->planejamento($turmas->whereNull('etapa_bncc'));
        $this->comunicacao($turmas);
        $this->financeiroDoAno($turmas);
        $this->rematriculaDoAnoSeguinte($turmas);
        // Turmas do ano seguinte (criadas na rematrícula) também com a grade pronta.
        $this->quadroDeHorarios(DB::table('turmas')->where('ano_letivo', $this->ano + 1)->where('status', 'Ativa')->orderBy('nome_turma')->get());
        $this->novoDiagnosticoDoMax();
    }

    /** O diagnóstico do Max é feito uma vez por dia: apaga o de hoje para ele refazer com os dados novos. */
    private function novoDiagnosticoDoMax(): void
    {
        if (Schema::hasTable('max_briefings')) {
            $n = DB::table('max_briefings')->where('data', now()->toDateString())->delete();
            $this->dizer($n ? 'Max: diagnóstico de hoje apagado — refeito na próxima abertura da home' : 'Max: sem diagnóstico de hoje para refazer');
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Nomes fictícios
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Troca nomes, e-mails, CPFs, telefones e endereços de alunos, famílias e
     * professores por dados fictícios (famílias com o mesmo sobrenome). A
     * equipe administrativa mantém nome, e-mail e senha (login de quem
     * apresenta). Professores e responsáveis ficam com a senha demo1234.
     */
    private function anonimizar(): void
    {
        mt_srand(2027);
        $usuarios = DB::table('usuarios as u')->leftJoin('usuario_funcao as uf', 'uf.usuario_id', '=', 'u.id')
            ->leftJoin('funcoes as f', 'f.id', '=', 'uf.funcao_id')->groupBy('u.id', 'u.nome_completo', 'u.email')->orderBy('u.id')
            ->get(['u.id', 'u.nome_completo', 'u.email', DB::raw('GROUP_CONCAT(f.nome_funcao) as funcoes')]);
        $alunoDoUsuario = DB::table('alunos')->pluck('id', 'usuario_id')->all();
        $respDoUsuario = DB::table('responsaveis')->pluck('id', 'usuario_id')->all();

        // Irmãos e responsáveis em comum formam uma família (mesmo sobrenome).
        $pai = [];
        $raiz = function ($x) use (&$pai, &$raiz) { return ($pai[$x] ?? $x) === $x ? $x : ($pai[$x] = $raiz($pai[$x])); };
        foreach (DB::table('aluno_responsavel')->get(['aluno_id', 'responsavel_id']) as $v) {
            $a = $raiz('a' . $v->aluno_id);
            $r = $raiz('r' . $v->responsavel_id);
            if ($a !== $r) {
                $pai[$a] = $r;
            }
        }
        $S = NomesFicticios::SOBRENOMES;
        $sorteia = fn (array $l) => $l[mt_rand(0, count($l) - 1)];
        $usados = [];
        $unico = function (callable $gerar) use (&$usados) {
            do { $n = $gerar(); } while (isset($usados[$n]));
            return $usados[$n] = $n;
        };
        $familias = [];
        $familia = function (string $no) use (&$familias, $raiz, $sorteia, $S) {
            $k = $raiz($no);
            if (!isset($familias[$k])) {
                $f = ['s1' => $sorteia($S), 's2' => $sorteia($S), 'resp' => 0];
                if ($f['s1'] === $f['s2']) {
                    $f['s2'] = $S[(array_search($f['s2'], $S) + 1) % count($S)];
                }
                $familias[$k] = $f;
            }
            return $k;
        };

        $senha = Hash::make(self::SENHA_DEMO);
        $trocados = 0;
        foreach ($usuarios as $u) {
            $funcoes = array_filter(explode(',', (string) $u->funcoes));
            $id = (int) $u->id;
            $equipeAdm = array_intersect($funcoes, Papeis::ADMINISTRATIVOS) && !in_array(Papeis::PROFESSOR, $funcoes, true);
            if ($equipeAdm && !isset($alunoDoUsuario[$id]) && !isset($respDoUsuario[$id])) {
                continue;
            }
            if (isset($alunoDoUsuario[$id])) {
                $f = $familias[$familia('a' . $alunoDoUsuario[$id])];
                $sexo = mt_rand(0, 1) ? 'F' : 'M';
                $novo = $unico(fn () => $sorteia($sexo === 'F' ? NomesFicticios::NOMES_F : NomesFicticios::NOMES_M) . " {$f['s1']} {$f['s2']}");
                DB::table('alunos')->where('usuario_id', $id)->update(['sexo' => $sexo]);
            } elseif (isset($respDoUsuario[$id])) {
                $k = $familia('r' . $respDoUsuario[$id]);
                $mae = $familias[$k]['resp']++ % 2 === 0;
                ['s1' => $s1, 's2' => $s2] = $familias[$k];
                $novo = $unico(fn () => $mae ? $sorteia(NomesFicticios::ADULTOS_F) . " $s1 $s2" : $sorteia(NomesFicticios::ADULTOS_M) . " $s2");
            } else {
                $novo = $unico(fn () => $sorteia(mt_rand(0, 2) ? NomesFicticios::ADULTOS_F : NomesFicticios::ADULTOS_M) . ' ' . $sorteia($S));
            }
            $this->mapa[$u->nome_completo] = $novo;
            $dados = ['nome_completo' => $novo, 'telefone' => $this->telefone()];
            if ($u->email) {
                $dados['email'] = $this->email($novo, $id);
            }
            if (!isset($alunoDoUsuario[$id])) {
                $dados['senha'] = $senha;
            }
            DB::table('usuarios')->where('id', $id)->update($dados);
            $trocados++;
        }

        foreach (DB::table('alunos')->pluck('id') as $id) {
            [$rua, $bairro] = $this->endereco();
            DB::table('alunos')->where('id', $id)->update(['cpf' => $this->cpf(), 'endereco' => $rua, 'numero' => (string) mt_rand(10, 2400),
                'complemento' => null, 'bairro' => $bairro, 'cidade' => 'Campinas', 'uf' => 'SP', 'cep' => $this->cep(), 'foto_path' => null]);
        }
        foreach (DB::table('responsaveis')->pluck('id') as $id) {
            [$rua, $bairro] = $this->endereco();
            DB::table('responsaveis')->where('id', $id)->update(['cpf' => $this->cpf(), 'telefone' => $this->telefone(), 'endereco' => $rua,
                'numero' => (string) mt_rand(10, 2400), 'complemento' => null, 'bairro' => $bairro, 'cidade' => 'Campinas', 'uf' => 'SP', 'cep' => $this->cep()]);
        }
        foreach (DB::table('professores')->pluck('id') as $id) {
            DB::table('professores')->where('id', $id)->update(['cpf' => $this->cpf(), 'registro_funcional' => 'RF' . mt_rand(1000, 9999), 'salario' => null]);
        }
        // Nomes antigos que ficaram gravados em textos (recibos).
        foreach ($this->mapa as $antigo => $novo) {
            DB::table('recibos')->where('responsavel_nome', $antigo)->update(['responsavel_nome' => $novo]);
        }
        $this->dizer("nomes fictícios: $trocados pessoas (professores e responsáveis com a senha " . self::SENHA_DEMO . ')');
    }

    // ═════════════════════════════════════════════════════════════════════
    // Calendário
    // ═════════════════════════════════════════════════════════════════════

    /** Bimestres com datas, semestres da Ed. Infantil e feriados nacionais (só o que faltar). */
    private function ajustarCalendario(): void
    {
        $a = $this->ano;
        $padrao = [["$a-02-02", "$a-04-30"], ["$a-05-04", "$a-07-10"], ["$a-07-27", "$a-09-30"], ["$a-10-01", "$a-12-18"]];
        $unidades = DB::table('unidades_letivas')->where('ano_letivo', $a)->orderBy('id')->get();
        if ($unidades->isEmpty()) {
            foreach ($padrao as $i => [$ini, $fim]) {
                DB::table('unidades_letivas')->insert(['nome_unidade' => ($i + 1) . 'º Bimestre', 'data_inicio' => $ini, 'data_fim' => $fim, 'ano_letivo' => $a, 'ativo' => 1]);
            }
        } else {
            foreach ($unidades->values() as $i => $u) {
                $valida = fn ($d) => $d && $d !== '0000-00-00';
                if ((!$valida($u->data_inicio) || !$valida($u->data_fim)) && isset($padrao[$i])) {
                    DB::table('unidades_letivas')->where('id', $u->id)->update(['data_inicio' => $padrao[$i][0], 'data_fim' => $padrao[$i][1]]);
                }
            }
        }
        if (Schema::hasTable('semestres') && !DB::table('semestres')->where('ano_letivo', $a)->exists()) {
            DB::table('semestres')->insert([
                ['nome' => '1º Semestre', 'ano_letivo' => $a, 'data_inicio' => "$a-02-01", 'data_fim' => "$a-07-10"],
                ['nome' => '2º Semestre', 'ano_letivo' => $a, 'data_inicio' => "$a-07-27", 'data_fim' => "$a-12-18"],
            ]);
        }
        if (!DB::table('calendario_escolar')->where('ano_letivo', $a)->where('tipo', 'feriado')->exists()) {
            foreach ($this->calendario->feriadosNacionais($a) as $dia => $nome) {
                DB::table('calendario_escolar')->insert(['ano_letivo' => $a, 'data_inicio' => $dia, 'data_fim' => $dia, 'tipo' => 'feriado',
                    'descricao' => $nome, 'origem' => 'nacional', 'criado_em' => now(), 'atualizado_em' => now()]);
            }
        }
        $mapa = $this->calendario->mapa($a);
        $this->letivos = $this->calendario->diasLetivos($mapa, Carbon::create($a, 1, 1), Carbon::create($a, 12, 31));
        $this->dizer('calendário: ' . count($this->letivos) . " dias letivos em $a");
    }

    private function letivosAte(Carbon $limite): array
    {
        $l = $limite->toDateString();
        return array_values(array_filter($this->letivos, fn ($d) => $d <= $l));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Quadro de horários
    // ═════════════════════════════════════════════════════════════════════

    /** Grade completa das turmas sem horário, evitando o mesmo professor em duas turmas ao mesmo tempo. */
    private function quadroDeHorarios(Collection $turmas): void
    {
        if (!$this->horarios->disponivel()) {
            return;
        }
        $this->horarios->criarModelosSugeridos();
        $modelos = collect($this->horarios->modelos());
        if ($turmas->isEmpty()) {
            return;
        }
        $ocupado = [];
        // Choque de professor só conta dentro do mesmo ano letivo.
        foreach (DB::table('horario_aulas as a')->join('turmas as tt', 'tt.id', '=', 'a.turma_id')->where('tt.ano_letivo', $turmas->first()->ano_letivo)->leftJoin('turma_professor_disciplina as v', fn ($j) => $j->on('v.turma_id', '=', 'a.turma_id')
            ->on('v.disciplina_id', '=', 'a.disciplina_id'))->get(['a.turma_id', 'a.dia_semana', 'a.faixa_id', 'a.professor_id', 'v.professor_id as pv']) as $a) {
            if ($p = $a->professor_id ?: $a->pv) {
                $ocupado["$p:{$a->dia_semana}:{$a->faixa_id}"] = true;
            }
        }
        $peso = fn (string $n) => match (true) {
            str_contains($n, 'Portuguesa'), str_contains($n, 'Matemática') => 5, str_contains($n, 'Ciências') => 3,
            str_contains($n, 'História'), str_contains($n, 'Geografia'), str_contains($n, 'Inglesa'), str_contains($n, 'Física'), str_contains($n, 'Arte') => 2,
            default => 1,
        };
        $montadas = 0;
        foreach ($turmas as $t) {
            if (DB::table('horario_aulas')->where('turma_id', $t->id)->exists()) {
                continue;
            }
            $vinc = DB::table('turma_professor_disciplina as v')->join('disciplinas as d', 'd.id', '=', 'v.disciplina_id')
                ->where('v.turma_id', $t->id)->get(['v.disciplina_id', 'v.professor_id', 'd.nome_disciplina']);
            if ($vinc->isEmpty()) {
                continue;
            }
            $modelo = $modelos->firstWhere('turno', $t->turno) ?? $modelos->first();
            $this->horarios->configurar($t->id, $modelo['id'], [1, 2, 3, 4, 5], (int) $this->admin);
            $faixas = collect($modelo['faixas'])->where('tipo', 'aula')->pluck('id')->values();
            $casas = 5 * count($faixas);
            $soma = $vinc->sum(fn ($v) => $peso($v->nome_disciplina));
            $meta = $vinc->mapWithKeys(fn ($v) => [$v->disciplina_id => max(1, (int) floor($peso($v->nome_disciplina) * $casas / $soma))])->all();
            $sobra = $casas - array_sum($meta);
            foreach ($vinc->sortByDesc(fn ($v) => $peso($v->nome_disciplina)) as $v) {
                if ($sobra-- <= 0) {
                    break;
                }
                $meta[$v->disciplina_id]++;
            }
            foreach ($meta as $d => $m) {
                DB::table('turma_professor_disciplina')->where('turma_id', $t->id)->where('disciplina_id', $d)->update(['carga_horaria_semanal' => $m]);
            }
            $prof = $vinc->pluck('professor_id', 'disciplina_id')->all();
            $usadas = [];
            foreach ([1, 2, 3, 4, 5] as $dia) {
                $noDia = [];
                foreach ($faixas as $faixa) {
                    $cand = collect($meta)->map(fn ($m, $d) => ['d' => $d, 'falta' => $m - ($usadas[$d] ?? 0)])
                        ->filter(fn ($c) => $c['falta'] > 0 && count(array_keys($noDia, $c['d'])) < 2)->sortByDesc('falta')->values();
                    $e = $cand->first(fn ($c) => !$prof[$c['d']] || !isset($ocupado["{$prof[$c['d']]}:$dia:$faixa"])) ?? $cand->first();
                    if (!$e) {
                        continue;
                    }
                    DB::table('horario_aulas')->insert(['turma_id' => $t->id, 'dia_semana' => $dia, 'faixa_id' => $faixa, 'disciplina_id' => $e['d']]);
                    if ($prof[$e['d']]) {
                        $ocupado["{$prof[$e['d']]}:$dia:$faixa"] = true;
                    }
                    $usadas[$e['d']] = ($usadas[$e['d']] ?? 0) + 1;
                    $noDia[] = $e['d'];
                }
            }
            $montadas++;
        }
        $this->dizer("quadro de horários: $montadas turma(s) montada(s)");
    }

    // ═════════════════════════════════════════════════════════════════════
    // Chamadas e conteúdos
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Chamada de todo dia letivo até a data, e o conteúdo das aulas do dia
     * (as disciplinas do quadro de horários daquele dia da semana).
     * Pendências de propósito: uma turma com as 3 últimas chamadas atrasadas;
     * poucos alunos com frequência abaixo de 75%.
     */
    private function chamadasEConteudos(Collection $turmas): void
    {
        $hoje = $this->ate->toDateString();
        $dias = $this->letivosAte($this->ate);
        $atrasada = $turmas->whereNull('etapa_bncc')->sortBy('nome_turma')->last();
        $nomesDisc = DB::table('disciplinas')->pluck('nome_disciplina', 'id')->all();

        // Chamadas lançadas em dia não letivo (sobras de teste) aparecem como pendência no Diário.
        $naoLetivos = array_keys($this->calendario->mapa($this->ano)['nao_letivos']);
        if ($naoLetivos) {
            DB::table('frequencia')->whereIn('data', $naoLetivos)->delete();
        }

        $chamadas = 0;
        $registros = 0;
        foreach ($turmas as $t) {
            $alunos = $this->alunosDaTurma((int) $t->id);
            if (!$alunos) {
                continue;
            }
            $diasTurma = $t->id === $atrasada?->id ? array_slice($dias, 0, max(0, count($dias) - 3)) : $dias;
            // Hoje: só quem já teve aula a esta hora fez a chamada (manhã depois das 9h, tarde depois das 14h).
            $jaTeveAula = $t->turno === 'Tarde' ? now()->hour >= 14 : now()->hour >= 9;
            if (end($diasTurma) === $hoje && (!$jaTeveAula || $this->ate->toDateString() !== now()->toDateString())) {
                array_pop($diasTurma);
            }
            $tem = DB::table('frequencia')->whereIn('aluno_id', $alunos)->whereYear('data', $this->ano)->get(['aluno_id', 'data'])
                ->mapWithKeys(fn ($f) => ["{$f->aluno_id}:{$f->data}" => true])->all();
            $linhas = [];
            foreach ($diasTurma as $d) {
                foreach ($alunos as $a) {
                    if (isset($tem["$a:$d"])) {
                        continue;
                    }
                    $faltoso = $this->h("faltoso$a") % 23 === 0;
                    $presente = $this->h("f$a$d") % 100 >= ($faltoso ? 28 : 4);
                    $linhas[] = ['aluno_id' => $a, 'data' => $d, 'presente' => $presente ? 1 : 0,
                        'justificativa' => !$presente && $this->h("j$a$d") % 4 === 0 ? 'Atestado médico apresentado pela família.' : null];
                }
            }
            foreach (array_chunk($linhas, 1000) as $lote) {
                DB::table('frequencia')->insertOrIgnore($lote);
            }
            $chamadas += count($linhas);

            // Conteúdo das aulas do dia, pelas disciplinas do quadro de horários.
            $vinc = DB::table('turma_professor_disciplina')->where('turma_id', $t->id)->whereNotNull('professor_id')->pluck('professor_id', 'disciplina_id')->all();
            if (!$vinc) {
                continue;
            }
            $grade = Schema::hasTable('horario_aulas') ? DB::table('horario_aulas')->where('turma_id', $t->id)->get(['dia_semana', 'disciplina_id'])
                ->groupBy('dia_semana')->map(fn ($g) => $g->countBy('disciplina_id')->all())->all() : [];
            $temReg = DB::table('registros_aula')->where('turma_id', $t->id)->whereYear('data', $this->ano)->get(['disciplina_id', 'data'])
                ->mapWithKeys(fn ($r) => ["{$r->disciplina_id}:{$r->data}" => true])->all();
            $uso = [];
            $novos = [];
            foreach ($diasTurma as $d) {
                $dow = Carbon::parse($d)->dayOfWeekIso;
                $doDia = $grade[$dow] ?? array_fill_keys(array_slice(array_keys($vinc), $this->h("g$d") % max(1, count($vinc) - 2), 3), 1);
                foreach ($doDia as $disc => $n) {
                    if (!isset($vinc[$disc]) || isset($temReg["$disc:$d"])) {
                        continue;
                    }
                    $lista = $this->conteudos($nomesDisc[$disc] ?? '', $t->etapa_bncc !== null);
                    $uso[$disc] = ($uso[$disc] ?? -1) + 1;
                    $novos[] = ['turma_id' => $t->id, 'professor_id' => $vinc[$disc], 'disciplina_id' => $disc, 'data' => $d,
                        'disciplina' => $nomesDisc[$disc] ?? '', 'conteudo' => $lista[($uso[$disc] + $this->h("c$t->id$disc")) % count($lista)],
                        'numero_aulas' => $n, 'criado_em' => "$d 12:00:00", 'atualizado_em' => "$d 12:00:00"];
                }
            }
            foreach (array_chunk($novos, 500) as $lote) {
                DB::table('registros_aula')->insertOrIgnore($lote);
            }
            $registros += count($novos);
        }
        $this->dizer("chamadas: +$chamadas presenças/faltas; conteúdos: +$registros aulas registradas"
            . ($atrasada ? " (de propósito: {$atrasada->nome_turma} com as 3 últimas chamadas atrasadas)" : ''));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Avaliações e notas (Ensino Fundamental)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Em cada disciplina e bimestre já começado: as avaliações do modelo da
     * turma (trabalho, teste, prova) ao longo do bimestre; notas lançadas até
     * 2 dias atrás. Pendências de propósito: avaliações recentes ainda sem
     * nota, poucos alunos abaixo da média, provas futuras no bimestre atual.
     */
    private function avaliacoesENotas(Collection $turmas): void
    {
        $unidades = DB::table('unidades_letivas')->where('ano_letivo', $this->ano)->where('data_inicio', '<=', $this->ate->toDateString())
            ->orderBy('data_inicio')->get(['id', 'nome_unidade', 'data_inicio', 'data_fim']);
        $nomesDisc = DB::table('disciplinas')->pluck('nome_disciplina', 'id')->all();
        $limiteNota = $this->ate->copy()->subDays(2)->toDateString();
        $criadas = 0;
        $notas = 0;
        $primeira = true;

        foreach ($turmas as $t) {
            $alunos = $this->alunosDaTurma((int) $t->id);
            $modelo = collect($this->modelos->modelosDaTurma((int) $t->id))
                ->map(fn ($id) => ['id' => $id, 'tipos' => DB::table('tipos_avaliacao')->where('modelo_avaliacao_id', $id)->orderBy('ordem')->orderBy('id')->get(['id', 'nome_tipo', 'peso'])])
                ->sortByDesc(fn ($m) => count($m['tipos']))->first();
            if (!$modelo || !$alunos) {
                continue;
            }
            $vinc = DB::table('turma_professor_disciplina')->where('turma_id', $t->id)->pluck('professor_id', 'disciplina_id')->all();
            $usuarioDoProf = DB::table('professores')->pluck('usuario_id', 'id')->all();
            foreach ($unidades as $u) {
                $ini = Carbon::parse($u->data_inicio);
                $fim = Carbon::parse($u->data_fim);
                $letivosBim = array_values(array_filter($this->letivos, fn ($d) => $d >= $u->data_inicio && $d <= $u->data_fim));
                foreach ($vinc as $disc => $prof) {
                    if (DB::table('avaliacoes')->where('turma_id', $t->id)->where('disciplina_id', $disc)->where('unidade_letiva_id', $u->id)->exists()) {
                        continue;
                    }
                    $n = count($modelo['tipos']);
                    foreach ($modelo['tipos']->values() as $i => $tipo) {
                        // Espalhadas pelo bimestre; a última (prova) perto do fim.
                        $alvo = $letivosBim ? $letivosBim[(int) floor((count($letivosBim) - 1) * ($n === 1 ? 0.85 : (0.3 + 0.55 * $i / ($n - 1))))] : $ini->toDateString();
                        // Uma avaliação aplicada ontem, ainda sem nota (pendência do professor).
                        if ($primeira && $u->data_fim >= $this->ate->toDateString() && $i === 0) {
                            $alvo = $this->letivosAte($this->ate->copy()->subDay()) ? last($this->letivosAte($this->ate->copy()->subDay())) : $alvo;
                            $primeira = false;
                        }
                        DB::table('avaliacoes')->insert([
                            'turma_id' => $t->id, 'disciplina_id' => $disc, 'tipo_avaliacao_id' => $tipo->id, 'professor_id' => $prof,
                            'descricao' => "{$tipo->nome_tipo} de " . ($nomesDisc[$disc] ?? ''), 'data_aplicacao' => $alvo,
                            'valor_maximo' => $tipo->peso, 'data_cadastro' => $ini->copy()->addDays(3)->toDateTimeString(), 'unidade_letiva_id' => $u->id,
                        ]);
                        $criadas++;
                    }
                }
            }

            // Notas: todo aluno nas avaliações aplicadas até 2 dias atrás; as existentes ficam com valores realistas.
            $avaliacoes = DB::table('avaliacoes as a')->join('unidades_letivas as u', 'u.id', '=', 'a.unidade_letiva_id')
                ->where('a.turma_id', $t->id)->where('u.ano_letivo', $this->ano)->get(['a.id', 'a.valor_maximo', 'a.data_aplicacao', 'a.professor_id']);
            foreach ($avaliacoes as $av) {
                $existentes = DB::table('notas')->where('avaliacao_id', $av->id)->pluck('id', 'aluno_id')->all();
                foreach ($existentes as $aluno => $notaId) {
                    DB::table('notas')->where('id', $notaId)->update(['nota_valor' => $this->nota((int) $aluno, (int) $av->id, (float) $av->valor_maximo)]);
                }
                if ($av->data_aplicacao > $limiteNota) {
                    continue;
                }
                $lancadoEm = Carbon::parse($av->data_aplicacao)->addDays(2 + $this->h("l$av->id") % 4)->setTime(19, 30);
                $novas = [];
                foreach ($alunos as $a) {
                    if (!isset($existentes[$a])) {
                        $novas[] = ['avaliacao_id' => $av->id, 'aluno_id' => $a, 'nota_valor' => $this->nota($a, (int) $av->id, (float) $av->valor_maximo),
                            'segunda_chamada' => 0, 'professor_id' => $av->professor_id, 'usuario_id_lancamento' => $usuarioDoProf[$av->professor_id] ?? $this->admin,
                            'data_lancamento' => $lancadoEm->min($this->ate->copy()->setTime(8, 0))->toDateTimeString()];
                    }
                }
                foreach (array_chunk($novas, 500) as $lote) {
                    DB::table('notas')->insert($lote);
                }
                $notas += count($novas);
            }
        }
        $this->dizer("avaliações: +$criadas; notas: +$notas (avaliações dos últimos 2 dias ficam sem nota, de propósito)");
    }

    /** Cada aluno tem um nível (poucos com dificuldade); a nota varia em torno dele, de meio em meio ponto. */
    private function nota(int $aluno, int $avaliacao, float $max): float
    {
        $dificuldade = $this->h("dif$aluno") % 17 === 0;
        $nivel = $dificuldade ? 0.40 + ($this->h("n$aluno") % 16) / 100 : 0.62 + ($this->h("n$aluno") % 36) / 100;
        $fator = max(0.2, min(1, $nivel + (($this->h("v$aluno:$avaliacao") % 19) - 10) / 100));
        return round($max * $fator * 2) / 2;
    }

    // ═════════════════════════════════════════════════════════════════════
    // BNCC (Educação Infantil)
    // ═════════════════════════════════════════════════════════════════════

    /** Registros de desenvolvimento por semestre: o 1º completo, o 2º pela metade. */
    private function bncc(Collection $turmas): void
    {
        if (!Schema::hasTable('registros_bncc')) {
            return;
        }
        $semestres = DB::table('semestres')->where('ano_letivo', $this->ano)->where('data_inicio', '<=', $this->ate->toDateString())->orderBy('data_inicio')->get();
        $total = 0;
        foreach ($turmas as $t) {
            $alunos = $this->alunosDaTurma((int) $t->id);
            $habilidades = DB::table('habilidades_bncc')->where('etapa', $t->etapa_bncc)->where('ativo', 1)->orderBy('ordem')->orderBy('id')->limit(12)->pluck('id')->all();
            $prof = DB::table('turma_professor_disciplina')->where('turma_id', $t->id)->whereNotNull('professor_id')->value('professor_id');
            foreach ($semestres->values() as $i => $s) {
                $habs = $i === 0 || $s->data_fim < $this->ate->toDateString() ? $habilidades : array_slice($habilidades, 0, (int) ceil(count($habilidades) / 2));
                $tem = DB::table('registros_bncc')->where('turma_id', $t->id)->where('semestre_id', $s->id)->get(['aluno_id', 'habilidade_id'])
                    ->mapWithKeys(fn ($r) => ["{$r->aluno_id}:{$r->habilidade_id}" => true])->all();
                $linhas = [];
                foreach ($alunos as $a) {
                    foreach ($habs as $h) {
                        if (!isset($tem["$a:$h"])) {
                            $nivel = $this->h("b$a:$h:$s->id") % 10;
                            $linhas[] = ['aluno_id' => $a, 'habilidade_id' => $h, 'semestre_id' => $s->id, 'turma_id' => $t->id,
                                'nivel' => $nivel < 1 ? 1 : ($nivel < 5 ? 2 : 3), 'professor_id' => $prof];
                        }
                    }
                }
                foreach (array_chunk($linhas, 500) as $lote) {
                    DB::table('registros_bncc')->insert($lote);
                }
                $total += count($linhas);
            }
        }
        $this->dizer("BNCC (Infantil): +$total registros de desenvolvimento");
    }

    // ═════════════════════════════════════════════════════════════════════
    // Planejamento
    // ═════════════════════════════════════════════════════════════════════

    /** Aulas planejadas para os próximos dias letivos; parte aprovada, parte aguardando a coordenação. */
    private function planejamento(Collection $turmas): void
    {
        if (!Schema::hasTable('planos_aula')) {
            return;
        }
        DB::table('configuracoes_escola')->updateOrInsert(['chave' => 'planejamento_aprovacao'], ['valor' => '1']);
        $proximos = array_slice(array_values(array_filter($this->letivos, fn ($d) => $d > $this->ate->toDateString())), 0, 5);
        $nomesDisc = DB::table('disciplinas')->pluck('nome_disciplina', 'id')->all();
        $criados = 0;
        foreach ($turmas as $t) {
            $vinc = DB::table('turma_professor_disciplina')->where('turma_id', $t->id)->whereNotNull('professor_id')->pluck('professor_id', 'disciplina_id')->all();
            $grade = DB::table('horario_aulas')->where('turma_id', $t->id)->get(['dia_semana', 'disciplina_id'])->groupBy('dia_semana')
                ->map(fn ($g) => $g->pluck('disciplina_id')->unique()->values()->all())->all();
            $serie = preg_match('/(\d+)/', $t->nome_turma, $m) ? (int) $m[1] : 0;
            foreach ($proximos as $k => $d) {
                $discs = array_slice($grade[Carbon::parse($d)->dayOfWeekIso] ?? array_keys($vinc), 0, 2);
                foreach ($discs as $disc) {
                    if (!isset($vinc[$disc]) || DB::table('planos_aula')->where('turma_id', $t->id)->where('disciplina_id', $disc)->where('data', $d)->exists()) {
                        continue;
                    }
                    $nome = $nomesDisc[$disc] ?? '';
                    $lista = $this->conteudos($nome, false);
                    $aprovacao = $k < 2 ? 'aprovado' : ($k < 4 ? 'pendente' : 'rascunho');
                    DB::table('planos_aula')->insert([
                        'turma_id' => $t->id, 'disciplina_id' => $disc, 'professor_id' => $vinc[$disc], 'data' => $d,
                        'tema' => $lista[($this->h("p$t->id$disc$d")) % count($lista)],
                        'objetivos' => 'Compreender o tema da aula e aplicar em atividades práticas.',
                        'habilidades' => json_encode($this->habilidadesEF($serie, $nome)),
                        'desenvolvimento' => "Retomada da aula anterior (10 min).\nExplicação com exemplos no quadro (15 min).\nAtividade em duplas e correção coletiva (20 min).",
                        'recursos' => 'Livro didático, quadro e material impresso.', 'avaliacao' => 'Participação e atividade em sala.',
                        'aprovacao' => $aprovacao, 'aprovado_por' => $aprovacao === 'aprovado' ? $this->admin : null,
                        'aprovado_em' => $aprovacao === 'aprovado' ? now() : null, 'gerado_max' => $this->h("mx$t->id$disc$d") % 3 === 0 ? 1 : 0,
                        'criado_por' => DB::table('professores')->where('id', $vinc[$disc])->value('usuario_id'), 'criado_em' => now(), 'atualizado_em' => now(),
                    ]);
                    $criados++;
                }
            }
        }
        $this->dizer("planejamento: +$criados aulas planejadas (parte aguardando aprovação)");
    }

    private function habilidadesEF(int $serie, string $disciplina): array
    {
        $comp = match (true) {
            str_contains($disciplina, 'Portuguesa') => 'LP', str_contains($disciplina, 'Matemática') => 'MA', str_contains($disciplina, 'Ciências') => 'CI',
            str_contains($disciplina, 'História') => 'HI', str_contains($disciplina, 'Geografia') => 'GE', str_contains($disciplina, 'Arte') => 'AR',
            default => null,
        };
        if (!$comp || $serie < 1 || !Schema::hasTable('bncc_habilidades')) {
            return [];
        }
        return DB::table('bncc_habilidades')->where('codigo', 'like', sprintf('EF%02d%s%%', $serie, $comp))->orderBy('codigo')->limit(2)->pluck('codigo')->all();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Comunicação
    // ═════════════════════════════════════════════════════════════════════

    /** Comunicados recentes no mural, ocorrências (algumas aguardando ciência) e recados. Só se ainda não houver no último mês. */
    private function comunicacao(Collection $turmas): void
    {
        $quando = fn (string $rel) => $this->ate->copy()->setTime(now()->hour, now()->minute)->modify($rel)->format('Y-m-d H:i:s');
        $autor = (int) (DB::table('usuarios as u')->join('usuario_funcao as uf', 'uf.usuario_id', '=', 'u.id')->join('funcoes as f', 'f.id', '=', 'uf.funcao_id')
            ->whereIn('f.nome_funcao', [Papeis::SECRETARIA, Papeis::DIRETORIA])->where('u.ativo', 1)->value('u.id') ?: $this->admin);
        $fundamental = $turmas->whereNull('etapa_bncc')->first();
        $feitos = [];

        if (Schema::hasTable('mural_posts') && !DB::table('mural_posts')->where('criado_em', '>=', $this->ate->copy()->subDays(20))->exists()) {
            $festa = $this->ate->copy()->addDays(12)->next(Carbon::SATURDAY);
            $reuniao = $this->ate->copy()->addDays(9)->next(Carbon::THURSDAY);
            $posts = [
                ['familias', null, 'Cardápio da semana', "Segunda: arroz, feijão, frango assado e salada.\nTerça: macarrão à bolonhesa.\nQuarta: escondidinho de carne.\nQuinta: peixe com purê.\nSexta: estrogonofe de frango.", 0, 0, '-5 days'],
                ['escola', null, 'Festa da Primavera — ' . $festa->locale('pt_BR')->isoFormat('D [de] MMMM'), "Convidamos todas as famílias para a nossa Festa da Primavera, das 9h às 13h.\nTeremos apresentações das turmas, barracas e brincadeiras.\nContamos com vocês!", 1, 0, '-3 days'],
                ['familias', null, 'Reunião de pais do 4º bimestre', "A reunião de pais será na " . $reuniao->locale('pt_BR')->isoFormat('dddd, D [de] MMMM') . ", às 19h, no auditório.\nPor favor, confirme a ciência deste comunicado.", 0, 1, '-1 day'],
                ['equipe', null, 'Fechamento do bimestre', "Professores: lançar todas as notas e registros de aula até o fim do bimestre.\nDúvidas, procurem a coordenação.", 0, 0, '-2 days'],
            ];
            if ($fundamental) {
                $posts[] = ['turmas', json_encode([(int) $fundamental->id]), "Passeio ao Museu de Ciências — {$fundamental->nome_turma}", "Saída às 8h e retorno às 12h. Os alunos devem vir de uniforme e trazer lanche.\nConfirme a ciência para autorizar a participação.", 0, 1, '-4 hours'];
            }
            foreach ($posts as [$publico, $ts, $titulo, $texto, $fixado, $ciencia, $rel]) {
                DB::table('mural_posts')->insert(['autor_usuario_id' => $autor, 'titulo' => $titulo, 'texto' => $texto, 'publico' => $publico, 'turmas' => $ts,
                    'fixado' => $fixado, 'exige_ciencia' => $ciencia, 'oculto' => 0, 'criado_em' => $quando($rel), 'atualizado_em' => $quando($rel)]);
            }
            $feitos[] = count($posts) . ' comunicados';
        }

        if (!DB::table('ocorrencias')->where('data', '>=', $this->ate->copy()->subDays(30)->toDateString())->exists()) {
            $modelos = [
                ['elogio', 'info', 'Excelente participação', 'Ajudou os colegas na atividade em grupo e apresentou o trabalho com muita clareza.', 0],
                ['tarefa', 'atencao', 'Tarefas não entregues', 'Não entregou as tarefas de casa nos últimos três dias. Pedimos o acompanhamento da família.', 1],
                ['comportamento', 'atencao', 'Conversa durante a aula', 'Precisou ser chamado à atenção várias vezes durante a explicação.', 1],
                ['saude', 'info', 'Mal-estar no recreio', 'Relatou dor de cabeça após o recreio; descansou na secretaria e melhorou.', 0],
                ['atraso', 'info', 'Chegada atrasada', 'Chegou 30 minutos após o início das aulas.', 0],
                ['disciplinar', 'grave', 'Desentendimento com colega', 'Houve um desentendimento físico no recreio. A coordenação conversou com os envolvidos.', 1],
                ['elogio', 'info', 'Destaque na leitura', 'Leu em voz alta para a turma com ótima fluência.', 0],
            ];
            $n = 0;
            foreach ($turmas->values() as $i => $t) {
                $alunos = $this->alunosDaTurma((int) $t->id);
                if (!$alunos || $i % 2) {
                    continue;
                }
                [$tipo, $nivel, $titulo, $desc, $ciencia] = $modelos[$n % count($modelos)];
                $data = $this->ate->copy()->subDays(1 + $n * 3);
                DB::table('ocorrencias')->insert(['aluno_id' => $alunos[$this->h("oc$t->id") % count($alunos)], 'turma_id' => $t->id, 'autor_usuario_id' => $autor,
                    'tipo' => $tipo, 'nivel' => $nivel, 'data' => $data->toDateString(), 'titulo' => $titulo, 'descricao' => $desc, 'visibilidade' => 'familia',
                    'exige_ciencia' => $ciencia, 'ciente_em' => $ciencia && $n % 3 === 2 ? $data->copy()->addDay()->setTime(20, 0) : null,
                    'criado_em' => $data->copy()->setTime(11, 0), 'atualizado_em' => $data->copy()->setTime(11, 0)]);
                $n++;
            }
            $feitos[] = "$n ocorrências";
        }

        if (Schema::hasTable('recados_conversas') && $fundamental && !DB::table('recados_conversas')->where('criado_em', '>=', $this->ate->copy()->subDays(20))->exists()) {
            $prof = DB::table('turma_professor_disciplina as v')->join('professores as p', 'p.id', '=', 'v.professor_id')->where('v.turma_id', $fundamental->id)->value('p.usuario_id');
            $alunos = $this->alunosDaTurma((int) $fundamental->id);
            foreach ([['Tarefa de Matemática', 'O caderno de Matemática ficou na escola hoje. Pode conferir com ele(a) a tarefa da página 42? Obrigada!', '-3 hours'],
                ['Material para a aula de Arte', 'Amanhã vamos usar tinta guache. Se possível, mandar uma camiseta velha para proteger o uniforme.', '-1 day']] as $i => [$assunto, $texto, $rel]) {
                if (!$prof || !isset($alunos[$i])) {
                    break;
                }
                $id = DB::table('recados_conversas')->insertGetId(['aluno_id' => $alunos[$i], 'escola_usuario_id' => $prof, 'assunto' => $assunto, 'criado_por' => $prof,
                    'criado_em' => $quando($rel), 'ultima_mensagem_em' => $quando($rel), 'encerrada' => 0]);
                DB::table('recados_mensagens')->insert(['conversa_id' => $id, 'autor_usuario_id' => $prof, 'lado' => 'escola', 'texto' => $texto, 'criado_em' => $quando($rel)]);
            }
            $feitos[] = 'recados';
        }
        $this->dizer('comunicação: ' . ($feitos ? implode(', ', $feitos) : 'já havia do último mês'));
    }

    // ═════════════════════════════════════════════════════════════════════
    // Financeiro
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Contrato do ano para quem não tem; parcelas vencidas pagas (baixa e
     * recibo), menos as de algumas famílias — que ficam em atraso de
     * propósito (inadimplência). Cobranças do Asaas não são mexidas.
     */
    private function financeiroDoAno(Collection $turmas): void
    {
        $plano = $this->planoDoAno($this->ano, 9000, $turmas->pluck('id')->all());
        $contratos = 0;
        $semCobranca = Schema::hasTable('financeiro_dispensas') ? DB::table('financeiro_dispensas')->where('ano_letivo', $this->ano)->pluck('aluno_id')->all() : [];
        foreach ($turmas as $t) {
            $planoTurma = (int) (DB::table('plano_pagamento_turma as pt')->join('planos_pagamento as p', 'p.id', '=', 'pt.plano_id')
                ->where('pt.turma_id', $t->id)->where('p.ativo', 1)->value('p.id') ?: $plano);
            foreach ($this->alunosDaTurma((int) $t->id) as $a) {
                if (in_array($a, $semCobranca) || $this->matriculas->jaMatriculado($a, (string) $this->ano)) {
                    continue;
                }
                $irmao = $this->h("ir$a") % 9 === 0;
                $this->matriculas->criar((int) $this->admin, ['aluno_id' => $a, 'plano_id' => $planoTurma, 'ano_letivo' => (string) $this->ano,
                    'data_inicio' => "{$this->ano}-01-01", 'desconto_extra_tipo' => $irmao ? 'percentual' : null, 'desconto_extra_valor' => $irmao ? 10 : null,
                    'desconto_extra_descricao' => $irmao ? 'Desconto de irmão' : '', 'observacoes' => '']);
                $contratos++;
            }
        }

        // Famílias em atraso (de propósito): por responsável financeiro.
        $hoje = $this->ate->toDateString();
        $abertas = DB::table('parcelas as p')->join('matriculas_financeiras as mf', 'mf.id', '=', 'p.matricula_financeira_id')
            ->where('mf.ano_letivo', (string) $this->ano)->where('mf.status', 'ativa')->whereIn('p.status', ['pendente', 'vencido'])
            ->where('p.data_vencimento', '<', $hoje)->orderBy('p.data_vencimento')
            ->get(['p.id', 'p.aluno_id', 'p.valor_final', 'p.data_vencimento']);
        $comAsaas = Schema::hasTable('asaas_cobrancas') ? DB::table('asaas_cobrancas')->where('ativa', 1)->pluck('parcela_id')->flip()->all() : [];
        $pagas = 0;
        $atraso = [];
        foreach ($abertas as $p) {
            if (isset($comAsaas[$p->id])) {
                continue;
            }
            $pagador = DB::table('aluno_responsavel')->where('aluno_id', $p->aluno_id)->orderByDesc('principal')->value('responsavel_id');
            $meses = Carbon::parse($p->data_vencimento)->diffInMonths($this->ate);
            if ($this->h("dev$pagador") % 9 === 0 && $meses < 1 + $this->h("m$pagador") % 3) {
                $atraso[$pagador] = true;
                continue;
            }
            $pagoEm = Carbon::parse($p->data_vencimento)->subDays($this->h("pg$p->id") % 6)->min($this->ate)->toDateString();
            DB::table('parcelas')->where('id', $p->id)->update(['status' => 'pendente']);
            $forma = ['pix', 'pix', 'boleto', 'dinheiro', 'cartao_debito'][$this->h("fm$p->id") % 5];
            $this->financeiro->aplicarBaixaParcela((int) $p->id, (float) $p->valor_final, $pagoEm, $forma, 'Pagamento registrado.', (int) $this->admin);
            $pagas++;
        }
        DB::statement("UPDATE parcelas SET status = 'vencido' WHERE status = 'pendente' AND data_vencimento < ?", [$hoje]);
        $this->dizer("financeiro: +$contratos contratos; $pagas parcelas pagas com recibo; " . count($atraso) . ' família(s) em atraso de propósito');
    }

    /** Plano "Mensalidade {ano}" (12x) ligado às turmas que ainda não têm plano. */
    private function planoDoAno(int $ano, float $valor, array $turmas): int
    {
        $id = (int) DB::table('planos_pagamento')->where('ativo', 1)->where('nome', 'like', "%$ano%")->value('id');
        if (!$id) {
            $id = (int) DB::table('planos_pagamento')->insertGetId(['nome' => "Mensalidade $ano", 'descricao' => "12 parcelas — ano letivo $ano",
                'valor_total' => $valor, 'numero_parcelas' => 12, 'dia_vencimento' => 10, 'ativo' => 1, 'criado_em' => now(), 'atualizado_em' => now()]);
        }
        foreach ($turmas as $t) {
            $temPlano = DB::table('plano_pagamento_turma as pt')->join('planos_pagamento as p', 'p.id', '=', 'pt.plano_id')->where('pt.turma_id', $t)->where('p.ativo', 1)->exists();
            if (!$temPlano) {
                DB::table('plano_pagamento_turma')->insertOrIgnore(['plano_id' => $id, 'turma_id' => $t]);
            }
        }
        return $id;
    }

    // ═════════════════════════════════════════════════════════════════════
    // Rematrícula do ano seguinte
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Turmas do ano seguinte (cópia das atuais), plano, campanha aberta e
     * um retrato realista: parte confirmada (app e secretaria), parte faltando
     * pagar, algumas famílias que não vão renovar e o resto sem resposta.
     */
    private function rematriculaDoAnoSeguinte(Collection $turmas): void
    {
        if (!$this->rematricula->disponivel()) {
            $this->dizer('rematrícula: migrations ainda não rodadas — pulado');
            return;
        }
        $destino = $this->ano + 1;
        DB::table('anos_letivos')->insertOrIgnore(['ano' => $destino]);
        // "3º Ano - 2027" e "3º Ano" são a mesma série: compara sem o ano no nome.
        $serie = fn (string $n) => mb_strtolower(trim(preg_replace('/\s*[-–]?\s*\b(19|20)\d{2}\b\s*/u', ' ', $n)));
        $existentes = DB::table('turmas')->where('ano_letivo', $destino)->get(['id', 'nome_turma'])->mapWithKeys(fn ($x) => [$serie($x->nome_turma) => (int) $x->id])->all();
        foreach ($turmas as $t) {
            if ($ja = $existentes[$serie($t->nome_turma)] ?? null) {
                // Turma do ano seguinte criada sem disciplinas: leva as da turma atual (com os professores).
                if (!DB::table('turma_professor_disciplina')->where('turma_id', $ja)->exists()) {
                    foreach (DB::table('turma_professor_disciplina')->where('turma_id', $t->id)->get() as $v) {
                        DB::table('turma_professor_disciplina')->insertOrIgnore(['turma_id' => $ja, 'disciplina_id' => $v->disciplina_id,
                            'professor_id' => $v->professor_id, 'carga_horaria_semanal' => $v->carga_horaria_semanal]);
                    }
                }
                continue;
            }
            $nova = DB::table('turmas')->insertGetId(['nome_turma' => $t->nome_turma, 'turno' => $t->turno, 'capacidade' => $t->capacidade,
                'status' => 'Ativa', 'ano_letivo' => $destino, 'sistema_avaliacao_id' => $t->sistema_avaliacao_id, 'etapa_bncc' => $t->etapa_bncc]);
            foreach (DB::table('turma_professor_disciplina')->where('turma_id', $t->id)->get() as $v) {
                DB::table('turma_professor_disciplina')->insertOrIgnore(['turma_id' => $nova, 'disciplina_id' => $v->disciplina_id,
                    'professor_id' => $v->professor_id, 'carga_horaria_semanal' => $v->carga_horaria_semanal]);
            }
        }
        $this->planoDoAno($destino, 9600, DB::table('turmas')->where('ano_letivo', $destino)->where('status', 'Ativa')->pluck('id')->all());

        $c = DB::table('rematricula_campanhas')->where('ano_destino', $destino)->first();
        if (!$c) {
            $nova = $this->rematricula->novaSugerida();
            $id = $this->rematricula->salvar(null, array_merge($nova, [
                'ano_destino' => $destino, 'inicio' => $this->ate->copy()->subDays(14)->toDateString(), 'fim' => $this->ate->copy()->addDays(21)->toDateString(),
                'cobranca' => 'primeira_parcela', 'prazo_pagamento_dias' => 5,
                'desconto_tipo' => 'percentual', 'desconto_valor' => 5, 'desconto_ate' => $this->ate->copy()->addDays(7)->toDateString(),
            ]), (int) $this->admin);
            // Direto no banco: abrir pela tela avisa todas as famílias (sino e push).
            DB::table('rematricula_campanhas')->where('id', $id)->update(['status' => 'aberta']);
            $c = DB::table('rematricula_campanhas')->where('id', $id)->first();
        }
        // Campanha que já existia: as turmas que ficaram de fora ganham a turma sugerida do ano novo.
        foreach ($this->rematricula->sugerirTurmas($destino) as $s) {
            if ($s['turma_destino_id']) {
                DB::table('rematricula_turmas')->insertOrIgnore(['campanha_id' => $c->id, 'turma_origem_id' => $s['turma_origem_id'], 'turma_destino_id' => $s['turma_destino_id']]);
            }
        }
        if ($c->status !== 'aberta' || $c->fim < $this->ate->toDateString()) {
            DB::table('rematricula_campanhas')->where('id', $c->id)->update(['status' => 'aberta',
                'fim' => max($c->fim, $this->ate->copy()->addDays(14)->toDateString())]);
        }

        $this->rematricula->semCobrancaExterna();
        $mapa = DB::table('rematricula_turmas')->where('campanha_id', $c->id)->pluck('turma_destino_id', 'turma_origem_id')->all();
        $planoDestino = fn ($turma) => (int) DB::table('plano_pagamento_turma as pt')->join('planos_pagamento as p', 'p.id', '=', 'pt.plano_id')
            ->where('pt.turma_id', $turma)->where('p.ativo', 1)->value('p.id');
        $conta = ['app' => 0, 'secretaria' => 0, 'falta_pagar' => 0, 'nao_renova' => 0];
        foreach ($turmas as $t) {
            if (!isset($mapa[$t->id])) {
                continue;
            }
            foreach ($this->alunosDaTurma((int) $t->id) as $a) {
                if (DB::table('rematriculas')->where('campanha_id', $c->id)->where('aluno_id', $a)->exists()) {
                    continue;
                }
                $sorte = $this->h("rm$a") % 100;
                $pagadorUsuario = (int) DB::table('aluno_responsavel as ar')->join('responsaveis as r', 'r.id', '=', 'ar.responsavel_id')
                    ->where('ar.aluno_id', $a)->orderByDesc('ar.principal')->orderBy('r.id')->value('r.usuario_id');
                try {
                    if ($sorte < 30 && $pagadorUsuario) {
                        // Pela família, no app: lê o contrato, aceita e (quase sempre) já paga.
                        $plano = $planoDestino($mapa[$t->id]);
                        $ct = $this->rematricula->contratoParaAceite($pagadorUsuario, $a, $plano);
                        $r = $this->rematricula->aceitar($pagadorUsuario, $a, $plano, $ct['hash'], '177.38.' . ($a % 250) . '.' . ($a * 7 % 250), 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) Safari/604.1');
                        if ($sorte < 22 && ($r['cobranca']['parcela_id'] ?? null)) {
                            $this->financeiro->aplicarBaixaParcela($r['cobranca']['parcela_id'], (float) $r['cobranca']['valor'], $this->ate->toDateString(), 'pix', 'Pago pelo app.', (int) $this->admin);
                        } else {
                            $conta['falta_pagar']++;
                        }
                        $conta['app']++;
                    } elseif ($sorte < 48) {
                        $this->rematricula->rematricularPelaEscola((int) $c->id, $a, ['turma_destino_id' => $mapa[$t->id], 'plano_id' => $planoDestino($mapa[$t->id]),
                            'pagamento' => $sorte < 44 ? 'agora' : 'depois', 'forma_pagamento' => 'pix', 'emitir_boleto' => false], (int) $this->admin);
                        $conta['secretaria']++;
                        if ($sorte >= 44) {
                            $conta['falta_pagar']++;
                        }
                    } elseif ($sorte < 55 && $pagadorUsuario) {
                        $this->rematricula->recusar($pagadorUsuario, $a, ['Vamos mudar de cidade', 'Questões financeiras', 'Escola mais perto de casa'][$sorte % 3]);
                        $conta['nao_renova']++;
                    }
                } catch (\Throwable $e) {
                    $this->dizer("  rematrícula do aluno #$a pulada: {$e->getMessage()}");
                }
            }
        }
        $this->dizer("rematrícula $destino: {$conta['app']} pelo app, {$conta['secretaria']} na secretaria, {$conta['falta_pagar']} falta pagar, "
            . "{$conta['nao_renova']} não vão renovar (o resto sem resposta)");
    }

    // ═════════════════════════════════════════════════════════════════════
    // Apoio
    // ═════════════════════════════════════════════════════════════════════

    /** @return int[] alunos ativos da turma */
    private function alunosDaTurma(int $turmaId): array
    {
        return DB::table('turma_aluno as ta')->join('alunos as a', 'a.id', '=', 'ta.aluno_id')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->where('ta.turma_id', $turmaId)->where('u.ativo', 1)->orderBy('a.id')->pluck('a.id')->map(fn ($v) => (int) $v)->all();
    }

    /** Conteúdos de aula por área (para o diário e o planejamento). */
    private function conteudos(string $disciplina, bool $infantil): array
    {
        if ($infantil) {
            return ['Roda de conversa: como foi o fim de semana', 'Contação de história com fantoches', 'Brincadeira de roda e cantigas',
                'Pintura com as mãos: cores primárias', 'Massinha: formas e texturas', 'Exploração do parque e da areia', 'Músicas e movimentos do corpo',
                'Blocos de montar: maior e menor', 'Hora do conto: livro de imagens', 'Cuidados com o corpo: lavar as mãos'];
        }
        return match (true) {
            str_contains($disciplina, 'Portuguesa') => ['Leitura e interpretação: fábulas', 'Substantivos próprios e comuns', 'Produção de texto: bilhete',
                'Sinais de pontuação', 'Ortografia: R e RR', 'Leitura compartilhada: poema', 'Adjetivos', 'Produção de texto: carta'],
            str_contains($disciplina, 'Matemática') => ['Adição com reagrupamento', 'Subtração com recurso', 'Sistema monetário', 'Medidas de comprimento',
                'Problemas de multiplicação', 'Tabelas e gráficos de colunas', 'Frações: metade e terço', 'Divisão exata'],
            str_contains($disciplina, 'Ciências') => ['Ciclo de vida das plantas', 'Partes da planta', 'Animais vertebrados', 'Cuidados com a água', 'O corpo humano', 'Estados físicos da água'],
            str_contains($disciplina, 'História') => ['Linha do tempo da família', 'Brincadeiras de antigamente', 'Documentos pessoais', 'A escola no passado e hoje', 'Povos indígenas'],
            str_contains($disciplina, 'Geografia') => ['O bairro e seus espaços', 'Pontos de referência e trajetos', 'Paisagem natural e modificada', 'Tipos de moradia', 'Mapas e legendas'],
            str_contains($disciplina, 'Arte') => ['Cores primárias e secundárias', 'Releitura de obra: Tarsila do Amaral', 'Colagem com papéis', 'Desenho de observação'],
            str_contains($disciplina, 'Física') => ['Circuito motor', 'Jogos cooperativos', 'Queimada', 'Alongamento e respiração', 'Corrida de revezamento'],
            str_contains($disciplina, 'Inglesa') => ['Colors and numbers', 'Greetings', 'Family members', 'Animals', 'Days of the week'],
            default => ['Respeito às diferenças', 'Combinados da turma', 'Gentileza no dia a dia', 'Trabalho em equipe'],
        };
    }

    /** Número estável a partir de um texto (as escolhas não mudam entre uma execução e outra). */
    private function h(string $chave): int
    {
        return abs(crc32($chave));
    }

    private function dizer(string $msg): void
    {
        ($this->saida)($msg);
    }

    private function email(string $nome, int $id): string
    {
        $p = explode(' ', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $nome)));
        return preg_replace('/[^a-z.]/', '', $p[0] . '.' . end($p)) . $id . '@example.com';
    }

    private function cpf(): string
    {
        $n = [];
        for ($i = 0; $i < 9; $i++) {
            $n[] = mt_rand(0, 9);
        }
        for ($t = 9; $t < 11; $t++) {
            $s = 0;
            for ($i = 0; $i < $t; $i++) {
                $s += $n[$i] * ($t + 1 - $i);
            }
            $n[] = ((10 * $s) % 11) % 10;
        }
        $d = implode('', $n);
        return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9, 2);
    }

    private function telefone(): string
    {
        return '(19) 9' . mt_rand(1000, 9999) . '-' . mt_rand(1000, 9999);
    }

    private function cep(): string
    {
        return '130' . mt_rand(10, 99) . '-' . mt_rand(100, 999);
    }

    private function endereco(): array
    {
        return [NomesFicticios::RUAS[mt_rand(0, count(NomesFicticios::RUAS) - 1)], NomesFicticios::BAIRROS[mt_rand(0, count(NomesFicticios::BAIRROS) - 1)]];
    }
}
