<?php

namespace App\Support;

use App\Services\Sistema\PermissaoService;

/**
 * Catálogo de permissões por área e o padrão de cada perfil.
 *
 * Regra: o padrão vem daqui; a escola cria exceções por perfil (conceder ou
 * retirar uma permissão) na tela Configurações → Permissões, gravadas em
 * `perfil_permissoes`. Administrador tem tudo, sempre (não é configurável).
 *
 * Professor, Responsável e Aluno têm acesso "de escopo" independente disto:
 * professor nas turmas/disciplinas onde leciona, família nos próprios
 * dependentes. Estas permissões são o acesso AMPLO (a escola toda).
 */
final class Permissoes
{
    public const PAINEL_DIRECAO = 'painel.direcao';
    public const PESSOAS_VER = 'pessoas.ver';
    public const PESSOAS_GERENCIAR = 'pessoas.gerenciar';
    public const EQUIPE_GERENCIAR = 'equipe.gerenciar';
    public const TURMAS_GERENCIAR = 'turmas.gerenciar';
    public const REMATRICULA_GERENCIAR = 'rematricula.gerenciar';
    public const DOCUMENTOS_EMITIR = 'documentos.emitir';
    public const PEDAGOGICO_VER = 'pedagogico.ver';
    public const PEDAGOGICO_EDITAR = 'pedagogico.editar';
    public const PLANEJAMENTO_APROVAR = 'planejamento.aprovar';
    public const CALENDARIO_EDITAR = 'calendario.editar';
    public const HORARIOS_EDITAR = 'horarios.editar';
    public const COMUNICACAO_GESTAO = 'comunicacao.gestao';
    public const WHATSAPP_GERENCIAR = 'whatsapp.gerenciar';
    public const FINANCEIRO_OPERAR = 'financeiro.operar';
    public const FINANCEIRO_CONFIGURAR = 'financeiro.configurar';
    public const CONFIGURACOES_ESCOLA = 'configuracoes.escola';
    public const LOGS_VER = 'logs.ver';

    /** Áreas, na ordem da tela. */
    public const AREAS = [
        'secretaria'   => 'Secretaria',
        'pedagogico'   => 'Pedagógico',
        'comunicacao'  => 'Comunicação',
        'financeiro'   => 'Financeiro',
        'administracao' => 'Administração',
    ];

    /** chave => [área, rótulo, descrição] */
    public const CATALOGO = [
        self::PESSOAS_VER => ['secretaria', 'Consultar alunos e responsáveis',
            'Ver a lista e a ficha de alunos e responsáveis de toda a escola.'],
        self::PESSOAS_GERENCIAR => ['secretaria', 'Cadastrar alunos e responsáveis',
            'Cadastrar, editar, inativar e vincular alunos e responsáveis; liberar o acesso do aluno ao portal.'],
        self::TURMAS_GERENCIAR => ['secretaria', 'Turmas e disciplinas',
            'Criar e editar turmas, enturmar, transferir e promover alunos, vincular professores às disciplinas.'],
        self::REMATRICULA_GERENCIAR => ['secretaria', 'Rematrícula online',
            'Abrir a rematrícula do ano novo, editar o contrato e acompanhar quem confirmou, quem falta e quem não vai renovar.'],
        self::DOCUMENTOS_EMITIR => ['secretaria', 'Emitir documentos',
            'Declarações, históricos, atas, listas, carteirinhas e atestados de qualquer aluno.'],
        self::EQUIPE_GERENCIAR => ['secretaria', 'Equipe e usuários',
            'Cadastrar professores e a equipe da escola, ativar/desativar usuários e definir perfis.'],

        self::PAINEL_DIRECAO => ['pedagogico', 'Painel da Direção',
            'Home com os indicadores da escola. Os números financeiros só aparecem para quem também tem acesso ao financeiro.'],
        self::PEDAGOGICO_VER => ['pedagogico', 'Acompanhar todas as turmas',
            'Ver notas, frequência, diário de classe, BNCC e boletins de qualquer turma.'],
        self::PEDAGOGICO_EDITAR => ['pedagogico', 'Lançar e corrigir em qualquer turma',
            'Criar avaliações e lançar ou corrigir notas, frequência e registros de aula em qualquer turma.'],
        self::PLANEJAMENTO_APROVAR => ['pedagogico', 'Aprovar planejamentos',
            'Ver os planejamentos de todos os professores e aprovar ou devolver.'],
        self::CALENDARIO_EDITAR => ['pedagogico', 'Editar o calendário escolar',
            'Feriados, recessos, sábados letivos e eventos.'],
        self::HORARIOS_EDITAR => ['pedagogico', 'Montar o quadro de horários',
            'Definir os horários de aula e intervalos da escola e montar a grade semanal de cada turma (todos podem consultar).'],

        self::COMUNICACAO_GESTAO => ['comunicacao', 'Comunicação da escola toda',
            'Publicar no mural para toda a escola e ver ocorrências e recados de todas as turmas.'],
        self::WHATSAPP_GERENCIAR => ['comunicacao', 'Notificações WhatsApp',
            'Painel, testes e histórico dos avisos enviados às famílias.'],

        self::FINANCEIRO_OPERAR => ['financeiro', 'Operar o financeiro',
            'Visão geral, cobranças, contratos, baixas, inadimplência, recibos e centrais de cobrança.'],
        self::FINANCEIRO_CONFIGURAR => ['financeiro', 'Configurar o financeiro',
            'Planos, descontos, convênio bancário e integração com o Asaas.'],

        self::CONFIGURACOES_ESCOLA => ['administracao', 'Configurações da escola',
            'Dados da escola, parametrizações (ano letivo, modelos de avaliação, Max) e uso do Max.'],
        self::LOGS_VER => ['administracao', 'Logs do sistema',
            'Auditoria: acessos, sessões, telas visitadas e alterações feitas por cada usuário.'],
    ];

