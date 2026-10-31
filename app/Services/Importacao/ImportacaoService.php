<?php

namespace App\Services\Importacao;

use App\Services\Cadastro\CadastroValidacaoService;
use App\Services\Matriculas\NumeroMatriculaService;
use App\Support\Papeis;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Importação de dados da escola nova: uma planilha (uma linha por aluno, com
 * até dois responsáveis) vira alunos, responsáveis, vínculos e turmas.
 *
 * O front lê a planilha e manda as linhas já com as colunas reconhecidas
 * (chaves de CAMPOS). Aqui:
 * - analisar(): confere tudo sem gravar — o que entra, o que já existe, o
 *   que tem problema (com o campo, para a escola corrigir na própria tela);
 * - executar(): grava linha a linha (uma linha com problema não derruba as
 *   outras) e guarda os ids criados em `importacoes`;
 * - desfazer(): apaga o que a importação criou, enquanto nada foi lançado
 *   em cima (nota, chamada, cobrança...).
 *
 * Família com mais de um filho: o responsável é reconhecido pelo CPF — vira
 * um cadastro só, vinculado a todos. Quem já está no sistema (aluno pelo CPF
 * ou nome + nascimento; responsável pelo CPF) não é duplicado, então dá para
 * importar a mesma planilha de novo depois de corrigir.
 */
class ImportacaoService
{
    /**
     * Colunas que o sistema entende: chave => [rótulo, grupo, nomes de coluna comuns].
     * Os nomes vão sem acento e em minúsculas (o front compara assim).
     */
    public const CAMPOS = [
        'aluno_nome' => ['Nome do aluno', 'aluno', ['aluno', 'nome do aluno', 'nome aluno', 'nome completo do aluno', 'estudante', 'nome', 'nome completo', 'crianca', 'nome da crianca']],
        'aluno_nascimento' => ['Nascimento', 'aluno', ['nascimento', 'data de nascimento', 'data nascimento', 'dt nascimento', 'dt nasc', 'data nasc', 'nasc']],
        'turma' => ['Turma', 'aluno', ['turma', 'serie', 'serie ano', 'ano serie', 'turma atual', 'classe', 'sala', 'nivel']],
        'turno' => ['Turno', 'aluno', ['turno']],
        'aluno_sexo' => ['Sexo', 'aluno', ['sexo', 'genero']],
        'aluno_cpf' => ['CPF do aluno', 'aluno', ['cpf do aluno', 'cpf aluno', 'cpf']],
        'aluno_matricula' => ['Matrícula', 'aluno', ['matricula', 'n matricula', 'numero de matricula', 'numero matricula', 'ra', 'codigo do aluno']],
        'aluno_saude' => ['Saúde e alergias', 'aluno', ['alergia', 'alergias', 'saude', 'observacoes de saude', 'medicamentos', 'restricao alimentar', 'restricoes']],
        'cep' => ['CEP', 'endereco', ['cep']],
        'endereco' => ['Logradouro', 'endereco', ['endereco', 'logradouro', 'rua', 'endereco completo']],
        'numero' => ['Número', 'endereco', ['numero', 'n', 'no', 'num']],
        'complemento' => ['Complemento', 'endereco', ['complemento', 'compl']],
        'bairro' => ['Bairro', 'endereco', ['bairro']],
        'cidade' => ['Cidade', 'endereco', ['cidade', 'municipio']],
        'uf' => ['UF', 'endereco', ['uf', 'estado']],
        'resp1_nome' => ['Nome', 'resp1', ['responsavel', 'nome do responsavel', 'responsavel 1', 'responsavel financeiro', 'nome do responsavel financeiro', 'mae', 'nome da mae']],
        'resp1_cpf' => ['CPF', 'resp1', ['cpf do responsavel', 'cpf responsavel', 'cpf responsavel 1', 'cpf do responsavel financeiro', 'cpf da mae', 'cpf mae']],
        'resp1_email' => ['E-mail', 'resp1', ['email', 'e mail', 'email do responsavel', 'email responsavel', 'email responsavel 1', 'email da mae', 'email mae']],
        'resp1_telefone' => ['Celular', 'resp1', ['telefone', 'celular', 'whatsapp', 'fone', 'contato', 'telefone do responsavel', 'celular do responsavel', 'telefone da mae', 'celular da mae']],
        'resp1_parentesco' => ['Parentesco', 'resp1', ['parentesco', 'grau de parentesco', 'vinculo', 'parentesco responsavel 1']],
        'resp2_nome' => ['Nome', 'resp2', ['responsavel 2', 'segundo responsavel', 'pai', 'nome do pai']],
        'resp2_cpf' => ['CPF', 'resp2', ['cpf responsavel 2', 'cpf do pai', 'cpf pai']],
        'resp2_email' => ['E-mail', 'resp2', ['email responsavel 2', 'email do pai', 'email pai']],
        'resp2_telefone' => ['Celular', 'resp2', ['telefone responsavel 2', 'celular responsavel 2', 'telefone do pai', 'celular do pai']],
        'resp2_parentesco' => ['Parentesco', 'resp2', ['parentesco 2', 'parentesco responsavel 2']],
    ];

