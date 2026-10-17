<?php

namespace App\Services\Turmas;

use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Calendário escolar: a regra única de "dia letivo".
 *
 * Dia letivo = dentro de um bimestre (Parametrizações → Ano Letivo), de
 * segunda a sexta, sem feriado/recesso; sábados marcados como letivos também
 * contam. Sem datas de bimestre, vale dia útil sem feriado/recesso.
 *
 * Uso nos painéis: monte o mapa uma vez por ano letivo (mapa()) e consulte
 * com classificar()/ehLetivo()/diasLetivos() — sem consultas por dia.
 */
class CalendarioEscolarService
{
    public const TIPOS = ['feriado', 'ponto_facultativo', 'recesso', 'sabado_letivo', 'evento'];
    public const NAO_LETIVOS = ['feriado', 'ponto_facultativo', 'recesso'];
    /** Sigla padrão no calendário impresso (eventos usam as iniciais da descrição). */
    public const SIGLAS = ['feriado' => 'F', 'ponto_facultativo' => 'PF', 'recesso' => 'R', 'sabado_letivo' => 'SL'];
    public const META_DIAS = 200;

    /**
     * @return array{ranges: array<int, array{0: string, 1: string, 2: string}>, nao_letivos: array<string, array{tipo: string, descricao: string}>, sabados: array<string, string>}
     */
    public function mapa(int $ano): array
    {
        $ranges = app(PeriodoLetivoService::class)->unidadesComDatas($ano)
            ->map(fn ($u) => [substr($u->data_inicio, 0, 10), substr($u->data_fim, 0, 10), $u->nome_unidade])
            ->all();

        $naoLetivos = [];
        $sabados = [];
        $marcacoes = DB::table('calendario_escolar')->where('ano_letivo', $ano)
            ->whereIn('tipo', [...self::NAO_LETIVOS, 'sabado_letivo'])->get();
        foreach ($marcacoes as $m) {
            foreach (CarbonPeriod::create($m->data_inicio, $m->data_fim) as $d) {
                $dia = $d->toDateString();
                if ($m->tipo === 'sabado_letivo') {
                    if ($d->isSaturday()) {
                        $sabados[$dia] = $m->descricao;
                    }
                } elseif (!isset($naoLetivos[$dia]) || $m->tipo === 'feriado') {
                    $naoLetivos[$dia] = ['tipo' => $m->tipo, 'descricao' => $m->descricao];
                }
            }
        }

        return ['ranges' => array_values($ranges), 'nao_letivos' => $naoLetivos, 'sabados' => $sabados];
    }

    /** letivo | sabado_letivo | feriado | ponto_facultativo | recesso | fim_de_semana | fora (fora dos bimestres) */
    public function classificar(string $dia, array $mapa): string
    {
        if ($mapa['ranges'] && !$this->dentroDosBimestres($dia, $mapa)) {
            return 'fora';
        }
        if (isset($mapa['nao_letivos'][$dia])) {
            return $mapa['nao_letivos'][$dia]['tipo'];
        }
        $dow = (int) Carbon::parse($dia)->dayOfWeekIso; // 6 = sáb, 7 = dom
        if ($dow === 6) {
            return isset($mapa['sabados'][$dia]) ? 'sabado_letivo' : 'fim_de_semana';
        }
        return $dow === 7 ? 'fim_de_semana' : 'letivo';
    }

    public function ehLetivo(string $dia, array $mapa): bool
    {
        return in_array($this->classificar($dia, $mapa), ['letivo', 'sabado_letivo'], true);
    }

    /** @return string[] dias letivos (Y-m-d) no intervalo, inclusive */
    public function diasLetivos(array $mapa, Carbon $de, Carbon $ate): array
    {
        if ($de->gt($ate)) {
            return [];
        }
        $dias = [];
        foreach (CarbonPeriod::create($de->copy()->startOfDay(), $ate->copy()->startOfDay()) as $d) {
            $dia = $d->toDateString();
            if ($this->ehLetivo($dia, $mapa)) {
                $dias[] = $dia;
            }
        }
        return $dias;
    }

    /** Os $n dias letivos mais recentes antes de $antesDe (sem passar de $limite). */
    public function ultimosLetivos(array $mapa, Carbon $antesDe, int $n, Carbon $limite): array
    {
        $dias = [];
        $d = $antesDe->copy()->startOfDay()->subDay();
        $guarda = 0;
        while (count($dias) < $n && $d->gte($limite) && $guarda++ < 120) {
            if ($this->ehLetivo($d->toDateString(), $mapa)) {
                array_unshift($dias, $d->toDateString());
            }
            $d->subDay();
        }
        return $dias;
    }

