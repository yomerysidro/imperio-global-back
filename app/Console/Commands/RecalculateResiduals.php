<?php

namespace App\Console\Commands;

use App\Models\CommissionRule;
use App\Models\PaymentOrderPoint;
use App\Models\User;
use App\Services\Core\ActivationService;
use App\Services\Core\NetworkTreeService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecalculateResiduals extends Command
{
    protected $signature = 'residuals:recalculate {year} {month} {--apply : Crea los movimientos faltantes}';

    protected $description = 'Previsualiza o completa residuales faltantes de un periodo sin duplicarlos';

    public function handle(NetworkTreeService $network, ActivationService $activation): int
    {
        $year = (int) $this->argument('year');
        $month = (int) $this->argument('month');
        if ($year < 2000 || $month < 1 || $month > 12) {
            $this->error('Periodo invalido.');
            return self::FAILURE;
        }

        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $rules = CommissionRule::with('minimumRange')
            ->where('bonus_type', CommissionRule::RESIDUAL)
            ->where('state', true)->get()
            ->groupBy(fn (CommissionRule $rule) => strtolower((string) $rule->category));
        $sources = PaymentOrderPoint::with('paymentOrder.pack')
            ->where('type', PaymentOrderPoint::COMPRA)->where('state', true)
            ->whereBetween('created_at', [$from, $to])->orderBy('id')->get();

        $planned = [];
        $blocked = [];
        foreach ($sources as $source) {
            $category = strtolower((string) ($source->paymentOrder?->pack?->category ?? 'product'));
            $category = str_contains($category, 'serv') ? 'service' : 'product';
            $categoryRules = $rules->get($category, collect())->keyBy('level');
            $maxLevel = (int) $categoryRules->keys()->max();
            if ($maxLevel < 1) {
                $blocked[] = [$source->user_code, '-', 0, 'sin reglas '.$category];
                continue;
            }

            $beneficiaryCode = $network->sponsorCode((string) $source->user_code)
                ?: (string) $source->sponsor_code;
            $visited = [];
            for ($level = 1; $level <= $maxLevel && $beneficiaryCode !== ''; $level++) {
                $normalized = strtoupper($beneficiaryCode);
                if (isset($visited[$normalized])) break;
                $visited[$normalized] = true;
                $beneficiary = User::whereRaw('UPPER(uuid) = ?', [$normalized])->first();
                if (!$beneficiary) {
                    $blocked[] = [$source->user_code, $beneficiaryCode, $level, 'beneficiario inexistente'];
                    break;
                }

                $rule = $categoryRules->get($level);
                $isCompany = $beneficiary->is_admin || $normalized === 'DOSB';
                $isActive = $isCompany || $activation->isActiveForCategoryPeriod(
                    $beneficiary, $category, $from, $to, true
                );
                $beneficiaryRangeOrder = (int) ($beneficiary->range?->range?->order ?? 0);
                $requiredRangeOrder = (int) ($rule?->minimumRange?->order ?? 0);
                $meetsRange = $isCompany || $category === 'service' || $level <= 3
                    || $beneficiaryRangeOrder >= $requiredRangeOrder;
                $type = $category === 'service'
                    ? PaymentOrderPoint::RESIDUAL_SERVICIO
                    : PaymentOrderPoint::RESIDUAL;
                $exists = PaymentOrderPoint::where('payment_order_id', $source->payment_order_id)
                    ->whereRaw('UPPER(user_code) = ?', [$normalized])
                    ->where('type', $type)->where('level', $level)->exists();

                if (!$rule) {
                    $blocked[] = [$source->user_code, $beneficiary->uuid, $level, 'regla inexistente'];
                } elseif ($exists) {
                    $blocked[] = [$source->user_code, $beneficiary->uuid, $level, 'ya existe'];
                } elseif (!$isActive) {
                    $blocked[] = [$source->user_code, $beneficiary->uuid, $level, 'beneficiario inactivo'];
                } elseif (!$meetsRange) {
                    $blocked[] = [$source->user_code, $beneficiary->uuid, $level, 'rango minimo'];
                } elseif ((float) $rule->percentage <= 0) {
                    $blocked[] = [$source->user_code, $beneficiary->uuid, $level, 'porcentaje cero'];
                } else {
                    // La columna point de esta BD es entera; se conserva el
                    // mismo redondeo historico (65 x 18% = 12).
                    $amount = (float) round(((float) $source->point * (float) $rule->percentage) / 100);
                    if ($amount > 0) {
                        $planned[] = compact('source', 'beneficiary', 'level', 'type', 'amount');
                    }
                }

                $beneficiaryCode = $network->sponsorCode((string) $beneficiary->uuid) ?: '';
            }
        }

        $this->table(
            ['Orden', 'Origen', 'Beneficiario', 'Nivel', 'Tipo', 'Importe'],
            collect($planned)->map(fn ($row) => [
                $row['source']->payment_order_id,
                $row['source']->user_code,
                $row['beneficiary']->uuid,
                $row['level'],
                $row['type'],
                number_format($row['amount'], 2, '.', ''),
            ])->all()
        );
        $total = (float) collect($planned)->sum('amount');
        $this->info('Fuentes revisadas: '.$sources->count());
        $this->info('Movimientos faltantes: '.count($planned));
        $this->info('Importe faltante: S/ '.number_format($total, 2));

        if (!$this->option('apply')) {
            $this->comment('Vista previa: no se modifico la base de datos.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($planned) {
            foreach ($planned as $row) {
                $movement = PaymentOrderPoint::firstOrNew([
                    'payment_order_id' => $row['source']->payment_order_id,
                    'user_code' => $row['beneficiary']->uuid,
                    'type' => $row['type'],
                    'level' => $row['level'],
                ]);
                if ($movement->exists) continue;
                $movement->timestamps = false;
                $movement->forceFill([
                    'manual_reactivation_id' => $row['source']->manual_reactivation_id,
                    'sponsor_code' => app(NetworkTreeService::class)
                        ->sponsorCode((string) $row['beneficiary']->uuid) ?? '',
                    'source_user_code' => $row['source']->user_code,
                    'point' => $row['amount'],
                    'payment' => false,
                    'user_id' => $row['beneficiary']->id,
                    'state' => true,
                    'created_at' => $row['source']->created_at,
                    'updated_at' => $row['source']->created_at,
                ])->save();
            }
        });
        $this->info('Recalculo aplicado correctamente.');
        return self::SUCCESS;
    }
}