    public const GRUPOS = ['aluno' => 'Aluno', 'endereco' => 'Endereço', 'resp1' => 'Responsável financeiro', 'resp2' => 'Segundo responsável'];

    /** Escolha de turma no front: 0 = criar a turma; -1 = não importar esses alunos. */
    public const TURMA_NOVA = 0;
    public const TURMA_IGNORAR = -1;

    public function __construct(private CadastroValidacaoService $v, private NumeroMatriculaService $numeros)
    {
    }

    public function disponivel(): bool
    {
        return Schema::hasTable('importacoes');
    }

    // ── Análise ─────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $linhas linhas com as chaves de CAMPOS + `_linha` (nº na planilha)
     * @param array<string, int> $escolhas nome da turma na planilha (normalizado) => turma_id | TURMA_NOVA | TURMA_IGNORAR
     */
    public function analisar(array $linhas, int $ano, array $escolhas = []): array
    {
        $existentes = $this->turmasDoAno($ano);
        $turmas = [];
        $saida = [];
        $vistos = [];
        $respPlanilha = [];   // cpf => nome (primeira linha em que apareceu)
        $emailsPlanilha = []; // email => cpf
        $resumo = ['novos' => 0, 'existentes' => 0, 'erros' => 0, 'ignorados' => 0, 'avisos' => 0, 'responsaveis_novos' => 0, 'responsaveis_existentes' => 0, 'turmas_novas' => 0];

        foreach (array_values($linhas) as $i => $bruta) {
            $l = $this->limpar($bruta);
            $n = (int) ($bruta['_linha'] ?? $i + 2);
            $msgs = [];
            $erro = function (string $campo, string $texto) use (&$msgs) {
                $msgs[] = ['tipo' => 'erro', 'campo' => $campo, 'texto' => $texto];
            };
            $aviso = function (?string $campo, string $texto) use (&$msgs) {
                $msgs[] = ['tipo' => 'aviso', 'campo' => $campo, 'texto' => $texto];
            };

            // Turma
            $chaveTurma = $this->chave($l['turma']);
            if ($chaveTurma !== '') {
                $turmas[$chaveTurma] ??= ['chave' => $chaveTurma, 'nome' => $l['turma'], 'alunos' => 0, 'turnos' => [],
                    'turma_id' => $existentes[$chaveTurma]->id ?? null];
                $turmas[$chaveTurma]['alunos']++;
                if ($l['turno']) {
                    $turmas[$chaveTurma]['turnos'][] = $l['turno'];
                }
            }
            $escolha = $chaveTurma !== '' ? ($escolhas[$chaveTurma] ?? ($existentes[$chaveTurma]->id ?? self::TURMA_NOVA)) : null;

            // Aluno
            if (!$l['aluno_nome']) {
                $erro('aluno_nome', 'Sem o nome do aluno.');
            }
            if (!$l['aluno_nascimento']) {
                $erro('aluno_nascimento', $l['nascimento_bruto'] ? "Data de nascimento não reconhecida: \"{$l['nascimento_bruto']}\"." : 'Sem a data de nascimento.');
            }
            if ($chaveTurma === '') {
                $erro('turma', 'Sem a turma.');
            }
            if ($l['cpf_aluno_invalido']) {
                $aviso('aluno_cpf', 'CPF do aluno inválido — vai ficar em branco.');
            }

            $existe = null;
            if ($l['aluno_nome'] && $l['aluno_nascimento']) {
                $chaveAluno = $this->chave($l['aluno_nome']) . '|' . $l['aluno_nascimento'];
                if (isset($vistos[$chaveAluno])) {
                    $erro('aluno_nome', "Repetido: o mesmo aluno está na linha {$vistos[$chaveAluno]}.");
                } else {
                    $vistos[$chaveAluno] = $n;
                }
                $existe = $this->alunoExistente($l['aluno_nome'], $l['aluno_nascimento'], $l['aluno_cpf']);
            }
            if (!$existe && $l['aluno_matricula'] && $this->numeros->emUso($l['aluno_matricula'])) {
                $aviso('aluno_matricula', "A matrícula {$l['aluno_matricula']} já é de outro aluno — vai receber um número novo.");
            }

            // Responsáveis: o 1º é o financeiro; sem ele, o 2º assume.
            $resps = [];
            foreach (['resp1', 'resp2'] as $r) {
                $nome = $l["{$r}_nome"];
                $cpf = $l["{$r}_cpf"];
                if (!$nome && !$cpf) {
                    continue;
                }
                $problema = match (true) {
                    !$nome => 'Responsável sem o nome',
                    !$cpf => "Falta o CPF de $nome",
                    !$this->v->cpfValido($cpf) => "CPF de $nome inválido",
                    default => null,
                };
                if ($problema) {
                    $campo = !$nome ? "{$r}_nome" : "{$r}_cpf";
                    if ($r === 'resp1' || !$resps) {
                        $erro($campo, $problema . ($cpf ? '.' : ' (é o CPF que vai no boleto).'));
                    } else {
                        $aviso($campo, "$problema — o segundo responsável não vai ser vinculado.");
                    }
                    continue;
                }
                $cpfF = $this->v->formatarCpf($cpf);
                $doBanco = DB::table('responsaveis as r')->join('usuarios as u', 'u.id', '=', 'r.usuario_id')
                    ->where('r.cpf', $cpfF)->first(['r.id', 'u.nome_completo', 'u.email']);
                $email = $l["{$r}_email"];
                if (!$doBanco && $email) {
                    if (!$this->v->emailValido($email)) {
                        $aviso("{$r}_email", "E-mail de $nome inválido — fica sem acesso ao app por enquanto.");
                        $email = null;
                    } elseif ($this->v->emailEmUso($email) || (isset($emailsPlanilha[mb_strtolower($email)]) && $emailsPlanilha[mb_strtolower($email)] !== $cpfF)) {
                        $aviso("{$r}_email", "O e-mail $email já é de outra pessoa — $nome fica sem acesso ao app por enquanto.");
                        $email = null;
                    } else {
                        $emailsPlanilha[mb_strtolower($email)] = $cpfF;
                    }
                }
                $tel = $l["{$r}_telefone"];
                if ($tel && !$this->v->telefoneValido($tel)) {
                    $aviso("{$r}_telefone", "Celular de $nome inválido (precisa de DDD) — fica em branco.");
                    $tel = null;
                }
                $resps[] = ['cpf' => $cpfF, 'nome' => $doBanco->nome_completo ?? $nome, 'email' => $doBanco ? $doBanco->email : $email,
                    'telefone' => $tel, 'parentesco' => $l["{$r}_parentesco"], 'id' => $doBanco ? (int) $doBanco->id : null];
            }
            if (!$resps && !array_filter($msgs, fn ($m) => str_starts_with($m['campo'] ?? '', 'resp'))) {
                $erro('resp1_nome', 'Sem responsável — todo aluno precisa de pelo menos um.');
            }

            $status = $escolha === self::TURMA_IGNORAR ? 'ignorada'
                : ($existe ? 'existe'
                : (array_filter($msgs, fn ($m) => $m['tipo'] === 'erro') ? 'erro'
                : ($msgs ? 'aviso' : 'ok')));
            match ($status) {
                'ignorada' => $resumo['ignorados']++,
                'existe' => $resumo['existentes']++,
                'erro' => $resumo['erros']++,
                default => $resumo['novos']++,
            };
            if ($status === 'aviso') {
                $resumo['avisos']++;
            }
            if (in_array($status, ['ok', 'aviso'], true)) {
                foreach ($resps as $r) {
                    if (!isset($respPlanilha[$r['cpf']])) {
                        $respPlanilha[$r['cpf']] = true;
                        $r['id'] ? $resumo['responsaveis_existentes']++ : $resumo['responsaveis_novos']++;
                    }
                }
            }

            $saida[] = [
                'linha' => $n,
                'status' => $status,
                'mensagens' => $status === 'existe' ? [['tipo' => 'info', 'campo' => null, 'texto' => 'Já cadastrado' . ($existe->turma ? " ({$existe->turma})" : '') . ' — fica como está.']] : $msgs,
                'aluno' => $l['aluno_nome'],
                'nascimento' => $l['aluno_nascimento'],
                'turma' => $l['turma'],
                'turma_escolha' => $escolha,
                'familia' => array_column($resps, 'nome'),
                '_dados' => $l,
                '_resps' => $resps,
            ];
        }

        foreach ($turmas as &$t) {
            $t['escolha'] = $escolhas[$t['chave']] ?? ($t['turma_id'] ?? self::TURMA_NOVA);
            $t['turno'] = $this->turnoMaisComum($t['turnos']);
            $t['infantil'] = $this->etapaInfantil($t['nome']) !== null;
            unset($t['turnos']);
            if ($t['escolha'] === self::TURMA_NOVA) {
                $resumo['turmas_novas']++;
            }
        }
        unset($t);

        return [
            'ano' => $ano,
            'resumo' => $resumo,
            'turmas' => array_values($turmas),
            'turmas_existentes' => array_values(array_map(fn ($t) => ['id' => (int) $t->id, 'nome' => $t->nome_turma, 'turno' => $t->turno], $existentes)),
            'linhas' => $saida,
        ];
    }