    /** Motivo legível de um dia não letivo (para tooltips). */
    public function motivo(string $dia, array $mapa): ?string
    {
        return $mapa['nao_letivos'][$dia]['descricao'] ?? null;
    }

    private function dentroDosBimestres(string $dia, array $mapa): bool
    {
        foreach ($mapa['ranges'] as [$ini, $fim]) {
            if ($dia >= $ini && $dia <= $fim) {
                return true;
            }
        }
        return false;
    }

    // ── Lançamento em dia sem aula ──────────────────────────────────────────

    /**
     * Aviso quando a data não é dia letivo para a turma (feriado, recesso,
     * fim de semana, fora dos bimestres). null = dia letivo, pode lançar.
     * Chamada/registro nesse dia só com confirmação explícita: o calendário
     * pode estar desatualizado ou ter havido reposição.
     *
     * @return array{codigo: string, tipo: string, motivo: ?string, error: string, message: string}|null
     */
    public function avisoDiaNaoLetivo(int $turmaId, string $data): ?array
    {
        $ano = (int) (DB::table('turmas')->where('id', $turmaId)->value('ano_letivo') ?: substr($data, 0, 4));
        $mapa = $this->mapa($ano);
        $tipo = $this->classificar($data, $mapa);
        if (in_array($tipo, ['letivo', 'sabado_letivo'], true)) {
            return null;
        }
        $motivo = $this->motivo($data, $mapa);
        $dia = Carbon::parse($data)->format('d/m/Y');
        $texto = match ($tipo) {
            'feriado'       => "$dia é feriado no calendário escolar" . ($motivo ? " ($motivo)" : '') . '.',
            'recesso'       => "$dia está num recesso do calendário escolar" . ($motivo ? " ($motivo)" : '') . '.',
            'ponto_facultativo' => "$dia é ponto facultativo no calendário escolar" . ($motivo ? " ($motivo)" : '') . '.',
            'fim_de_semana' => "$dia é fim de semana e não está marcado como sábado letivo.",
            default         => "$dia está fora do período letivo (fora das datas dos bimestres).",
        };

        return [
            'codigo'  => 'dia_nao_letivo',
            'tipo'    => $tipo,
            'motivo'  => $motivo,
            'error'   => 'Dia sem aula no calendário escolar.',
            'message' => "$texto Lançamentos nesse dia entram no cálculo de frequência como se tivesse havido aula. Confirme só se houve aula (ex.: reposição).",
        ];
    }

    // ── Visão completa do ano (tela Calendário Escolar) ─────────────────────

    public function resumo(int $ano): array
    {
        $mapa = $this->mapa($ano);
        $dias = [];
        $total = 0;
        foreach (CarbonPeriod::create(Carbon::create($ano, 1, 1), Carbon::create($ano, 12, 31)) as $d) {
            $dia = $d->toDateString();
            $status = $this->classificar($dia, $mapa);
            $dias[$dia] = $status;
            if (in_array($status, ['letivo', 'sabado_letivo'], true)) {
                $total++;
            }
        }

        $bimestres = array_map(function ($r) use ($mapa) {
            $letivos = count($this->diasLetivos($mapa, Carbon::parse($r[0]), Carbon::parse($r[1])));
            return ['nome' => $r[2], 'inicio' => $r[0], 'fim' => $r[1], 'dias_letivos' => $letivos];
        }, $mapa['ranges']);

        $marcacoes = DB::table('calendario_escolar')->where('ano_letivo', $ano)
            ->orderBy('data_inicio')->orderBy('id')
            ->get(['id', 'data_inicio', 'data_fim', 'tipo', 'descricao', 'sigla', 'origem'])
            ->map(fn ($m) => [
                'id' => (int) $m->id, 'data_inicio' => substr($m->data_inicio, 0, 10), 'data_fim' => substr($m->data_fim, 0, 10),
                'tipo' => $m->tipo, 'descricao' => $m->descricao, 'origem' => $m->origem,
                'sigla' => $m->sigla ?: (self::SIGLAS[$m->tipo] ?? $this->iniciais($m->descricao)),
                'sigla_personalizada' => (bool) $m->sigla,
            ])->all();

        // Etapas atendidas no ano (subtítulo do calendário impresso).
        $sistemas = DB::table('turmas')->where('ano_letivo', $ano)->pluck('sistema_avaliacao_id')->map(fn ($s) => (int) $s);
        $etapas = array_values(array_filter([
            $sistemas->contains(3) ? 'Educação Infantil' : null,
            $sistemas->contains(fn ($s) => $s !== 3) ? 'Ensino Fundamental' : null,
        ]));

        return [
            'ano'               => $ano,
            'anos'              => DB::table('anos_letivos')->orderBy('ano')->pluck('ano')->map(fn ($a) => (int) $a)->all(),
            'meta_dias'         => self::META_DIAS,
            'periodo_configurado' => (bool) $mapa['ranges'],
            'inicio'            => $mapa['ranges'] ? min(array_column($mapa['ranges'], 0)) : null,
            'fim'               => $mapa['ranges'] ? max(array_column($mapa['ranges'], 1)) : null,
            'total_dias_letivos' => $mapa['ranges'] ? $total : null,
            'bimestres'         => $bimestres,
            'dias'              => $dias,
            'marcacoes'         => $marcacoes,
            'etapas'            => $etapas,
            'escola'            => $this->escola(),
        ];
    }

