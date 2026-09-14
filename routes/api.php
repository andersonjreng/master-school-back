<?php

use App\Http\Controllers\Api\AlunoController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AvaliacaoController;
use App\Http\Controllers\Api\DescontoPadraoController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\FinanceiroController;
use App\Http\Controllers\Api\FrequenciaController;
use App\Http\Controllers\Api\MatriculaFinanceiraController;
use App\Http\Controllers\Api\MaxAgentController;
use App\Http\Controllers\Api\MaxConversaController;
use App\Http\Controllers\Api\MaxUsageController;
use App\Http\Controllers\Api\NotaController;
use App\Http\Controllers\Api\ParcelaController;
use App\Http\Controllers\Api\PlanoPagamentoController;
use App\Http\Controllers\Api\ProfessorController;
use App\Http\Controllers\Api\ReciboController;
use App\Http\Controllers\Api\RegistroAulaController;
use App\Http\Controllers\Api\ResponsavelController;
use Illuminate\Support\Facades\Route;

// Equivalente a api/auth/login.php — sem middleware, é o próprio ponto de entrada.
Route::post('/auth/login', [AuthController::class, 'login']);

Route::prefix('agent')->middleware('max.jwt')->group(function () {
    Route::post('/chat', [MaxAgentController::class, 'chat']);
    Route::get('/uso', [MaxUsageController::class, 'meuUso']);

    Route::get('/conversas', [MaxConversaController::class, 'index']);
    Route::get('/conversas/{id}', [MaxConversaController::class, 'show']);
    Route::delete('/conversas/{id}', [MaxConversaController::class, 'destroy']);

    Route::middleware('max.role:Administrador,Diretoria')->group(function () {
        Route::get('/uso-admin', [MaxUsageController::class, 'usoAdmin']);
    });
});

// Equivalente a api/documentos/*.php do backend legado — mesmo JWT compartilhado
// (o middleware "max.jwt" é só validação de token, não é específico do Max).
Route::prefix('documentos')->middleware('max.jwt')->group(function () {
    Route::get('/declaracao-matricula', [DocumentoController::class, 'declaracaoMatricula']);
    Route::get('/comprovante-pagamentos', [DocumentoController::class, 'comprovantePagamentos']);
    Route::get('/historico-escolar', [DocumentoController::class, 'historicoEscolar']);
    Route::get('/ficha-aluno', [DocumentoController::class, 'fichaAluno']);
    Route::get('/ficha-inscricao', [DocumentoController::class, 'fichaInscricao']);
});

// Equivalente a api/frequencia/*.php do backend legado.
Route::prefix('frequencia')->middleware('max.jwt')->group(function () {
    Route::get('/alunos-por-turma', [FrequenciaController::class, 'alunosPorTurma']);
    Route::get('/por-aluno', [FrequenciaController::class, 'porAluno']);
    Route::get('/por-dia', [FrequenciaController::class, 'porDia']);
    Route::get('/resumo', [FrequenciaController::class, 'resumo']);
    Route::get('/resumo-geral', [FrequenciaController::class, 'resumoGeral']);
    Route::get('/resumo-mensal', [FrequenciaController::class, 'resumoMensal']);
});

// Equivalente a api/registros_aula/*.php do backend legado.
Route::prefix('registros-aula')->middleware('max.jwt')->group(function () {
    Route::get('/', [RegistroAulaController::class, 'index']);
    Route::get('/resumo-mensal', [RegistroAulaController::class, 'resumoMensal']);
    Route::post('/', [RegistroAulaController::class, 'salvar']);
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
    Route::get('/minhas-disciplinas', [ProfessorController::class, 'minhasDisciplinas']);
    Route::get('/alunos-por-turma', [ProfessorController::class, 'alunosPorTurma']);
    Route::get('/frequencia-por-turma', [ProfessorController::class, 'frequenciaPorTurma']);
    Route::get('/historico-frequencia', [ProfessorController::class, 'historicoFrequencia']);
    Route::post('/frequencia', [ProfessorController::class, 'salvarFrequencia']);
});

// Equivalente a api/alunos/*.php. Atenção: setFoto/deleteFoto gravam no disco
// local do Laravel, mas a foto_url exibida no front sempre aponta pro domínio
// de produção — upload local não fica visível até este backend estar no ar
// nesse domínio (ver comentário em AlunoController::setFoto()).
Route::prefix('alunos')->middleware('max.jwt')->group(function () {
    Route::get('/', [AlunoController::class, 'index']);
    Route::post('/', [AlunoController::class, 'store']);
    Route::post('/inativar', [AlunoController::class, 'inativar']);
    Route::get('/boletim', [AlunoController::class, 'boletim']);
    Route::get('/boletim-turma', [AlunoController::class, 'boletimTurma']);
    Route::post('/foto', [AlunoController::class, 'setFoto']);
    Route::post('/foto/remover', [AlunoController::class, 'deleteFoto']);
});

// Equivalente a api/responsaveis/*.php.
Route::prefix('responsaveis')->middleware('max.jwt')->group(function () {
    Route::get('/', [ResponsavelController::class, 'index']);
    Route::post('/', [ResponsavelController::class, 'store']);
    Route::post('/atualizar', [ResponsavelController::class, 'update']);
    Route::get('/dependentes', [ResponsavelController::class, 'dependentes']);
    Route::get('/por-aluno', [ResponsavelController::class, 'responsaveisPorAluno']);
});

// Equivalente a api/financeiro/*.php, EXCETO api/financeiro/convenio/*.php (boleto
// bancário Sicoob — remessa/retorno CNAB, boleto visual — fica pra uma etapa
// separada, é um sub-módulo distinto e mais arriscado).
Route::prefix('financeiro')->middleware('max.jwt')->group(function () {
    // Recibo tem regra de acesso própria (admin/diretoria vê tudo, responsável só
    // os recibos dos próprios dependentes) — não cabe num único max.role.
    Route::get('/recibos', [ReciboController::class, 'index']);

    Route::middleware('max.role:Responsável')->group(function () {
        Route::get('/minhas-parcelas', [ParcelaController::class, 'minhasParcelas']);
    });

    Route::middleware('max.role:Administrador,Diretoria')->group(function () {
        Route::get('/configuracoes', [FinanceiroController::class, 'configuracoes']);
        Route::get('/dashboard', [FinanceiroController::class, 'dashboard']);

        Route::get('/planos', [PlanoPagamentoController::class, 'index']);
        Route::post('/planos', [PlanoPagamentoController::class, 'store']);
        Route::put('/planos', [PlanoPagamentoController::class, 'update']);
        Route::delete('/planos', [PlanoPagamentoController::class, 'destroy']);

        Route::get('/descontos', [DescontoPadraoController::class, 'index']);
        Route::post('/descontos', [DescontoPadraoController::class, 'store']);
        Route::put('/descontos', [DescontoPadraoController::class, 'update']);

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
    });
});
