<?php

use App\Http\Controllers\Api\AlunoController;
use App\Http\Controllers\Api\AuditoriaController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvaliacaoController;
use App\Http\Controllers\Api\BnccController;
use App\Http\Controllers\Api\AsaasController;
use App\Http\Controllers\Api\BoletoSicoobController;
use App\Http\Controllers\Api\ContratoFinanceiroController;
use App\Http\Controllers\Api\ConvenioCobrancaController;
use App\Http\Controllers\Api\DescontoPadraoController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\DocumentoEscolarController;
use App\Http\Controllers\Api\EscolaConfigController;
use App\Http\Controllers\Api\FinanceiroController;
use App\Http\Controllers\Api\FinanceiroParametrosController;
use App\Http\Controllers\Api\FrequenciaController;
use App\Http\Controllers\Api\ModeloAvaliacaoController;
use App\Http\Controllers\Api\AnoLetivoController;
use App\Http\Controllers\Api\LogController;
use App\Http\Controllers\Api\MatriculaFinanceiraController;
use App\Http\Controllers\Api\MaxAgentController;
use App\Http\Controllers\Api\MaxConversaController;
use App\Http\Controllers\Api\MaxUsageController;
use App\Http\Controllers\Api\MetadadoController;
use App\Http\Controllers\Api\NotaController;
use App\Http\Controllers\Api\NotificacaoWhatsappController;
use App\Http\Controllers\Api\PainelDirecaoController;
use App\Http\Controllers\Api\ParcelaController;
use App\Http\Controllers\Api\PlanoPagamentoController;
use App\Http\Controllers\Api\ProfessorController;
use App\Http\Controllers\Api\ReciboController;
use App\Http\Controllers\Api\RegistroAulaController;
use App\Http\Controllers\Api\ResponsavelController;
use App\Http\Controllers\Api\TurmaController;
use App\Http\Controllers\Api\UsuarioController;
use App\Http\Controllers\Api\VinculoProfessorTurmaDisciplinaController;
use Illuminate\Support\Facades\Route;

// Equivalente a api/auth/login.php — sem middleware, é o próprio ponto de entrada.
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/auth/permissoes', [AuthController::class, 'permissoes'])->middleware('max.jwt');

// Acesso por permissão (max.pode:...), não por perfil: o padrão de cada
// perfil e as exceções da escola ficam em App\Support\Permissoes e na tela
// Configurações → Permissões. "max.role:equipe" = qualquer perfil da equipe.

Route::prefix('agent')->middleware('max.jwt')->group(function () {
    Route::post('/chat', [MaxAgentController::class, 'chat']);
    Route::get('/uso', [MaxUsageController::class, 'meuUso']);

    Route::get('/conversas', [MaxConversaController::class, 'index']);
    Route::get('/conversas/{id}', [MaxConversaController::class, 'show']);
    Route::delete('/conversas/{id}', [MaxConversaController::class, 'destroy']);

    Route::middleware('max.pode:configuracoes.escola')->group(function () {
        Route::get('/uso-admin', [MaxUsageController::class, 'usoAdmin']);
    });
});

// Auditoria: eventos que só o front vê (telas visitadas, exportações feitas
// no navegador, impressões, logout). Qualquer usuário logado.
Route::prefix('auditoria')->middleware('max.jwt')->group(function () {
    Route::post('/eventos', [AuditoriaController::class, 'eventos']);
});

