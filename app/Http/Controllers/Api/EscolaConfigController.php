<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Documentos\EscolaConfigService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Equivalente a api/escola/{get,post}_configuracoes_escola.php e
 * api/escola/{post,delete}_logo_escola.php.
 */
class EscolaConfigController extends Controller
{
    public function __construct(private EscolaConfigService $escolaConfig)
    {
    }

    // Qualquer usuário autenticado pode ler (a topbar mostra nome/logo pra
    // todo mundo, não só Administrador — a escrita é que fica restrita).
    public function index(): JsonResponse
    {
        return response()->json(['success' => true, 'escola' => $this->escolaConfig->obter()]);
    }

    public function update(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        try {
            $escola = $this->escolaConfig->salvar($request->all());
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Falha ao salvar configurações da escola.'], 500);
        }

        return response()->json(['success' => true, 'escola' => $escola]);
    }

    public function setLogo(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $file = $request->file('logo');
        if (!$file || !$file->isValid()) {
            return response()->json(['error' => 'Arquivo de imagem (campo "logo") é obrigatório.'], 400);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            return response()->json(['error' => 'Formato inválido. Use JPG, PNG ou WEBP.'], 400);
        }

        $novoNome = 'logo_' . time() . '.' . $ext;

        try {
            Storage::disk('public')->putFileAs('logo_escola', $file, $novoNome);
        } catch (\Throwable $e) {
            return response()->json(['error' => 'Não foi possível salvar o arquivo no servidor.'], 500);
        }

        $caminhoBanco = 'logo_escola/' . $novoNome;
        $logoAnterior = $this->escolaConfig->logoAtual();

        try {
            $this->escolaConfig->definirLogo($caminhoBanco);
        } catch (\Throwable $e) {
            Storage::disk('public')->delete($caminhoBanco); // rollback do arquivo físico
            return response()->json(['error' => 'Erro ao salvar o logo no banco de dados.'], 500);
        }

        if ($logoAnterior && $logoAnterior !== $caminhoBanco) {
            Storage::disk('public')->delete($logoAnterior);
        }

        return response()->json(['success' => 'Logo atualizado com sucesso!', 'path' => $caminhoBanco]);
    }

    public function deleteLogo(Request $request): JsonResponse
    {
        $jwtUser = $request->attributes->get('max_user');
        $funcoes = (array) ($jwtUser->funcoes ?? $jwtUser->roles ?? []);
        if (!in_array('Administrador', $funcoes, true)) {
            return response()->json(['error' => 'Acesso negado.'], 403);
        }

        $logoAtual = $this->escolaConfig->logoAtual();
        if ($logoAtual) {
            Storage::disk('public')->delete($logoAtual);
        }

        $this->escolaConfig->removerLogo();

        return response()->json(['success' => 'Logo removido com sucesso!']);
    }
}
