<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('max_conversas', function (Blueprint $table) {
            $table->id();
            // integer (nao unsignedBigInteger/foreignId): usuarios.id no schema legado e int(11) signed.
            $table->integer('usuario_id');
            $table->string('titulo', 150)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('usuario_id')->references('id')->on('usuarios');
            $table->index(['usuario_id', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('max_conversas');
    }
};