// Equivalente a api/documentos/*.php do backend legado — mesmo JWT compartilhado
// (o middleware "max.jwt" é só validação de token, não é específico do Max).
Route::prefix('documentos')->middleware('max.jwt')->group(function () {
    Route::get('/declaracao-matricula', [DocumentoController::class, 'declaracaoMatricula']);
    Route::get('/comprovante-pagamentos', [DocumentoController::class, 'comprovantePagamentos']);
    Route::get('/historico-escolar', [DocumentoController::class, 'historicoEscolar']);
    Route::get('/ficha-aluno', [DocumentoController::class, 'fichaAluno']);
    Route::get('/ficha-inscricao', [DocumentoController::class, 'fichaInscricao']);

    Route::get('/declaracao-frequencia', [DocumentoEscolarController::class, 'declaracaoFrequencia']);
    Route::get('/declaracao-quitacao', [DocumentoEscolarController::class, 'declaracaoQuitacao']);
    Route::get('/nada-consta', [DocumentoEscolarController::class, 'nadaConsta']);
    Route::get('/declaracao-transferencia', [DocumentoEscolarController::class, 'declaracaoTransferencia']);
    Route::get('/declaracao-escolaridade', [DocumentoEscolarController::class, 'declaracaoEscolaridade']);
    Route::get('/relatorio-ocorrencias', [DocumentoEscolarController::class, 'relatorioOcorrencias']);
    Route::get('/carteirinha', [DocumentoEscolarController::class, 'carteirinha']);

    // Documentos da turma e atestado de vaga: só a secretaria.
    Route::middleware('max.pode:documentos.emitir')->group(function () {
        Route::get('/ata-resultados', [DocumentoEscolarController::class, 'ataResultados']);
        Route::get('/lista-alunos', [DocumentoEscolarController::class, 'listaAlunos']);
        Route::get('/atestado-vaga', [DocumentoEscolarController::class, 'atestadoVaga']);
    });
});

// Equivalente a api/frequencia/*.php do backend legado.
Route::prefix('frequencia')->middleware('max.jwt')->group(function () {
    Route::get('/alunos-por-turma', [FrequenciaController::class, 'alunosPorTurma']);
    Route::get('/por-aluno', [FrequenciaController::class, 'porAluno']);
    Route::get('/por-dia', [FrequenciaController::class, 'porDia']);
    Route::get('/resumo', [FrequenciaController::class, 'resumo']);
    Route::get('/resumo-geral', [FrequenciaController::class, 'resumoGeral']);
    Route::get('/resumo-mensal', [FrequenciaController::class, 'resumoMensal']);
    Route::get('/relatorio', [FrequenciaController::class, 'relatorio']);
});

// Equivalente a api/registros_aula/*.php do backend legado.
Route::prefix('registros-aula')->middleware('max.jwt')->group(function () {
    Route::get('/', [RegistroAulaController::class, 'index']);
    Route::get('/resumo-mensal', [RegistroAulaController::class, 'resumoMensal']);
    Route::post('/', [RegistroAulaController::class, 'salvar']);
    Route::delete('/', [RegistroAulaController::class, 'excluir']);
});

// Equivalente a api/avaliacoes/*.php do backend legado (get_frequencia_anual.php
// e post_frequencia.php não portados — dead code, referenciam colunas que não
// existem na tabela `frequencia` atual e não têm nenhum uso no frontend).
Route::prefix('avaliacoes')->middleware('max.jwt')->group(function () {
    Route::get('/tipos', [AvaliacaoController::class, 'tiposAvaliacao']);
    Route::get('/unidades-letivas', [AvaliacaoController::class, 'unidadesLetivas']);
    Route::get('/', [AvaliacaoController::class, 'index']);
    Route::post('/', [AvaliacaoController::class, 'store']);
    Route::put('/', [AvaliacaoController::class, 'update']);
    Route::delete('/', [AvaliacaoController::class, 'destroy']);
});

Route::prefix('notas')->middleware('max.jwt')->group(function () {
    Route::get('/por-aluno', [NotaController::class, 'porAluno']);
    Route::get('/por-avaliacao', [NotaController::class, 'alunosPorAvaliacao']);
    Route::get('/grade-turma-disciplina', [NotaController::class, 'porTurmaDisciplina']);
    Route::get('/grade-turma-disciplina/grafico', [NotaController::class, 'porTurmaDisciplinaChart']);
    Route::get('/media-final-anual', [NotaController::class, 'mediaFinalAnual']);
    Route::get('/medias-bimestrais', [NotaController::class, 'mediasBimestrais']);
    Route::post('/lote', [NotaController::class, 'storeLote']);
    Route::put('/', [NotaController::class, 'update']);
});