    // ── Gravação ────────────────────────────────────────────────────────

    /**
     * Grava as linhas que passaram na análise. Senha de quem ganha acesso ao app
     * (responsável com e-mail): os 6 primeiros números do CPF, se $senhaCpf.
     */
    public function executar(array $linhas, int $ano, array $escolhas, bool $senhaCpf, ?string $arquivo, int $usuarioId): array
    {
        $plano = $this->analisar($linhas, $ano, $escolhas);
        $criados = ['turmas' => [], 'usuarios' => [], 'responsaveis' => [], 'alunos' => [], 'matriculas' => []];
        $falhas = [];
        $turmaIds = [];
        $respIds = [];
        $acessos = 0;
        $alunos = 0;
        $funcaoAluno = (int) DB::table('funcoes')->where('nome_funcao', Papeis::ALUNO)->value('id');
        $funcaoResp = (int) DB::table('funcoes')->where('nome_funcao', Papeis::RESPONSAVEL)->value('id');

        foreach ($plano['turmas'] as $t) {
            $usada = collect($plano['linhas'])->contains(fn ($l) => $this->chave($l['turma']) === $t['chave'] && in_array($l['status'], ['ok', 'aviso'], true));
            if (!$usada || $t['escolha'] === self::TURMA_IGNORAR) {
                continue;
            }
            if ($t['escolha'] === self::TURMA_NOVA) {
                $turmaIds[$t['chave']] = $this->criarTurma($t['nome'], $ano, $t['turno']);
                $criados['turmas'][] = $turmaIds[$t['chave']];
            } else {
                $turmaIds[$t['chave']] = (int) $t['escolha'];
            }
        }

        foreach ($plano['linhas'] as $linha) {
            if (!in_array($linha['status'], ['ok', 'aviso'], true)) {
                continue;
            }
            $l = $linha['_dados'];
            try {
                // O que a linha cria só conta depois do commit (se falhar, a transação desfaz tudo dela).
                $novo = DB::transaction(function () use ($l, $linha, $ano, $turmaIds, $respIds, $senhaCpf, $funcaoAluno, $funcaoResp) {
                    $novo = ['usuarios' => [], 'responsaveis' => [], 'cpfs' => [], 'acessos' => 0];
                    $vinculos = [];
                    foreach ($linha['_resps'] as $i => $r) {
                        $id = $r['id'] ?? $respIds[$r['cpf']] ?? $novo['cpfs'][$r['cpf']] ?? null;
                        if (!$id) {
                            $comAcesso = $r['email'] && $senhaCpf;
                            $uid = DB::table('usuarios')->insertGetId([
                                'nome_completo' => $r['nome'],
                                'email' => $r['email'],
                                'senha' => $comAcesso ? Hash::make(substr($this->v->digitos($r['cpf']), 0, 6)) : null,
                                'telefone' => $this->v->telefone($r['telefone']),
                                'data_cadastro' => now(),
                                'ativo' => 1,
                                'acesso_portal' => $r['email'] ? 1 : 0,
                            ]);
                            $id = DB::table('responsaveis')->insertGetId([
                                'usuario_id' => $uid,
                                'cpf' => $r['cpf'],
                                'telefone' => $this->v->telefone($r['telefone']),
                            ] + $this->v->endereco($l));
                            DB::table('usuario_funcao')->insert(['usuario_id' => $uid, 'funcao_id' => $funcaoResp]);
                            $novo['usuarios'][] = $uid;
                            $novo['responsaveis'][] = $id;
                            $novo['cpfs'][$r['cpf']] = $id;
                            $novo['acessos'] += $comAcesso ? 1 : 0;
                        }
                        $vinculos[$id] ??= ['parentesco' => mb_substr((string) $r['parentesco'], 0, 50), 'principal' => $i === 0 ? 1 : 0];
                    }

                    $turmaId = $turmaIds[$this->chave($l['turma'])];
                    $matricula = $l['aluno_matricula'] && !$this->numeros->emUso($l['aluno_matricula'])
                        ? $l['aluno_matricula'] : $this->numeros->proximo($ano);
                    $uid = DB::table('usuarios')->insertGetId([
                        'nome_completo' => $l['aluno_nome'],
                        'email' => null,
                        'senha' => null,
                        'data_cadastro' => now(),
                        'ativo' => 1,
                        'acesso_portal' => 0,
                    ]);
                    $alunoId = DB::table('alunos')->insertGetId([
                        'usuario_id' => $uid,
                        'matricula' => $matricula,
                        'cpf' => $l['aluno_cpf'] ? $this->v->formatarCpf($l['aluno_cpf']) : null,
                        'data_nascimento' => $l['aluno_nascimento'],
                        'sexo' => $l['aluno_sexo'],
                        'observacoes_saude' => $l['aluno_saude'],
                        'status_matricula' => 'Ativo',
                    ] + $this->v->endereco($l));
                    DB::table('turma_aluno')->insert(['aluno_id' => $alunoId, 'turma_id' => $turmaId]);
                    $novo['matricula'] = DB::table('matriculas')->insertGetId($this->numeros->linha([
                        'aluno_id' => $alunoId,
                        'turma_id' => $turmaId,
                        'ano_letivo' => $ano,
                        'data_inicio' => now()->toDateString(),
                        'status' => 'ativa',
                        'origem' => 'sistema',
                    ], $matricula));
                    DB::table('usuario_funcao')->insert(['usuario_id' => $uid, 'funcao_id' => $funcaoAluno]);
                    foreach ($vinculos as $respId => $vin) {
                        DB::table('aluno_responsavel')->insertOrIgnore(['aluno_id' => $alunoId, 'responsavel_id' => $respId] + $vin);
                    }
                    $novo['usuarios'][] = $uid;
                    $novo['aluno'] = $alunoId;
                    return $novo;
                });
                $respIds = $novo['cpfs'] + $respIds;
                array_push($criados['usuarios'], ...$novo['usuarios']);
                array_push($criados['responsaveis'], ...$novo['responsaveis']);
                $criados['alunos'][] = $novo['aluno'];
                $criados['matriculas'][] = $novo['matricula'];
                $acessos += $novo['acessos'];
                $alunos++;
            } catch (\Throwable $e) {
                $falhas[] = ['linha' => $linha['linha'], 'aluno' => $linha['aluno'], 'texto' => $this->v->mensagemDuplicidade($e) ?? 'Não foi possível gravar esta linha.'];
                report($e);
            }
        }

        $id = DB::table('importacoes')->insertGetId([
            'arquivo' => $arquivo ? mb_substr($arquivo, 0, 150) : null,
            'ano_letivo' => $ano,
            'alunos' => $alunos,
            'responsaveis' => count($criados['responsaveis']),
            'turmas' => count($criados['turmas']),
            'criados' => json_encode($criados),
            'criado_por' => $usuarioId ?: null,
        ]);

        return [
            'id' => $id,
            'alunos' => $alunos,
            'responsaveis' => count($criados['responsaveis']),
            'turmas' => count($criados['turmas']),
            'acessos' => $acessos,
            'falhas' => $falhas,
        ];
    }

