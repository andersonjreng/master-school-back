<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Sistema\LogSistemaService;
use App\Services\Sistema\PermissaoService;
use App\Support\Permissoes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configurações → Permissões: o que cada perfil acessa, por área, com as
 * exceções da escola sobre o padrão. Só Administrador (rota com max.role) —
 * quem ajusta permissões não pode ser alguém que se beneficiaria disso.
 */
class PermissaoController extends Controller
{
    public function __construct(private PermissaoService $permissoes, private LogSistemaService $log)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->permissoes->matriz());
    }

    /** Body: { permissoes: string[] } — o estado completo desejado do perfil. */
    public function update(Request $request, string $perfil): JsonResponse
    {
        if (!in_array($perfil, Permissoes::PERFIS_CONFIGURAVEIS, true)) {
            return response()->json(['error' => 'Este perfil não tem permissões configuráveis.'], 422);
        }
        $desejadas = $request->input('permissoes');
        if (!is_array($desejadas)) {
            return response()->json(['error' => 'Informe a lista de permissões do perfil.'], 422);
        }
        $desconhecidas = array_diff($desejadas, array_keys(Permissoes::CATALOGO));
        if ($desconhecidas) {
            return response()->json(['error' => 'Permissão desconhecida: ' . implode(', ', $desconhecidas)], 422);
        }

        $antes = $this->permissoes->doPerfil($perfil);
        $jwtUser = $request->attributes->get('max_user');
        $excecoes = $this->permissoes->salvarPerfil($perfil, $desejadas, (int) ($jwtUser->id ?? 0));
        $depois = $this->permissoes->doPerfil($perfil);

        $ganhou = array_values(array_diff($depois, $antes));
        $perdeu = array_values(array_diff($antes, $depois));
        $rotulo = fn ($p) => Permissoes::CATALOGO[$p][1] ?? $p;
        $partes = [];
        if ($ganhou) {
            $partes[] = 'passou a ter: ' . implode(', ', array_map($rotulo, $ganhou));
        }
        if ($perdeu) {
            $partes[] = 'deixou de ter: ' . implode(', ', array_map($rotulo, $perdeu));
        }
        $this->log->registrar(
            $request, (int) ($jwtUser->id ?? 0), $jwtUser->nome ?? 'desconhecido', 'PERMISSOES_PERFIL',
            '/api/admin/permissoes/' . $perfil, 'PUT',
            "Permissões do perfil $perfil " . ($partes ? implode('; ', $partes) : 'sem mudança') . '.', 200,
            ['perfil' => $perfil, 'ganhou' => $ganhou, 'perdeu' => $perdeu, 'excecoes' => $excecoes]
        );

        return response()->json(['success' => 'Permissões salvas.', 'matriz' => $this->permissoes->matriz()]);
    }

    /** Volta o perfil ao padrão (apaga as exceções). */
    public function restaurar(Request $request, string $perfil): JsonResponse
    {
        if (!in_array($perfil, Permissoes::PERFIS_CONFIGURAVEIS, true)) {
            return response()->json(['error' => 'Este perfil não tem permissões configuráveis.'], 422);
        }

        return $this->update($request->merge(['permissoes' => Permissoes::comImplicadas(Permissoes::padrao($perfil))]), $perfil);
    }
}