    /** "Encontro Pedagógico" → "EP" (ignora de/da/do/e). */
    public function iniciais(string $texto): string
    {
        $ignorar = ['de', 'da', 'do', 'das', 'dos', 'e', 'a', 'o'];
        $palavras = array_filter(preg_split('/\s+/u', trim($texto)) ?: [], fn ($p) => $p !== '' && !in_array(mb_strtolower($p), $ignorar, true));
        $sigla = implode('', array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), $palavras));
        return mb_substr($sigla !== '' ? $sigla : 'E', 0, 3);
    }

    /** Cabeçalho da escola para o calendário impresso. */
    private function escola(): array
    {
        $c = app(\App\Services\Documentos\EscolaConfigService::class)->obter();
        $logo = (string) ($c['logo_url'] ?? '');
        if ($logo !== '' && !str_starts_with($logo, 'http')) {
            $logo = url('storage/' . ltrim($logo, '/'));
        }
        return [
            'nome' => $c['nome_escola'] ?? '', 'cnpj' => $c['cnpj'] ?? '', 'endereco' => $c['endereco'] ?? '',
            'autorizacao' => $c['autorizacao_mec'] ?? '', 'logo_url' => $logo,
            'diretor_nome' => $c['diretor_nome'] ?? '', 'diretor_cargo' => $c['diretor_cargo'] ?? '',
        ];
    }

    // ── Feriados nacionais ──────────────────────────────────────────────────

    /** @return array<string, string> data => nome (feriados e pontos facultativos nacionais em que não há aula) */
    public function feriadosNacionais(int $ano): array
    {
        $pascoa = $this->pascoa($ano);
        $f = [
            "$ano-01-01" => 'Confraternização Universal',
            $pascoa->copy()->subDays(48)->toDateString() => 'Carnaval',
            $pascoa->copy()->subDays(47)->toDateString() => 'Carnaval',
            $pascoa->copy()->subDays(2)->toDateString() => 'Sexta-feira Santa',
            "$ano-04-21" => 'Tiradentes',
            "$ano-05-01" => 'Dia do Trabalho',
            $pascoa->copy()->addDays(60)->toDateString() => 'Corpus Christi',
            "$ano-09-07" => 'Independência do Brasil',
            "$ano-10-12" => 'Nossa Senhora Aparecida',
            "$ano-11-02" => 'Finados',
            "$ano-11-15" => 'Proclamação da República',
            "$ano-11-20" => 'Dia Nacional de Zumbi e da Consciência Negra',
            "$ano-12-25" => 'Natal',
        ];
        ksort($f);
        return $f;
    }

    /** Domingo de Páscoa (algoritmo de Meeus/Butcher — sem depender da extensão calendar). */
    public function pascoa(int $ano): Carbon
    {
        $a = $ano % 19;
        $b = intdiv($ano, 100);
        $c = $ano % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $mes = intdiv($h + $l - 7 * $m + 114, 31);
        $dia = (($h + $l - 7 * $m + 114) % 31) + 1;
        return Carbon::create($ano, $mes, $dia)->startOfDay();
    }

    /** Cria os feriados nacionais do ano que ainda não estão marcados. Devolve quantos criou. */
    public function importarNacionais(int $ano): int
    {
        $existentes = DB::table('calendario_escolar')->where('ano_letivo', $ano)
            ->whereIn('tipo', self::NAO_LETIVOS)->get(['data_inicio', 'data_fim']);
        $criados = 0;
        foreach ($this->feriadosNacionais($ano) as $data => $nome) {
            $coberto = $existentes->contains(fn ($m) => $data >= substr($m->data_inicio, 0, 10) && $data <= substr($m->data_fim, 0, 10));
            if ($coberto) {
                continue;
            }
            DB::table('calendario_escolar')->insert([
                'ano_letivo' => $ano, 'data_inicio' => $data, 'data_fim' => $data,
                'tipo' => 'feriado', 'descricao' => $nome, 'origem' => 'nacional',
            ]);
            $criados++;
        }
        return $criados;
    }
}
