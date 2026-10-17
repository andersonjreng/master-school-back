<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A baixa automática via retorno Sicoob grava forma_pagamento = 'boleto'
 * (RetornoSicoobService -> FinanceiroHelperService::aplicarBaixaParcela()),
 * mas o schema do boleto só tinha incluído esse valor no ENUM de
 * baixas_pagamento — parcelas.forma_pagamento ficou sem ele. MySQL em modo
 * estrito (local) recusa o UPDATE e a baixa falha; sem modo estrito
 * (produção/Hostgator) o valor seria truncado silenciosamente pra ''.
 * Achado simulando o ciclo remessa -> retorno antes do teste real com o banco.
 *
 * As duas colunas são reafirmadas aqui porque cada banco de escola
 * (teste/cepelc/criarte) pode estar num estado diferente do schema.
 */
return new class extends Migration
{
    private const SEM_BOLETO = "'dinheiro','pix','transferencia','cartao_debito','cartao_credito'";
    private const COM_BOLETO = self::SEM_BOLETO . ",'boleto'";

    public function up(): void
    {
        DB::statement('ALTER TABLE parcelas MODIFY forma_pagamento ENUM(' . self::COM_BOLETO . ') NULL');
        DB::statement('ALTER TABLE baixas_pagamento MODIFY forma_pagamento ENUM(' . self::COM_BOLETO . ') NOT NULL');
    }

    public function down(): void
    {
        // Só parcelas volta — baixas_pagamento já tinha 'boleto' antes desta migration.
        DB::statement('ALTER TABLE parcelas MODIFY forma_pagamento ENUM(' . self::SEM_BOLETO . ') NULL');
    }
};