// Equivalente a api/professores/*.php do backend legado (get_frequencias.php e
// post_frequencia.php não portados — dead code, escrevem/leem uma tabela
// `frequencias` no plural que não existe; get_todos_alunos.php não portado —
// sem nenhum uso no frontend).
Route::prefix('professores')->middleware('max.jwt')->group(function () {
    Route::get('/', [ProfessorController::class, 'index']);
    Route::post('/', [ProfessorController::class, 'store']);
    Route::post('/atualizar', [ProfessorController::class, 'update']);
    Route::get('/minhas-turmas', [ProfessorController::class, 'minhasTurmas']);
    Route::get('/painel', [ProfessorController::class, 'painel']);
    Route::get('/minhas-disciplinas', [ProfessorController::class, 'minhasDisciplinas']);
    Route::get('/alunos-por-turma', [ProfessorController::class, 'alunosPorTurma']);
    Route::get('/frequencia-por-turma', [ProfessorController::class, 'frequenciaPorTurma']);
    Route::get('/historico-frequencia', [ProfessorController::class, 'historicoFrequencia']);
    Route::post('/frequencia', [ProfessorController::class, 'salvarFrequencia']);
    Route::delete('/frequencia', [ProfessorController::class, 'excluirFrequencia']);
});

// Equivalente a api/alunos/*.php. Atenção: setFoto/deleteFoto gravam no disco
// local do Laravel, mas a foto_url exibida no front sempre aponta pro domínio
// de produção — upload local não fica visível até este backend estar no ar
// nesse domínio (ver comentário em AlunoController::setFoto()).
Route::prefix('alunos')->middleware('max.jwt')->group(function () {
    Route::get('/', [AlunoController::class, 'index']);
    Route::post('/', [AlunoController::class, 'store']);
    Route::put('/', [AlunoController::class, 'update']);
    Route::post('/inativar', [AlunoController::class, 'inativar']);
    Route::get('/boletim', [AlunoController::class, 'boletim']);
    Route::get('/boletim-turma', [AlunoController::class, 'boletimTurma']);
    Route::post('/foto', [AlunoController::class, 'setFoto']);
    Route::post('/foto/remover', [AlunoController::class, 'deleteFoto']);
    Route::post('/vincular-responsaveis', [AlunoController::class, 'vincularResponsaveis']);
    Route::get('/proxima-matricula', [AlunoController::class, 'proximaMatriculaSugerida']);
    Route::get('/{id}', [AlunoController::class, 'show'])->whereNumber('id');
    Route::post('/{id}/acesso', [AlunoController::class, 'acessoPortal'])->whereNumber('id');
});

// Equivalente a api/responsaveis/*.php.
Route::prefix('responsaveis')->middleware('max.jwt')->group(function () {
    Route::get('/', [ResponsavelController::class, 'index']);
    Route::post('/', [ResponsavelController::class, 'store']);
    Route::post('/atualizar', [ResponsavelController::class, 'update']);
    Route::get('/dependentes', [ResponsavelController::class, 'dependentes']);
    Route::get('/painel', [ResponsavelController::class, 'painel']);
    Route::get('/por-aluno', [ResponsavelController::class, 'responsaveisPorAluno']);
    Route::post('/vincular-alunos', [ResponsavelController::class, 'vincularAlunos']);
});

// Avisos do Asaas (webhook). Público: autentica pelo token no header
// asaas-access-token; a escola vem do subdomínio (ResolveTenantDatabase).
// Sem limite de requisições de propósito: num dia de muitos pagamentos o
// Asaas manda rajadas de avisos, e respostas 429 contam como falha — 15
// seguidas pausam a fila de webhooks da escola. O token já barra terceiros.
Route::post('/webhooks/asaas', [\App\Http\Controllers\Api\AsaasWebhookController::class, 'receber']);

