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
        Schema::create('max_mensagens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('max_conversa_id');
            $table->string('role', 20);
            $table->text('content');
            $table->integer('tokens_entrada')->nullable();
            $table->integer('tokens_saida')->nullable();
            $table->timestamps();

            $table->foreign('max_conversa_id')->references('id')->on('max_conversas')->cascadeOnDelete();
            $table->index(['max_conversa_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('max_mensagens');
    }
};
