<?php

namespace App\Services\Sistema;

use App\Support\Papeis;
use App\Support\Permissoes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Permissões efetivas = padrão do perfil (App\Support\Permissoes) + exceções
 * da escola (`perfil_permissoes`: concedida = 1 dá, 0 retira). Quem tem mais
 * de um perfil fica com a soma deles.
 *
 * Registrado como "scoped": uma instância por requisição (no Octane também),
 * então as exceções são lidas uma vez por requisição e mudanças valem na
 * hora, sem precisar logar de novo.
 */
class PermissaoService
{
    /** @var array<string, array{concedidas: string[], retiradas: string[]}>|null */
    private ?array $excecoes = null;

    public function efetivas(array $funcoes): array
    {
        if (Papeis::ehAdministrador($funcoes)) {
            return array_keys(Permissoes::CATALOGO);
        }

        $todas = [];
        foreach (array_unique($funcoes) as $perfil) {
            $todas = array_merge($todas, $this->doPerfil($perfil));
        }

        return array_values(array_unique($todas));
    }

    /** Verdadeiro se tiver QUALQUER uma das permissões pedidas. */
    public function pode(array $funcoes, string ...$permissoes): bool
    {
        return (bool) array_intersect($permissoes, $this->efetivas($funcoes));
    }

    public function doPerfil(string $perfil): array
    {
        if ($perfil === Papeis::ADMINISTRADOR) {
            return array_keys(Permissoes::CATALOGO);
        }
        if (!in_array($perfil, Permissoes::PERFIS_CONFIGURAVEIS, true)) {
            return [];
        }

        $excecao = $this->excecoes()[$perfil] ?? ['concedidas' => [], 'retiradas' => []];
        $lista = array_diff(array_merge(Permissoes::padrao($perfil), $excecao['concedidas']), $excecao['retiradas']);

        // Retirar "ver" também tira o "gerenciar" que depende dele.
        $lista = array_filter($lista, function ($p) use ($excecao) {
            return !array_intersect(Permissoes::IMPLICA[$p] ?? [], $excecao['retiradas']);
        });

        return Permissoes::comImplicadas(array_values($lista));
    }

    /** Dados da tela Configurações → Permissões. */
    public function matriz(): array
    {
        $excecoes = $this->excecoes();
        $perfis = [];
        foreach (Permissoes::PERFIS_CONFIGURAVEIS as $perfil) {
            $padrao = Permissoes::comImplicadas(Permissoes::padrao($perfil));
            $perfis[] = [
                'perfil'     => $perfil,
                'padrao'     => $padrao,
                'efetivas'   => $this->doPerfil($perfil),
                'concedidas' => $excecoes[$perfil]['concedidas'] ?? [],
                'retiradas'  => $excecoes[$perfil]['retiradas'] ?? [],
                'usuarios'   => $this->usuariosNoPerfil($perfil),
            ];
        }

        $permissoes = [];
        foreach (Permissoes::CATALOGO as $chave => [$area, $rotulo, $descricao]) {
            $permissoes[] = [
                'chave' => $chave, 'area' => $area, 'rotulo' => $rotulo, 'descricao' => $descricao,
                'implica' => Permissoes::IMPLICA[$chave] ?? [],
            ];
        }

        $areas = [];
        foreach (Permissoes::AREAS as $chave => $rotulo) {
            $areas[] = ['chave' => $chave, 'rotulo' => $rotulo];
        }

        return ['areas' => $areas, 'permissoes' => $permissoes, 'perfis' => $perfis];
    }

    /**
     * Grava o estado desejado de um perfil: só o que difere do padrão vira
     * exceção (o resto é apagado, voltando a seguir o padrão).
     *
     * @return array{concedidas: string[], retiradas: string[]}
     */
    public function salvarPerfil(string $perfil, array $desejadas, int $usuarioId): array
    {
        $funcaoId = (int) DB::table('funcoes')->where('nome_funcao', $perfil)->value('id');
        $desejadas = array_values(array_intersect(Permissoes::comImplicadas($desejadas), array_keys(Permissoes::CATALOGO)));
        $padrao = Permissoes::comImplicadas(Permissoes::padrao($perfil));

        $concedidas = array_values(array_diff($desejadas, $padrao));
        $retiradas = array_values(array_diff($padrao, $desejadas));

        DB::transaction(function () use ($funcaoId, $concedidas, $retiradas, $usuarioId) {
            DB::table('perfil_permissoes')->where('funcao_id', $funcaoId)->delete();
            $linhas = [];
            foreach ([1 => $concedidas, 0 => $retiradas] as $concedida => $lista) {
                foreach ($lista as $permissao) {
                    $linhas[] = [
                        'funcao_id' => $funcaoId, 'permissao' => $permissao, 'concedida' => $concedida,
                        'atualizado_por' => $usuarioId, 'atualizado_em' => now(),
                    ];
                }
            }
            if ($linhas) {
                DB::table('perfil_permissoes')->insert($linhas);
            }
        });

        $this->excecoes = null;

        return ['concedidas' => $concedidas, 'retiradas' => $retiradas];
    }

    private function excecoes(): array
    {
        if ($this->excecoes !== null) {
            return $this->excecoes;
        }
        $this->excecoes = [];
        // Banco ainda sem a migration (escola que não atualizou): só o padrão.
        if (!Schema::hasTable('perfil_permissoes')) {
            return $this->excecoes;
        }
        DB::table('perfil_permissoes as pp')
            ->join('funcoes as f', 'f.id', '=', 'pp.funcao_id')
            ->get(['f.nome_funcao', 'pp.permissao', 'pp.concedida'])
            ->each(function ($r) {
                if (!isset(Permissoes::CATALOGO[$r->permissao])) {
                    return;
                }
                $this->excecoes[$r->nome_funcao][$r->concedida ? 'concedidas' : 'retiradas'][] = $r->permissao;
            });
        foreach ($this->excecoes as &$e) {
            $e += ['concedidas' => [], 'retiradas' => []];
        }

        return $this->excecoes;
    }

    private function usuariosNoPerfil(string $perfil): int
    {
        return (int) DB::table('usuario_funcao as uf')
            ->join('funcoes as f', 'f.id', '=', 'uf.funcao_id')
            ->join('usuarios as u', 'u.id', '=', 'uf.usuario_id')
            ->where('f.nome_funcao', $perfil)->where('u.ativo', 1)
            ->count();
    }
}
