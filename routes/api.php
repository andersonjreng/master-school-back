<?php

use App\Http\Controllers\Api\DocumentoController;
use App\Http\Controllers\Api\MaxAgentController;
use App\Http\Controllers\Api\MaxConversaController;
use App\Http\Controllers\Api\MaxUsageController;
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