// Equivalente a api/financeiro/*.php, incluindo api/financeiro/convenio/*.php
// (boleto bancário Sicoob — remessa/retorno CNAB, boleto visual).
Route::prefix('financeiro')->middleware('max.jwt')->group(function () {
    // Recibo tem regra de acesso própria (admin/diretoria vê tudo, responsável só
    // os recibos dos próprios dependentes) — não cabe num único max.role.
    Route::get('/recibos', [ReciboController::class, 'index']);

    Route::middleware('max.role:Responsável')->group(function () {
        Route::get('/minhas-parcelas', [ParcelaController::class, 'minhasParcelas']);
        Route::get('/minhas-parcelas/{id}/boleto', [ParcelaController::class, 'meuBoleto'])->whereNumber('id');
        Route::get('/minhas-parcelas/{id}/pix', [ParcelaController::class, 'meuPix'])->whereNumber('id');
    });

    Route::middleware('max.pode:financeiro.operar')->group(function () {
        Route::get('/configuracoes', [FinanceiroController::class, 'configuracoes']);
        Route::get('/dashboard', [FinanceiroController::class, 'dashboard']);
        Route::get('/pendencias', [ContratoFinanceiroController::class, 'pendencias']);
        Route::get('/contratos/sem-contrato', [ContratoFinanceiroController::class, 'semContrato']);
        Route::get('/contratos/sugestao', [ContratoFinanceiroController::class, 'sugestao']);
        Route::get('/contratos/dispensas', [ContratoFinanceiroController::class, 'dispensas']);
        Route::post('/contratos/dispensas', [ContratoFinanceiroController::class, 'dispensar']);
        Route::delete('/contratos/dispensas', [ContratoFinanceiroController::class, 'removerDispensa']);

        Route::get('/planos', [PlanoPagamentoController::class, 'index']);
        Route::get('/descontos', [DescontoPadraoController::class, 'index']);

        Route::get('/matriculas/alunos-turma', [MatriculaFinanceiraController::class, 'alunosTurmaMatricula']);
        Route::get('/matriculas', [MatriculaFinanceiraController::class, 'index']);
        Route::post('/matriculas', [MatriculaFinanceiraController::class, 'store']);
        Route::post('/matriculas/lote', [MatriculaFinanceiraController::class, 'storeLote']);
        Route::post('/matriculas/cancelar', [MatriculaFinanceiraController::class, 'cancelar']);

        Route::get('/parcelas', [ParcelaController::class, 'contasReceber']);
        Route::post('/parcelas/baixa', [ParcelaController::class, 'baixa']);
        Route::put('/parcelas', [ParcelaController::class, 'update']);
        Route::post('/parcelas/renegociar', [ParcelaController::class, 'renegociar']);
        Route::get('/inadimplencia', [ParcelaController::class, 'inadimplencia']);

        Route::get('/convenio/boleto-html', [BoletoSicoobController::class, 'boletoHtml']);
        Route::post('/convenio/gerar-remessa', [BoletoSicoobController::class, 'gerarRemessa']);
        Route::post('/convenio/importar-retorno', [BoletoSicoobController::class, 'importarRetorno']);

        // Central Sicoob (passo a passo remessa → banco → retorno).
        Route::get('/sicoob/resumo', [BoletoSicoobController::class, 'resumo']);
        Route::get('/sicoob/candidatas', [BoletoSicoobController::class, 'candidatas']);
        Route::get('/sicoob/remessas', [BoletoSicoobController::class, 'remessas']);
        Route::get('/sicoob/remessas/{id}/arquivo', [BoletoSicoobController::class, 'arquivoRemessa'])->whereNumber('id');
        Route::post('/sicoob/remessas/{id}/enviada', [BoletoSicoobController::class, 'marcarRemessaEnviada'])->whereNumber('id');
        Route::post('/sicoob/retorno/analisar', [BoletoSicoobController::class, 'analisarRetorno']);
        Route::get('/sicoob/retornos', [BoletoSicoobController::class, 'retornos']);
        Route::get('/sicoob/retornos/{id}', [BoletoSicoobController::class, 'retornoItens'])->whereNumber('id');

        // Central Asaas (gateway: boleto com Pix + baixa automática).
        Route::get('/asaas/clientes', [AsaasController::class, 'clientes']);
        Route::post('/asaas/clientes/sincronizar', [AsaasController::class, 'sincronizarClientes']);
        Route::get('/asaas/cobrancas/resumo', [AsaasController::class, 'resumoCobrancas']);
        Route::get('/asaas/cobrancas/candidatas', [AsaasController::class, 'candidatas']);
        Route::post('/asaas/cobrancas/emitir', [AsaasController::class, 'emitir']);
        Route::get('/asaas/cobrancas', [AsaasController::class, 'cobrancas']);
        Route::post('/asaas/cobrancas/{id}/cancelar', [AsaasController::class, 'cancelarCobranca'])->whereNumber('id');
        Route::post('/asaas/sincronizar', [AsaasController::class, 'sincronizar']);
        Route::get('/asaas/eventos', [AsaasController::class, 'eventos']);
    });

    // Planos, descontos e convênio bancário (dados do cedente).
    Route::middleware('max.pode:financeiro.configurar')->group(function () {
        Route::post('/planos', [PlanoPagamentoController::class, 'store']);
        Route::put('/planos', [PlanoPagamentoController::class, 'update']);
        Route::delete('/planos', [PlanoPagamentoController::class, 'destroy']);

        Route::post('/descontos', [DescontoPadraoController::class, 'store']);
        Route::put('/descontos', [DescontoPadraoController::class, 'update']);

        Route::get('/convenio', [ConvenioCobrancaController::class, 'index']);
        Route::post('/convenio', [ConvenioCobrancaController::class, 'store']);
    });
});