    /** Ter a da esquerda implica ter as da direita. */
    public const IMPLICA = [
        self::PESSOAS_GERENCIAR => [self::PESSOAS_VER],
        self::PEDAGOGICO_EDITAR => [self::PEDAGOGICO_VER],
        self::FINANCEIRO_CONFIGURAR => [self::FINANCEIRO_OPERAR],
    ];

    /** Perfis cujas permissões a escola pode ajustar, na ordem da tela. */
    public const PERFIS_CONFIGURAVEIS = [
        Papeis::DIRETORIA, Papeis::COORDENACAO, Papeis::SECRETARIA, Papeis::TESOURARIA, Papeis::PROFESSOR,
    ];

    public static function padrao(string $perfil): array
    {
        $todas = array_keys(self::CATALOGO);

        return match ($perfil) {
            Papeis::ADMINISTRADOR => $todas,
            Papeis::DIRETORIA => array_values(array_diff($todas, [self::LOGS_VER])),
            Papeis::COORDENACAO => [
                self::PAINEL_DIRECAO, self::PESSOAS_VER, self::DOCUMENTOS_EMITIR,
                self::PEDAGOGICO_VER, self::PEDAGOGICO_EDITAR, self::PLANEJAMENTO_APROVAR,
                self::CALENDARIO_EDITAR, self::HORARIOS_EDITAR, self::COMUNICACAO_GESTAO,
            ],
            Papeis::SECRETARIA => [
                self::PAINEL_DIRECAO, self::PESSOAS_GERENCIAR, self::TURMAS_GERENCIAR, self::REMATRICULA_GERENCIAR,
                self::DOCUMENTOS_EMITIR, self::PEDAGOGICO_VER, self::HORARIOS_EDITAR, self::COMUNICACAO_GESTAO,
            ],
            Papeis::TESOURARIA => [
                self::PESSOAS_VER, self::DOCUMENTOS_EMITIR, self::FINANCEIRO_OPERAR, self::FINANCEIRO_CONFIGURAR,
            ],
            default => [],
        };
    }

    /** Fecha o conjunto pelas implicações (gerenciar ⇒ ver). */
    public static function comImplicadas(array $permissoes): array
    {
        foreach ($permissoes as $p) {
            foreach (self::IMPLICA[$p] ?? [] as $implicada) {
                $permissoes[] = $implicada;
            }
        }

        return array_values(array_unique($permissoes));
    }

    /** Atalho para controllers e serviços que só têm as funções do JWT. */
    public static function pode(array $funcoes, string ...$permissoes): bool
    {
        return app(PermissaoService::class)->pode($funcoes, ...$permissoes);
    }
}
