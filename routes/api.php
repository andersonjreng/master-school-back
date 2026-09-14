<?php

use App\Http\Controllers\Api\AvaliacaoController;
use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\FrequenciaController;
use App\Http\Controllers\Api\MaxAgentController;
use App\Http\Controllers\Api\MaxConversaController;
use App\Http\Controllers\Api\MaxUsageController;
use App\Http\Controllers\Api\NotaController;
use App\Http\Controllers\Api\ProfessorController;
use App\Http\Controllers\Api\RegistroAulaController;
use Illuminate\Support\Facades\Route;

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