// Notificações WhatsApp: painel, teste manual e histórico. O envio diário é o
// comando whatsapp:notificacoes-diarias (cron).
Route::prefix('whatsapp')->middleware(['max.jwt', 'max.pode:whatsapp.gerenciar'])->group(function () {
    Route::get('/painel', [NotificacaoWhatsappController::class, 'painel']);
    Route::post('/testar', [NotificacaoWhatsappController::class, 'testar']);
    Route::get('/historico', [NotificacaoWhatsappController::class, 'historico']);
});

// Equivalente a api/admin/*.php (exceto get_disciplinas_por_turma.php — dead
// code, sem nenhum uso no frontend).
//
// Fix vs. legado: get_alunos_por_turma_chart.php e get_ocupacao_turma.php não
// tinham NENHUMA validação de JWT (endpoints completamente públicos) — aqui
// ganharam o mesmo nível de acesso de get_turmas_detalhes (Administrador,
// Diretoria, Professor), já que exibem dados agregados da mesma natureza.
Route::prefix('admin')->middleware('max.jwt')->group(function () {
    Route::get('/anos-letivos', [MetadadoController::class, 'anosLetivos']);
    Route::get('/disciplinas', [MetadadoController::class, 'disciplinas']);
    Route::get('/sistemas-avaliacao', [MetadadoController::class, 'sistemasAvaliacao']);

    Route::middleware('max.role:equipe')->group(function () {
        Route::get('/turmas', [TurmaController::class, 'index']);
        Route::get('/turmas/{id}', [TurmaController::class, 'show'])->whereNumber('id');
        Route::get('/turmas/alunos-por-turma-chart', [TurmaController::class, 'alunosPorTurmaChart']);
        Route::get('/turmas/ocupacao', [TurmaController::class, 'ocupacao']);
        Route::get('/turmas/situacao', [\App\Http\Controllers\Api\TurmaSituacaoController::class, 'situacao']);
        Route::get('/turmas/{id}/resumo', [\App\Http\Controllers\Api\TurmaSituacaoController::class, 'resumo'])->whereNumber('id');
    });

    // Logs do sistema (auditoria). Padrão: só Administrador.
    Route::middleware('max.pode:logs.ver')->group(function () {
        Route::get('/logs', [LogController::class, 'index']);
        Route::get('/logs/filtros', [LogController::class, 'filtros']);
        Route::get('/logs/resumo', [LogController::class, 'resumo']);
        Route::get('/logs/exportar', [LogController::class, 'exportar']);
        Route::get('/logs/sessoes', [LogController::class, 'sessoes']);
        Route::get('/logs/sessoes/{sid}', [LogController::class, 'sessao'])->where('sid', '[A-Za-z0-9]+');
        Route::get('/logs/{id}', [LogController::class, 'show'])->whereNumber('id');
    });

    // Permissões por perfil: só Administrador, sempre.
    Route::middleware('max.role:Administrador')->group(function () {
        Route::get('/permissoes', [\App\Http\Controllers\Api\PermissaoController::class, 'index']);
        Route::put('/permissoes/{perfil}', [\App\Http\Controllers\Api\PermissaoController::class, 'update']);
        Route::post('/permissoes/{perfil}/restaurar', [\App\Http\Controllers\Api\PermissaoController::class, 'restaurar']);
    });

    // Painel da Direção (home da gestão).
    Route::middleware('max.pode:painel.direcao')->group(function () {
        Route::get('/painel', [PainelDirecaoController::class, 'index']);
        Route::get('/painel/detalhe/{tipo}', [PainelDirecaoController::class, 'detalhe'])->whereAlpha('tipo');
        Route::get('/painel/briefing', [PainelDirecaoController::class, 'briefing']);
        Route::post('/painel/explicar', [PainelDirecaoController::class, 'explicar']);
    });

    Route::middleware('max.pode:equipe.gerenciar')->group(function () {
        Route::get('/perfis', [UsuarioController::class, 'perfis']);
        Route::get('/usuarios', [UsuarioController::class, 'index']);
        Route::post('/usuarios', [UsuarioController::class, 'store']);
        Route::put('/usuarios', [UsuarioController::class, 'update']);
        Route::patch('/usuarios/status', [UsuarioController::class, 'patchStatus']);
    });

    Route::middleware('max.pode:turmas.gerenciar')->group(function () {
        Route::post('/turmas', [TurmaController::class, 'store']);
        Route::put('/turmas', [TurmaController::class, 'update']);
        Route::patch('/turmas/status', [TurmaController::class, 'updateStatus']);
        Route::post('/turmas/vincular-alunos', [TurmaController::class, 'vincularAlunos']);
        Route::post('/turmas/promover-alunos', [TurmaController::class, 'promoverAlunos']);
        Route::post('/turmas/transferir-aluno', [TurmaController::class, 'transferirAluno']);

        Route::post('/vinculos-turma', [VinculoProfessorTurmaDisciplinaController::class, 'porTurma']);
        Route::post('/vinculos-professor', [VinculoProfessorTurmaDisciplinaController::class, 'porProfessor']);
        Route::delete('/vinculos', [VinculoProfessorTurmaDisciplinaController::class, 'destroy']);
    });
});

