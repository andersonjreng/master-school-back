<?php

use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\FrequenciaController;
use App\Http\Controllers\Api\MaxAgentController;
use App\Http\Controllers\Api\MaxConversaController;
use App\Http\Controllers\Api\MaxUsageController;
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
