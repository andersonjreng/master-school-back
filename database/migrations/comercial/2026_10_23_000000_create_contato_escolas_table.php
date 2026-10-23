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
        Schema::connection('comercial')->create('contato_escolas', function (Blueprint $table) {
            $table->id();

            // Quem falou com a gente
            $table->string('nome', 120);
            $table->string('cargo', 60);
            $table->string('whatsapp', 15)->index();     // só dígitos
            $table->string('email', 150)->nullable();

            // A escola
            $table->string('escola', 150);
            $table->string('cidade', 80);
            $table->string('alunos', 30)->nullable();    // faixa: "até 150", "151 a 400"...
            $table->json('interesses')->nullable();
            $table->text('mensagem')->nullable();

            // Rastreio da campanha
            $table->string('origem', 80)->nullable();    // utm_source

            // LGPD
            $table->timestamp('consentimento_em')->nullable();
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();

            // Controle interno
            $table->string('status', 20)->default('novo');   // novo | contatado | demo | fechado | descartado
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('comercial')->dropIfExists('contato_escolas');
    }
};
