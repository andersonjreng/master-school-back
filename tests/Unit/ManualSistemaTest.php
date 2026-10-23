<?php

namespace Tests\Unit;

use App\Services\Max\Manual\ManualSistema;
use App\Services\Max\Manual\VideosTutoriais;
use App\Support\Permissoes;
use PHPUnit\Framework\TestCase;

/** O manual do Max precisa citar só permissões que existem e ter todos os campos. */
class ManualSistemaTest extends TestCase
{
    public function test_itens_completos_e_ids_unicos(): void
    {
        $ids = [];
        foreach (ManualSistema::itens() as $item) {
            foreach (['id', 'titulo', 'area', 'menu', 'acesso', 'palavras', 'passos'] as $campo) {
                $this->assertArrayHasKey($campo, $item, "Item sem '$campo': " . ($item['id'] ?? '?'));
            }
            $this->assertNotSame('', trim($item['passos']), "Item sem passo a passo: {$item['id']}");
            $this->assertNotContains($item['id'], $ids, "id repetido: {$item['id']}");
            $ids[] = $item['id'];
        }
    }

    public function test_videos_apontam_para_itens_do_manual(): void
    {
        $ids = array_column(ManualSistema::itens(), 'id');
        foreach (VideosTutoriais::ids() as $id) {
            $this->assertContains($id, $ids, "Vídeo cadastrado para item inexistente: $id");
            foreach (VideosTutoriais::doItem($id) as $url) {
                $this->assertStringStartsWith('https://', $url, "Link inválido no vídeo de $id");
            }
        }
    }

    public function test_permissoes_citadas_existem(): void
    {
        foreach (ManualSistema::itens() as $item) {
            foreach ($item['acesso']['pode'] ?? [] as $p) {
                $this->assertArrayHasKey($p, Permissoes::CATALOGO, "Permissão inexistente '$p' no item {$item['id']}");
            }
        }
    }
}
