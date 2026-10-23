<?php

namespace App\Services\Sistema;

/**
 * Vocabulário da auditoria (logs_sistema): categorias e a regra que classifica
 * cada ação/rota numa delas. É a mesma lista que a tela "Logs do Sistema" usa
 * nos filtros e nos gráficos.
 */
class AuditoriaCatalogo
{
    public const CATEGORIAS = [
        'acesso'      => 'Acesso e segurança',
        'navegacao'   => 'Navegação',
        'exportacao'  => 'Exportações e relatórios',
        'cadastro'    => 'Cadastros',
        'academico'   => 'Acadêmico',
        'financeiro'  => 'Financeiro',
        'comunicacao' => 'Comunicação',
        'sistema'     => 'Sistema e configurações',
    ];

    /** Ações genéricas gravadas pelo middleware quando o controller não registrou nada. */
    public const ACAO_POR_METODO = [
        'POST'   => 'ACAO_INCLUSAO',
        'PUT'    => 'ACAO_ALTERACAO',
        'PATCH'  => 'ACAO_ALTERACAO',
        'DELETE' => 'ACAO_EXCLUSAO',
    ];

    /** Prefixo da rota (api/<prefixo>/...) → [categoria, rótulo do módulo]. */
    private const MODULOS = [
        'auth'           => ['acesso', 'Autenticação'],
        'auditoria'      => ['navegacao', 'Auditoria'],
        'agent'          => ['sistema', 'Max'],
        'documentos'     => ['exportacao', 'Documentos'],
        'frequencia'     => ['academico', 'Frequência'],
        'registros-aula' => ['academico', 'Registro de aula'],
        'avaliacoes'     => ['academico', 'Avaliações'],
        'notas'          => ['academico', 'Notas'],
        'diario'         => ['academico', 'Diário de classe'],
        'planejamento'   => ['academico', 'Planejamento'],
        'calendario'     => ['academico', 'Calendário escolar'],
        'bncc'           => ['academico', 'BNCC'],
        'professores'    => ['cadastro', 'Professores'],
        'alunos'         => ['cadastro', 'Alunos'],
        'responsaveis'   => ['cadastro', 'Responsáveis'],
        'financeiro'     => ['financeiro', 'Financeiro'],
        'webhooks'       => ['financeiro', 'Webhooks'],
        'comunicacao'    => ['comunicacao', 'Comunicação'],
        'whatsapp'       => ['comunicacao', 'WhatsApp'],
        'parametrizacoes' => ['sistema', 'Parametrizações'],
        'escola'         => ['sistema', 'Dados da escola'],
    ];

    /** Sub-rotas de api/admin/... (o prefixo admin mistura vários módulos). */
    private const MODULOS_ADMIN = [
        'usuarios'      => ['cadastro', 'Usuários'],
        'turmas'        => ['cadastro', 'Turmas'],
        'painel'        => ['sistema', 'Painel da Direção'],
        'logs'          => ['sistema', 'Logs do sistema'],
        'permissoes'    => ['sistema', 'Permissões'],
        'perfis'        => ['cadastro', 'Usuários'],
    ];

    /**
     * Categoria de uma ação já registrada pelos controllers (LOGIN_SUCESSO,
     * BAIXA_PARCELA...). Ordem importa: o primeiro padrão que casar vence.
     */
    public static function categoriaDaAcao(string $acao): string
    {
        $regras = [
            'acesso'      => ['LOGIN', 'LOGOUT', 'SESSAO', 'ACESSO_NEGADO', 'SENHA'],
            'navegacao'   => ['NAVEGACAO'],
            'exportacao'  => ['EXPORTACAO', 'IMPRESSAO', 'RELATORIO', 'DOCUMENTO'],
            'financeiro'  => ['PARCELA', 'PLANO_PAGAMENTO', 'DESCONTO', 'MATRICULA_FINANCEIRA', 'CONTRATO', 'SICOOB', 'ASAAS', 'CONVENIO', 'FINANCEIRO', 'RECIBO', 'COBRANCA'],
            'academico'   => ['NOTA', 'AVALIACAO', 'FREQUENCIA', 'REGISTRO_AULA', 'CALENDARIO', 'ANO_LETIVO', 'TURMAS', 'STATUS_TURMA', 'PROMOCAO', 'DIARIO', 'PLANEJAMENTO'],
            'cadastro'    => ['ALUNO', 'PROFESSOR', 'RESPONSAVEL', 'USUARIO', 'TURMA'],
            'comunicacao' => ['WHATSAPP', 'MURAL', 'OCORRENCIA', 'RECADO', 'COMUNICADO'],
        ];

        foreach ($regras as $categoria => $padroes) {
            foreach ($padroes as $p) {
                if (str_contains($acao, $p)) {
                    return $categoria;
                }
            }
        }

        return 'sistema';
    }

    /** [categoria, rótulo do módulo] a partir do caminho (sem o "api/"). */
    public static function moduloDaRota(string $path): array
    {
        $partes = explode('/', trim(preg_replace('#^api/#', '', $path), '/'));
        $prefixo = $partes[0] ?? '';

        if ($prefixo === 'admin') {
            return self::MODULOS_ADMIN[$partes[1] ?? ''] ?? ['sistema', 'Administração'];
        }

        return self::MODULOS[$prefixo] ?? ['sistema', ucfirst($prefixo ?: 'API')];
    }

    /**
     * Nome legível da operação a partir do método do controller
     * (vincularAlunos → "vincular alunos", store → "inclusão").
     */
    public static function operacao(?string $metodoController, string $metodoHttp): string
    {
        $fixos = [
            'index' => 'consulta', 'show' => 'consulta',
            'store' => 'inclusão', 'update' => 'alteração', 'destroy' => 'exclusão',
            'patchStatus' => 'alteração de status', 'updateStatus' => 'alteração de status',
        ];
        if ($metodoController && isset($fixos[$metodoController])) {
            return $fixos[$metodoController];
        }
        if ($metodoController) {
            return mb_strtolower(trim(preg_replace('/(?<!^)[A-Z]/', ' $0', $metodoController)));
        }

        return match ($metodoHttp) {
            'POST' => 'inclusão',
            'DELETE' => 'exclusão',
            default => 'alteração',
        };
    }
}
