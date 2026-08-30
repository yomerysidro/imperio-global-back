<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use App\Models\CollectionRequestPatrocinioUser;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FinancialLedgerService
{
    public function movements(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $userCode = null): Collection
    {
        $query = PaymentOrderPoint::query()
            ->whereIn('type', ['P', 'PS', 'S', 'R', 'RS', 'I'])
            ->where('state', true)
            ->where('point', '>', 0);
        if ($from && $to) $query->whereBetween('created_at', [$from, $to]);
        if ($userCode) $query->whereRaw('UPPER(user_code) = ?', [strtoupper($userCode)]);

        $movements = $query->orderBy('id')->get()
            ->groupBy(fn (PaymentOrderPoint $row) => $this->logicalCommissionKey($row))
            // Si un proceso reintenta exactamente la misma comision, el
            // primer asiento es el original. Nunca elegir por mayor importe.
            ->map(fn (Collection $duplicates) => $duplicates->first())
            ->values();

        if (!$from || !$to || $movements->isEmpty()) return $movements;

        $users = User::whereIn('uuid', $movements->pluck('user_code')->filter()->unique())
            ->get()->keyBy(fn (User $user) => strtoupper((string) $user->uuid));
        $activation = app(ActivationService::class);
        $isCurrentPeriod = $from->format('Y-m') === now()->format('Y-m');

        // Una comision registrada solo es dinero pagable si su beneficiario
        // estuvo activo en el periodo. El paquete historico no habilita cobro.
        return $movements->filter(function (PaymentOrderPoint $movement) use (
            $users, $activation, $from, $to, $isCurrentPeriod
        ) {
            $user = $users->get(strtoupper((string) $movement->user_code));
            if (!$user) return false;
            $category = $movement->type === PaymentOrderPoint::RESIDUAL_SERVICIO ? 'service'
                : ($movement->type === PaymentOrderPoint::RESIDUAL ? 'product' : null);
            return $category
                ? $activation->isActiveForCategoryPeriod($user, $category, $from, $to, !$isCurrentPeriod)
                : $activation->isActiveForPeriod($user, $from, $to, !$isCurrentPeriod);
        })->values();
    }

    public function logicalCommissionKey(PaymentOrderPoint $row): string
    {
        $type = $row->type === 'S' ? 'PS' : $row->type;

        return implode('|', [
            $row->payment_order_id ?: 'ROW-'.$row->id,
            strtoupper((string) $row->user_code),
            strtoupper((string) ($row->source_user_code ?? '')),
            $type,
            (int) ($row->level ?? 0),
            (int) ($row->manual_reactivation_id ?? 0),
        ]);
    }

    public function summary(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $userCode = null): array
    {
        return $this->summarizeMovements($this->movements($from, $to, $userCode));
    }

    public function summarizeMovements(Collection $active): array
    {
        $sum = fn (Collection $items, array $types) => round((float) $items->whereIn('type', $types)->sum('point'), 2);
        $patrocinio = $sum($active, ['P', 'PS', 'S']);
        $residualProducto = $sum($active, ['R']);
        $residualServicio = $sum($active, ['RS']);
        $residual = round($residualProducto + $residualServicio, 2);
        $infinito = $sum($active, ['I']);

        $bonoTotal = round($patrocinio + $residual + $infinito, 2);

        return [
            'patrocinio' => $patrocinio,
            'bono' => $patrocinio,
            'residual' => $residual,
            'residualProducto' => $residualProducto,
            'residualServicio' => $residualServicio,
            'infinito' => $infinito,
            'bono_total' => $bonoTotal,
            'bonos_totales' => $bonoTotal,
            'total_comisiones' => $bonoTotal,
            'ganancia_total' => $bonoTotal,
            'movimientos_validos' => $active->count(),
            'movimientos_anulados' => 0,
        ];
    }

    /**
     * Calcula todos los saldos del reporte con una sola lectura del libro y
     * una sola lectura de solicitudes, evitando consultas repetidas por usuario.
     */
    public function payoutSummaries(CarbonInterface $from, CarbonInterface $to, Collection $users): Collection
    {
        $movementsByUser = $this->movements($from, $to)
            ->groupBy(fn (PaymentOrderPoint $row) => strtoupper((string) $row->user_code));
        $requestsByUser = CollectionRequestPatrocinioUser::query()
            ->whereIn('user_id', $users->pluck('id'))
            ->whereDate('period', $from->copy()->startOfMonth()->toDateString())
            ->get()
            ->groupBy('user_id');

        return $users->mapWithKeys(function (User $user) use ($movementsByUser, $requestsByUser) {
            $commissions = $this->summarizeMovements(
                $movementsByUser->get(strtoupper((string) $user->uuid), collect())
            );
            $generated = (float) $commissions['bono_total'];
            $requests = $requestsByUser->get($user->id, collect());
            $pending = (float) $requests->where('state', CollectionRequestPatrocinioUser::PENDING)->sum('amount');
            $paidRequests = $requests->where('state', CollectionRequestPatrocinioUser::PAID);
            $paid = (float) $paidRequests->sum('amount');

            return [$user->id => [
                'generated' => round($generated, 2),
                'pending' => round($pending, 2),
                'paid' => round($paid, 2),
                'available' => round(max(0, $generated - $pending - $paid), 2),
                'last_paid_at' => $paidRequests->max('paid_at'),
                'commissions' => $commissions,
            ]];
        });
    }

    public function payoutSummary(CarbonInterface $from, CarbonInterface $to, User $user): array
    {
        $generated = (float) $this->summary($from, $to, $user->uuid)['bono_total'];
        $requests = CollectionRequestPatrocinioUser::where('user_id', $user->id)
            ->whereDate('period', $from->copy()->startOfMonth()->toDateString());
        $pending = (float) (clone $requests)->where('state', CollectionRequestPatrocinioUser::PENDING)->sum('amount');
        $paid = (float) (clone $requests)->where('state', CollectionRequestPatrocinioUser::PAID)->sum('amount');

        return [
            'generated' => round($generated, 2),
            'pending' => round($pending, 2),
            'paid' => round($paid, 2),
            'available' => round(max(0, $generated - $pending - $paid), 2),
            'last_paid_at' => (clone $requests)->where('state', CollectionRequestPatrocinioUser::PAID)->max('paid_at'),
        ];
    }
}
