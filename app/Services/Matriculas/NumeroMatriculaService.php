<?php

namespace App\Services\Matriculas;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Número de matrícula: ano letivo + sequência (2027001, 2027002...). O aluno
 * ganha um número novo a cada ano — no cadastro, ou na rematrícula, que
 * reserva o número do ano seguinte. alunos.matricula é o número do ano em
 * curso; o de cada ano fica em matriculas.numero (histórico).
 *
 * A sequência fica em matricula_numeracao e é travada (FOR UPDATE) dentro da
 * transação de quem usa o número, para dois atendimentos simultâneos não
 * pegarem o mesmo. Números digitados à mão também contam: a próxima sugestão
 * é sempre maior que o maior número já usado no ano.
 */
class NumeroMatriculaService
{
    /** Próximo número do ano, sem reservar (sugestão no formulário). */
    public function sugerir(int $ano): string
    {
        $ultimo = Schema::hasTable('matricula_numeracao')
            ? (int) DB::table('matricula_numeracao')->where('ano', $ano)->value('ultimo') : 0;
        return $this->formatar($ano, max($ultimo, $this->maiorUsado($ano)) + 1);
    }

    /** Reserva e devolve o próximo número do ano. Chamar dentro da transação que grava o número. */
    public function proximo(int $ano): string
    {
        if (!Schema::hasTable('matricula_numeracao')) {
            return $this->sugerir($ano);
        }
        DB::table('matricula_numeracao')->insertOrIgnore(['ano' => $ano, 'ultimo' => 0]);
        $ultimo = (int) DB::table('matricula_numeracao')->where('ano', $ano)->lockForUpdate()->value('ultimo');
        $n = max($ultimo, $this->maiorUsado($ano)) + 1;
        DB::table('matricula_numeracao')->where('ano', $ano)->update(['ultimo' => $n]);
        return $this->formatar($ano, $n);
    }

    /**
     * Número do aluno num ano: o que ele já teve nesse ano, o atual se já for
     * desse ano, ou um novo (que passa a ser o atual). Usado quando o aluno
     * volta a uma turma sem vir da virada (ex.: vinculado de novo).
     */
    public function numeroDoAno(int $alunoId, int $ano): string
    {
        if ($this->porAno()) {
            $doAno = DB::table('matriculas')->where('aluno_id', $alunoId)->where('ano_letivo', $ano)->whereNotNull('numero')
                ->orderByDesc('id')->value('numero');
            if ($doAno) {
                return $doAno;
            }
        }
        $atual = (string) DB::table('alunos')->where('id', $alunoId)->value('matricula');
        if (str_starts_with($atual, (string) $ano)) {
            return $atual;
        }
        $novo = $this->proximo($ano);
        DB::table('alunos')->where('id', $alunoId)->update(['matricula' => $novo]);
        return $novo;
    }

    /** Número já é de outro aluno (atual, de outro ano ou reservado numa rematrícula)? */
    public function emUso(string $numero, ?int $alunoId = null): bool
    {
        $outro = fn ($q) => $alunoId ? $q->where('aluno_id', '!=', $alunoId) : $q;
        return DB::table('alunos')->where('matricula', $numero)->when($alunoId, fn ($q) => $q->where('id', '!=', $alunoId))->exists()
            || (Schema::hasColumn('matriculas', 'numero') && $outro(DB::table('matriculas')->where('numero', $numero))->exists())
            || ($this->temReserva() && $outro(DB::table('rematriculas')->where('numero_matricula', $numero)->where('status', '!=', 'cancelada'))->exists());
    }

    /** Linha de `matriculas` com o número do ano (escola que ainda não rodou a migration: sem a coluna). */
    public function linha(array $linha, ?string $numero): array
    {
        return $this->porAno() ? $linha + ['numero' => $numero] : $linha;
    }

    /**
     * SQL do número de matrícula daquele ano numa consulta que junta
     * matriculas ($m) e alunos ($a): o número do ano, ou o atual quando a
     * matrícula é anterior à numeração anual. Documentos de anos fechados
     * (ata, diário, ficha) usam isto para não mostrar o número do ano novo.
     */
    public function sqlNumeroDoAno(string $m = 'm', string $a = 'a'): string
    {
        return $this->porAno() ? "COALESCE($m.numero, $a.matricula)" : "$a.matricula";
    }

    /** O histórico já guarda o número de cada ano (migration 2026_10_27_100000 rodada)? */
    public function porAno(): bool
    {
        return $this->temColunaNumero ??= Schema::hasColumn('matriculas', 'numero');
    }

    private ?bool $temColunaNumero = null;

    private function maiorUsado(int $ano): int
    {
        $padrao = '^' . $ano . '[0-9]+$';
        $maior = fn ($q, string $col) => (int) $q->where($col, 'like', $ano . '%')->whereRaw("$col REGEXP ?", [$padrao])
            ->max(DB::raw("CAST(SUBSTRING($col, 5) AS UNSIGNED)"));

        $valores = [$maior(DB::table('alunos'), 'matricula')];
        if (Schema::hasColumn('matriculas', 'numero')) {
            $valores[] = $maior(DB::table('matriculas'), 'numero');
        }
        if ($this->temReserva()) {
            $valores[] = $maior(DB::table('rematriculas'), 'numero_matricula');
        }
        return max($valores);
    }

    private function temReserva(): bool
    {
        return Schema::hasTable('rematriculas') && Schema::hasColumn('rematriculas', 'numero_matricula');
    }

    private function formatar(int $ano, int $n): string
    {
        return $ano . str_pad((string) $n, 3, '0', STR_PAD_LEFT);
    }
}