    /** Últimas importações (para a tela e para desfazer). */
    public function historico(): array
    {
        return DB::table('importacoes as i')->leftJoin('usuarios as u', 'u.id', '=', 'i.criado_por')
            ->orderByDesc('i.id')->limit(10)
            ->get(['i.id', 'i.arquivo', 'i.ano_letivo', 'i.alunos', 'i.responsaveis', 'i.turmas', 'i.criado_em', 'i.desfeita_em', 'u.nome_completo as por'])
            ->map(fn ($i) => (array) $i + ['pode_desfazer' => !$i->desfeita_em && $this->bloqueioDesfazer((int) $i->id) === null])
            ->all();
    }

    /** Por que não dá mais para desfazer (null = dá). */
    public function bloqueioDesfazer(int $id): ?string
    {
        $imp = DB::table('importacoes')->where('id', $id)->first();
        if (!$imp || $imp->desfeita_em) {
            return 'Importação não encontrada.';
        }
        $alunos = json_decode($imp->criados, true)['alunos'] ?? [];
        if (!$alunos) {
            return null;
        }
        $usos = [
            'frequencia' => 'chamadas', 'notas' => 'notas', 'registros_bncc' => 'avaliações da Educação Infantil',
            'parcelas' => 'cobranças', 'rematriculas' => 'rematrículas', 'ocorrencias' => 'ocorrências',
        ];
        foreach ($usos as $tabela => $rotulo) {
            if (Schema::hasTable($tabela) && Schema::hasColumn($tabela, 'aluno_id') && DB::table($tabela)->whereIn('aluno_id', $alunos)->exists()) {
                return "Os alunos importados já têm $rotulo lançadas.";
            }
        }
        return null;
    }

