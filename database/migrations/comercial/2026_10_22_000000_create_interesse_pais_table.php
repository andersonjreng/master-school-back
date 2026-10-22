<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Banco comercial (fora do multi-tenant). Rodar com:
 *   php artisan migrate --database=comercial --path=database/migrations/comercial
 */
return new class extends Migration
{
    protected $connection = 'comercial';

    public function up(): void
    {
        Schema::connection('comercial')->create('interesse_pais', function (Blueprint $table) {
            $table->id();

            // Quem pediu
            $table->string('nome', 120);
            $table->string('whatsapp', 15)->index();     // só dígitos: 22999998888
            $table->string('cidade', 80);

            // Qual escola (texto livre; o slug agrupa variações de acento/maiúscula)
            $table->string('escola_slug', 150)->index();
            $table->string('escola_nome', 150);

            // Perfil
            $table->json('etapas')->nullable();
            $table->string('comunicacao_atual', 60);
            $table->json('interesses')->nullable();
            $table->text('comentario')->nullable();

            // Autorização: com_nome | anonimo | nao
            $table->string('autorizacao', 10)->default('com_nome');
            $table->boolean('lider')->default(false);   // associação de pais / topa ir à direção

            // Rastreio da campanha
            $table->string('origem', 80)->nullable();        // utm_source
            $table->string('indicado_por', 80)->nullable();  // ?ref=

            // LGPD
            $table->timestamp('consentimento_em')->nullable();
            $table->string('ip_hash', 64)->nullable();       // IP com hash, nunca o IP puro
            $table->string('user_agent', 255)->nullable();

            // Controle interno
            $table->string('status', 20)->default('novo');   // novo | contatado | descartado
            $table->timestamps();

            // Um pedido por WhatsApp por escola (reenvio atualiza em vez de duplicar)
            $table->unique(['whatsapp', 'escola_slug']);
        });
    }

    public function down(): void
    {
        Schema::connection('comercial')->dropIfExists('interesse_pais');
    }
};
