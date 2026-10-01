<?php

namespace App\Console\Commands;

use App\Models\PaymentOrderPoint;
use App\Models\User;
use App\Services\Core\ActivationService;
use App\Services\Core\NetworkTreeService;
use App\Services\Core\ResidualCommissionPolicy;
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
        $policy = new ResidualCommissionPolicy();
        $sources = PaymentOrderPoint::with('paymentOrder.pack')
            ->where('type', PaymentOrderPoint::COMPRA)->where('state', true)
            ->whereBetween('created_at', [$from, $to])->orderBy('id')->get();

        $planned = [];
        $blocked = [];
        foreach ($sources as $source) {
            $category = strtolower((string) ($source->paymentOrder?->pack?->category ?? 'product'));
            $category = str_contains($category, 'serv') ? 'service' : 'product';
            $beneficiaryCode = $network->sponsorCode((string) $source->user_code)
                ?: (string) $source->sponsor_code;
            $visited = [];
            for ($level = 1; $beneficiaryCode !== ''; $level++) {
                $normalized = strtoupper($beneficiaryCode);
                if (isset($visited[$normalized])) break;
                $visited[$normalized] = true;
                $beneficiary = User::whereRaw('UPPER(uuid) = ?', [$normalized])->first();
                if (!$beneficiary) {
                    $blocked[] = [$source->user_code, $beneficiaryCode, $level, 'beneficiario inexistente'];
                    break;
                }

                $isCompany = $beneficiary->is_admin || $normalized === 'DOSB';
                $isActive = $isCompany || $activation->isActiveForCategoryPeriod(
                    $beneficiary, $category, $from, $to, true
                );
                $beneficiaryRangeOrder = (int) ($beneficiary->range?->range?->order ?? 0);
                [$percentage, $kind] = $policy->rate($category, $level,
                    $beneficiaryRangeOrder, $activation->hasRankActivity($beneficiary), $isCompany);
                $type = $kind === 'infinity' ? PaymentOrderPoint::INFINITO
                    : ($category === 'service' ? PaymentOrderPoint::RESIDUAL_SERVICIO
                        : PaymentOrderPoint::RESIDUAL);
                $exists = PaymentOrderPoint::where('payment_order_id', $source->payment_order_id)
                    ->whereRaw('UPPER(user_code) = ?', [$normalized])
                    ->whereIn('type', [PaymentOrderPoint::RESIDUAL,
                        PaymentOrderPoint::RESIDUAL_SERVICIO, PaymentOrderPoint::INFINITO])
                    ->where('level', $level)->exists();

                if ($exists) {
                    $blocked[] = [$source->user_code, $beneficiary->uuid, $level, 'ya existe'];
                } elseif (!$isActive) {
                    $blocked[] = [$source->user_code, $beneficiary->uuid, $level, 'beneficiario inactivo'];
                } elseif ($percentage <= 0) {
                    $blocked[] = [$source->user_code, $beneficiary->uuid, $level, 'porcentaje cero'];
                } else {
                    $amount = (float) round(((float) $source->point * $percentage) / 100, 2);
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