    public function desfazer(int $id): array
    {
        if ($motivo = $this->bloqueioDesfazer($id)) {
            throw new \DomainException($motivo);
        }
        $c = json_decode(DB::table('importacoes')->where('id', $id)->value('criados'), true);
        $alunos = $c['alunos'] ?? [];
        $resps = $c['responsaveis'] ?? [];

        DB::transaction(function () use ($id, $c, $alunos, $resps) {
            if ($alunos) {
                DB::table('aluno_responsavel')->whereIn('aluno_id', $alunos)->delete();
                DB::table('turma_aluno')->whereIn('aluno_id', $alunos)->delete();
                DB::table('matriculas')->whereIn('aluno_id', $alunos)->delete();
                DB::table('alunos')->whereIn('id', $alunos)->delete();
            }
            // Responsável que, depois, ganhou outro filho vinculado fica.
            $soltos = $resps ? DB::table('responsaveis')->whereIn('id', $resps)
                ->whereNotExists(fn ($q) => $q->from('aluno_responsavel as ar')->whereColumn('ar.responsavel_id', 'responsaveis.id'))
                ->pluck('usuario_id', 'id')->all() : [];
            DB::table('responsaveis')->whereIn('id', array_keys($soltos) ?: [0])->delete();
            $usuarios = array_values(array_diff($c['usuarios'] ?? [], DB::table('responsaveis')->whereIn('id', $resps ?: [0])->pluck('usuario_id')->all()));
            if ($usuarios) {
                DB::table('usuario_funcao')->whereIn('usuario_id', $usuarios)->delete();
                DB::table('usuarios')->whereIn('id', $usuarios)->delete();
            }
            // Turma criada pela importação: sai se ficou vazia e sem professor.
            foreach ($c['turmas'] ?? [] as $t) {
                $emUso = DB::table('turma_aluno')->where('turma_id', $t)->exists()
                    || DB::table('turma_professor_disciplina')->where('turma_id', $t)->exists();
                if (!$emUso) {
                    DB::table('turmas')->where('id', $t)->delete();
                }
            }
            DB::table('importacoes')->where('id', $id)->update(['desfeita_em' => now()]);
        });

        return ['alunos' => count($alunos)];
    }

