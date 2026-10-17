<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O registro de detalhe do CNAB400 Sicoob (posições 23-31) leva a CONTA
 * CORRENTE do beneficiário + o dígito dela — que não é o mesmo número nem o
 * mesmo dígito do código do cedente. Confirmado no retorno real da escola
 * (3118_01642740_20260901_C400_00.RET): cedente 164274-0, conta 421945-7.
 * Até aqui o gerador de remessa reaproveitava o DV do cedente como DV da
 * conta por falta de onde guardar o certo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE convenios_cobranca_bancaria ADD COLUMN conta_dv VARCHAR(1) NULL AFTER conta');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE convenios_cobranca_bancaria DROP COLUMN conta_dv');
    }
};