// Equivalente a api/bncc/*.php — avaliação de educação infantil por
// habilidades da BNCC. Papéis mistos (admin/professor/responsável com
// escopo próprio) tratados dentro do controller, não cabem num único
// max.role.
Route::prefix('bncc')->middleware('max.jwt')->group(function () {
    Route::get('/semestres', [BnccController::class, 'semestres']);
    Route::get('/habilidades', [BnccController::class, 'habilidades']);
    Route::get('/status-alunos', [BnccController::class, 'statusAlunos']);
    Route::get('/registros-aluno', [BnccController::class, 'registrosAluno']);
    Route::post('/registros', [BnccController::class, 'storeRegistros']);
});

// Parametrizações da escola (Configurações → Parametrizações). Começa pelos
// modelos de avaliação; novas seções entram como novos sub-prefixos aqui.
// Central de Comunicação (mural, ocorrências, recados). Permissões por perfil
// dentro dos serviços (App\Services\Comunicacao\PerfilComunicacao).
Route::prefix('comunicacao')->middleware('max.jwt')->group(function () {
    Route::get('/resumo', [\App\Http\Controllers\Api\ComunicacaoController::class, 'resumo']);
    Route::get('/pulso', [\App\Http\Controllers\Api\ComunicacaoController::class, 'pulso']);

    Route::get('/mural', [\App\Http\Controllers\Api\ComunicacaoController::class, 'mural']);
    Route::get('/mural/opcoes', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralOpcoes']);
    Route::post('/mural', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralSalvar']);
    Route::post('/mural/lidos', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralTodosLidos']);
    Route::post('/mural/{id}', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralSalvar'])->whereNumber('id');
    Route::delete('/mural/{id}', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralExcluir'])->whereNumber('id');
    Route::post('/mural/{id}/lido', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralLido'])->whereNumber('id');
    Route::post('/mural/{id}/reagir', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralReagir'])->whereNumber('id');
    Route::post('/mural/{id}/ocultar', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralOcultar'])->whereNumber('id');
    Route::get('/mural/{id}/confirmacoes', [\App\Http\Controllers\Api\ComunicacaoController::class, 'muralConfirmacoes'])->whereNumber('id');

    Route::get('/ocorrencias', [\App\Http\Controllers\Api\ComunicacaoController::class, 'ocorrencias']);
    Route::get('/alunos', [\App\Http\Controllers\Api\ComunicacaoController::class, 'alunos']);
    Route::post('/ocorrencias', [\App\Http\Controllers\Api\ComunicacaoController::class, 'ocorrenciaSalvar']);
    Route::put('/ocorrencias/{id}', [\App\Http\Controllers\Api\ComunicacaoController::class, 'ocorrenciaSalvar'])->whereNumber('id');
    Route::delete('/ocorrencias/{id}', [\App\Http\Controllers\Api\ComunicacaoController::class, 'ocorrenciaExcluir'])->whereNumber('id');
    Route::post('/ocorrencias/{id}/ciente', [\App\Http\Controllers\Api\ComunicacaoController::class, 'ocorrenciaCiente'])->whereNumber('id');

    Route::get('/recados', [\App\Http\Controllers\Api\ComunicacaoController::class, 'recados']);
    Route::get('/recados/contatos', [\App\Http\Controllers\Api\ComunicacaoController::class, 'recadoContatos']);
    Route::post('/recados', [\App\Http\Controllers\Api\ComunicacaoController::class, 'recadoIniciar']);
    Route::get('/recados/{id}', [\App\Http\Controllers\Api\ComunicacaoController::class, 'recadoAbrir'])->whereNumber('id');
    Route::post('/recados/{id}', [\App\Http\Controllers\Api\ComunicacaoController::class, 'recadoResponder'])->whereNumber('id');
    Route::post('/recados/{id}/encerrar', [\App\Http\Controllers\Api\ComunicacaoController::class, 'recadoEncerrar'])->whereNumber('id');
});

// Planejamento de aulas (com o Max). Professor: turmas/disciplinas dele;
// gestão: todas, mais a fila de aprovação (se ligada nas Parametrizações).
Route::prefix('planejamento')->middleware('max.jwt')->group(function () {
    Route::get('/opcoes', [\App\Http\Controllers\Api\PlanejamentoController::class, 'opcoes']);
    Route::get('/', [\App\Http\Controllers\Api\PlanejamentoController::class, 'periodo']);
    Route::get('/bncc', [\App\Http\Controllers\Api\PlanejamentoController::class, 'bncc']);
    Route::post('/', [\App\Http\Controllers\Api\PlanejamentoController::class, 'store']);
    Route::post('/lote', [\App\Http\Controllers\Api\PlanejamentoController::class, 'lote']);
    Route::post('/gerar', [\App\Http\Controllers\Api\PlanejamentoController::class, 'gerar']);
    Route::put('/{id}', [\App\Http\Controllers\Api\PlanejamentoController::class, 'update'])->whereNumber('id');
    Route::delete('/{id}', [\App\Http\Controllers\Api\PlanejamentoController::class, 'destroy'])->whereNumber('id');
    Route::post('/{id}/enviar', [\App\Http\Controllers\Api\PlanejamentoController::class, 'enviar'])->whereNumber('id');
    Route::post('/{id}/dada', [\App\Http\Controllers\Api\PlanejamentoController::class, 'dada'])->whereNumber('id');
    Route::middleware('max.pode:planejamento.aprovar')->group(function () {
        Route::get('/pendentes', [\App\Http\Controllers\Api\PlanejamentoController::class, 'pendentes']);
        Route::post('/{id}/aprovar', [\App\Http\Controllers\Api\PlanejamentoController::class, 'aprovar'])->whereNumber('id');
        Route::post('/{id}/devolver', [\App\Http\Controllers\Api\PlanejamentoController::class, 'devolver'])->whereNumber('id');
    });
});

// Diário de Classe: frequência, conteúdos, notas e pendências do bimestre.
Route::prefix('diario')->middleware('max.jwt')->group(function () {
    Route::get('/opcoes', [\App\Http\Controllers\Api\DiarioClasseController::class, 'opcoes']);
    Route::get('/', [\App\Http\Controllers\Api\DiarioClasseController::class, 'show']);
});

// Calendário escolar (feriados, recessos, sábados letivos, eventos). Leitura
// para qualquer perfil logado; alteração só para a gestão.
Route::prefix('calendario')->middleware('max.jwt')->group(function () {
    Route::get('/{ano}', [\App\Http\Controllers\Api\CalendarioEscolarController::class, 'show'])->whereNumber('ano');
    Route::middleware('max.pode:calendario.editar')->group(function () {
        Route::post('/{ano}/marcacoes', [\App\Http\Controllers\Api\CalendarioEscolarController::class, 'store'])->whereNumber('ano');
        Route::put('/marcacoes/{id}', [\App\Http\Controllers\Api\CalendarioEscolarController::class, 'update'])->whereNumber('id');
        Route::delete('/marcacoes/{id}', [\App\Http\Controllers\Api\CalendarioEscolarController::class, 'destroy'])->whereNumber('id');
        Route::post('/{ano}/feriados-nacionais', [\App\Http\Controllers\Api\CalendarioEscolarController::class, 'importarNacionais'])->whereNumber('ano');
    });
});

Route::prefix('parametrizacoes')->middleware('max.jwt')->group(function () {
    Route::middleware('max.pode:configuracoes.escola')->group(function () {
        Route::get('/pedagogico', [\App\Http\Controllers\Api\PlanejamentoController::class, 'configuracao']);
        Route::put('/pedagogico', [\App\Http\Controllers\Api\PlanejamentoController::class, 'salvarConfiguracao']);
        Route::get('/modelos-avaliacao', [ModeloAvaliacaoController::class, 'index']);
        Route::post('/modelos-avaliacao', [ModeloAvaliacaoController::class, 'store']);
        Route::put('/modelos-avaliacao/{id}', [ModeloAvaliacaoController::class, 'update'])->whereNumber('id');
        Route::patch('/modelos-avaliacao/{id}/status', [ModeloAvaliacaoController::class, 'updateStatus'])->whereNumber('id');
        Route::delete('/modelos-avaliacao/{id}', [ModeloAvaliacaoController::class, 'destroy'])->whereNumber('id');

        Route::get('/max', [PainelDirecaoController::class, 'configMax']);
        Route::put('/max', [PainelDirecaoController::class, 'salvarConfigMax']);

        Route::get('/anos-letivos', [AnoLetivoController::class, 'index']);
        Route::post('/anos-letivos', [AnoLetivoController::class, 'store']);
        Route::put('/anos-letivos/{ano}', [AnoLetivoController::class, 'update'])->whereNumber('ano');
        Route::post('/anos-letivos/{ano}/duplicar-turmas', [AnoLetivoController::class, 'duplicarTurmas'])->whereNumber('ano');
        Route::post('/anos-letivos/{ano}/encerrar-turmas', [AnoLetivoController::class, 'encerrarTurmas'])->whereNumber('ano');
    });

    Route::middleware('max.pode:financeiro.configurar')->group(function () {
        Route::get('/financeiro', [FinanceiroParametrosController::class, 'show']);
        Route::put('/financeiro', [FinanceiroParametrosController::class, 'update']);
        Route::post('/financeiro/asaas/testar', [FinanceiroParametrosController::class, 'testarAsaas']);
    });
});

// Equivalente a api/escola/*.php.
Route::prefix('escola')->middleware('max.jwt')->group(function () {
    Route::get('/configuracoes', [EscolaConfigController::class, 'index']);
    Route::post('/configuracoes', [EscolaConfigController::class, 'update']);
    Route::post('/logo', [EscolaConfigController::class, 'setLogo']);
    Route::post('/logo/remover', [EscolaConfigController::class, 'deleteLogo']);
});