    // ── Apoio ───────────────────────────────────────────────────────────

    /** Linha da planilha com os campos normalizados. */
    private function limpar(array $b): array
    {
        $t = fn (string $k) => $this->v->texto(isset($b[$k]) && !is_array($b[$k]) ? preg_replace('/\s+/u', ' ', (string) $b[$k]) : null);
        $l = [];
        foreach (array_keys(self::CAMPOS) as $k) {
            $l[$k] = $t($k);
        }
        $l['aluno_nome'] = $l['aluno_nome'] ? $this->nomeProprio($l['aluno_nome']) : null;
        foreach (['resp1_nome', 'resp2_nome'] as $k) {
            $l[$k] = $l[$k] ? $this->nomeProprio($l[$k]) : null;
        }
        $l['nascimento_bruto'] = $l['aluno_nascimento'];
        $l['aluno_nascimento'] = $this->data($l['aluno_nascimento']);
        $l['cpf_aluno_invalido'] = $l['aluno_cpf'] && !$this->v->cpfValido($l['aluno_cpf']);
        if ($l['cpf_aluno_invalido']) {
            $l['aluno_cpf'] = null;
        }
        $l['aluno_sexo'] = $this->sexo($l['aluno_sexo']);
        $l['turno'] = $this->turno($l['turno']);
        foreach (['resp1_email', 'resp2_email'] as $k) {
            $l[$k] = $l[$k] ? mb_strtolower($l[$k]) : null;
        }
        foreach (['resp1_parentesco', 'resp2_parentesco'] as $k) {
            $l[$k] = $l[$k] ? mb_convert_case($l[$k], MB_CASE_TITLE) : ($b["_{$k}"] ?? null);
        }
        if ($l['uf'] && strlen($l['uf']) !== 2) {
            $l['uf'] = null;
        }
        if ($l['cep'] && strlen($this->v->digitos($l['cep'])) !== 8) {
            $l['cep'] = null;
        }
        return $l;
    }

    /** "MARIA DA SILVA" → "Maria da Silva" (quando veio quase tudo maiúsculo, ou tudo minúsculo). */
    private function nomeProprio(string $nome): string
    {
        $letras = preg_replace('/[^\p{L}]/u', '', $nome);
        $maiusculas = preg_replace('/[^\p{Lu}]/u', '', $nome);
        $gritado = $letras !== '' && mb_strlen($maiusculas) / mb_strlen($letras) > 0.6;
        if (!$gritado && $nome !== mb_strtolower($nome)) {
            return $nome;
        }
        $partes = explode(' ', mb_convert_case(mb_strtolower($nome), MB_CASE_TITLE));
        return implode(' ', array_map(fn ($p) => in_array(mb_strtolower($p), ['da', 'de', 'do', 'das', 'dos', 'e'], true) ? mb_strtolower($p) : $p, $partes));
    }

    /** Data em dd/mm/aaaa, dd/mm/aa, aaaa-mm-dd ou dd-mm-aaaa → aaaa-mm-dd (null se não der). */
    private function data(?string $s): ?string
    {
        if (!$s) {
            return null;
        }
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            [$a, $me, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2}|\d{4})$#', $s, $m)) {
            [$d, $me, $a] = [(int) $m[1], (int) $m[2], (int) $m[3]];
            if ($a < 100) {
                $a += $a > (int) date('y') ? 1900 : 2000;
            }
        } else {
            return null;
        }
        if (!checkdate($me, $d, $a) || $a < 1950 || Carbon::create($a, $me, $d)->isFuture()) {
            return null;
        }
        return sprintf('%04d-%02d-%02d', $a, $me, $d);
    }

    private function sexo(?string $s): ?string
    {
        $s = $this->chave((string) $s);
        return match (true) {
            in_array($s, ['f', 'fem', 'feminino', 'menina'], true) => 'F',
            in_array($s, ['m', 'masc', 'masculino', 'menino'], true) => 'M',
            default => null,
        };
    }

    private function turno(?string $s): ?string
    {
        $s = $this->chave((string) $s);
        return match (true) {
            $s === '' => null,
            str_starts_with($s, 'manh'), str_starts_with($s, 'matut'), $s === 'm' => 'Manhã',
            str_starts_with($s, 'tard'), str_starts_with($s, 'vesp'), $s === 't' => 'Tarde',
            str_starts_with($s, 'noit'), str_starts_with($s, 'notur'), $s === 'n' => 'Noite',
            default => null,
        };
    }

    private function turnoMaisComum(array $turnos): string
    {
        if (!$turnos) {
            return 'Manhã';
        }
        $c = array_count_values($turnos);
        arsort($c);
        return (string) array_key_first($c);
    }

    /** Sem acento, minúsculo, só letras/números e espaço simples. */
    public function chave(?string $s): string
    {
        $s = mb_strtolower(trim((string) $s));
        $s = strtr($s, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'ê' => 'e', 'è' => 'e', 'í' => 'i', 'ì' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ò' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c', 'º' => 'o', 'ª' => 'a', '°' => 'o']);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9]+/', ' ', $s)));
    }

    /** @return array<string, object> turmas ativas do ano, pela chave do nome */
    private function turmasDoAno(int $ano): array
    {
        $out = [];
        foreach (DB::table('turmas')->where('ano_letivo', $ano)->where('status', 'Ativa')->orderBy('nome_turma')->get(['id', 'nome_turma', 'turno']) as $t) {
            $out[$this->chave($t->nome_turma)] = $t;
        }
        return $out;
    }

    private function alunoExistente(string $nome, string $nascimento, ?string $cpf): ?object
    {
        $q = DB::table('alunos as a')->join('usuarios as u', 'u.id', '=', 'a.usuario_id')
            ->leftJoin('turma_aluno as ta', 'ta.aluno_id', '=', 'a.id')->leftJoin('turmas as t', 't.id', '=', 'ta.turma_id')
            ->select('a.id', 't.nome_turma as turma');
        if ($cpf && $this->v->cpfValido($cpf)) {
            $porCpf = (clone $q)->where('a.cpf', $this->v->formatarCpf($cpf))->first();
            if ($porCpf) {
                return $porCpf;
            }
        }
        return $q->where('a.data_nascimento', $nascimento)->where('u.nome_completo', $nome)->first();
    }

    /** Etapa da BNCC pelo nome da turma (mesma regra do cadastro de turmas); null = não é Educação Infantil. */
    private function etapaInfantil(string $nome): ?string
    {
        $k = $this->chave($nome);
        return match (true) {
            str_contains($k, 'berc') => 'Bebês',
            str_contains($k, 'maternal') => 'Crianças bem pequenas',
            str_contains($k, 'periodo'), str_contains($k, 'jardim'), (bool) preg_match('/\bpre\b/', $k), str_contains($k, 'infantil') => 'Crianças pequenas',
            default => null,
        };
    }

    /** Turma nova vinda da planilha (Educação Infantil reconhecida pelo nome, como no cadastro de turmas). */
    private function criarTurma(string $nome, int $ano, string $turno): int
    {
        $etapa = $this->etapaInfantil($nome);
        return DB::transaction(function () use ($nome, $ano, $turno, $etapa) {
            $id = DB::table('turmas')->insertGetId([
                'nome_turma' => mb_substr($nome, 0, 100),
                'ano_letivo' => $ano,
                'turno' => $turno,
                'capacidade' => 30,
                'sistema_avaliacao_id' => $etapa ? 3 : 1,
                'etapa_bncc' => $etapa,
                'status' => 'Ativa',
            ]);
            DB::statement('INSERT IGNORE INTO anos_letivos (ano) VALUES (?)', [$ano]);
            // Primeira turma do ano: os 4 bimestres (como em TurmaController::store).
            if (!DB::table('unidades_letivas')->where('ano_letivo', $ano)->exists()) {
                foreach (['1º Bimestre', '2º Bimestre', '3º Bimestre', '4º Bimestre'] as $b) {
                    DB::table('unidades_letivas')->insert(['nome_unidade' => $b, 'data_inicio' => null, 'data_fim' => null, 'ano_letivo' => $ano, 'ativo' => 1]);
                }
            }
            return $id;
        });
    }
}
