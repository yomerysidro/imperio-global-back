<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use App\Models\CollectionRequestPatrocinioUser;
use App\Models\PaymentLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FinancialLedgerService
{
    private const SPONSORSHIP_TYPES = ['P', 'PS', 'S'];

    public function movements(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?string $userCode = null,
        bool $includeClosed = false
    ): Collection {
        return $this->classifyMovements($from, $to, $userCode, $includeClosed)[0];
    }

    private function classifyMovements(
        ?CarbonInterface $from,
        ?CarbonInterface $to,
        ?string $userCode,
        bool $includeClosed
    ): array
    {
        $cutover = Carbon::parse(config('app.sponsorship_category_lock_from'));
        $query = PaymentOrderPoint::query()
            ->whereIn('type', ['P', 'PS', 'S', 'R', 'RS', 'I'])
            ->when(!$includeClosed, fn ($query) => $query->where('state', true))
            ->where('point', '>', 0);
        if ($from && $to) {
            $query->where('created_at', '<=', $to)->where(function ($query) use ($from, $cutover) {
                $query->where('created_at', '>=', $from)
                    ->orWhere(fn ($query) => $query->whereIn('type', self::SPONSORSHIP_TYPES)
                        ->where('created_at', '>=', $cutover));
            });
        }
        if ($userCode) $query->whereRaw('UPPER(user_code) = ?', [strtoupper($userCode)]);

        $movements = $query->orderBy('id')->get()
            ->groupBy(fn (PaymentOrderPoint $row) => $this->logicalCommissionKey($row))
            // Si un proceso reintenta exactamente la misma comision, el
            // primer asiento es el original. Nunca elegir por mayor importe.
            ->map(fn (Collection $duplicates) => $duplicates->first())
            ->values();

        if (!$from || !$to || $movements->isEmpty()) return [$movements, collect()];

        $users = User::whereIn('uuid', $movements->pluck('user_code')->filter()->unique())
            ->get()->keyBy(fn (User $user) => strtoupper((string) $user->uuid));
        $activation = app(ActivationService::class);
        $isCurrentPeriod = $from->format('Y-m') === now()->format('Y-m');
        $pending = collect();
        $eligibility = [];

        // Una comision registrada solo es dinero pagable si su beneficiario
        // estuvo activo en el periodo. El paquete historico no habilita cobro.
        $payable = $movements->filter(function (PaymentOrderPoint $movement) use (
            $users, $activation, $from, $to, $isCurrentPeriod, $cutover, $pending, &$eligibility
        ) {
            $user = $users->get(strtoupper((string) $movement->user_code));
            if (!$user) return false;
            if (in_array($movement->type, self::SPONSORSHIP_TYPES, true)
                && $movement->created_at->gte($cutover)) {
                $category = $movement->type === PaymentOrderPoint::PATROCINIO ? 'product' : 'service';
                $month = $movement->created_at->copy()->startOfMonth();
                $lastMonth = $to->copy()->min(now())->startOfMonth();
                while ($month <= $lastMonth) {
                    $key = implode('|', [$user->id, $category, $month->format('Y-m')]);
                    $eligible = $eligibility[$key] ??= $this->canCollectSponsorship($user, $category, $month);
                    if ($eligible) return $month->isSameMonth($from);
                    $month->addMonth();
                }
                $pending->push($movement);
                return false;
            }
            // La actividad se valida al generar el residual. Una comisión ya
            // ganada no desaparece del historial si el socio vence después.
            if (in_array($movement->type, [
                PaymentOrderPoint::RESIDUAL,
                PaymentOrderPoint::RESIDUAL_SERVICIO,
            ], true) || ($movement->type === PaymentOrderPoint::INFINITO
                && $movement->source_user_code && (int) $movement->level >= 8)) return true;
            $category = $movement->type === PaymentOrderPoint::RESIDUAL_SERVICIO ? 'service'
                : ($movement->type === PaymentOrderPoint::RESIDUAL ? 'product' : null);
            return $category
                ? $activation->isActiveForCategoryPeriod($user, $category, $from, $to, !$isCurrentPeriod)
                : $activation->isActiveForPeriod($user, $from, $to, !$isCurrentPeriod);
        })->values();
        return [$payable, $pending];
    }

    private function canCollectSponsorship(User $user, string $category, CarbonInterface $month): bool
    {
        if ($user->is_admin || app(CorporateRootService::class)->isRootCode($user->uuid)) return true;

        $end = $month->copy()->endOfMonth()->min(now());
        $storedCategory = $category === 'product' ? 'Producto' : 'Servicio';
        $ownsPack = PaymentLog::where('user_id', $user->id)
            ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO, PaymentLog::RESET])
            ->where('created_at', '<=', $end)
            ->whereHas('paymentOrder', fn ($query) => $query->where('amount', '>', 0)
                ->whereHas('pack', fn ($query) => $query->where('category', $storedCategory)))
            ->exists();

        return $ownsPack && app(ActivationService::class)->isActiveForCategoryPeriod(
            $user, $category, $month, $end, !$month->isSameMonth(now())
        );
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

    public function summary(
        ?CarbonInterface $from = null,
        ?CarbonInterface $to = null,
        ?string $userCode = null,
        bool $includeClosed = false
    ): array
    {
        return $this->summarizeMovements($this->movements($from, $to, $userCode, $includeClosed));
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
    public function payoutSummaries(
        CarbonInterface $from,
        CarbonInterface $to,
        Collection $users,
        bool $includeClosed = false
    ): Collection
    {
        $movementsByUser = $this->movements($from, $to, null, $includeClosed)
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
        [$payable, $categoryPending] = $this->classifyMovements($from, $to, $user->uuid, false);
        $generated = (float) $this->summarizeMovements($payable)['bono_total'];
        $requests = CollectionRequestPatrocinioUser::where('user_id', $user->id)
            ->whereDate('period', $from->copy()->startOfMonth()->toDateString());
        $pending = (float) (clone $requests)->where('state', CollectionRequestPatrocinioUser::PENDING)->sum('amount');
        $paid = (float) (clone $requests)->where('state', CollectionRequestPatrocinioUser::PAID)->sum('amount');
        $sponsorship = [];
        foreach (['product' => ['P'], 'service' => ['PS', 'S']] as $category => $types) {
            $blocked = (float) $categoryPending->whereIn('type', $types)->sum('point');
            $collectible = (float) $payable->whereIn('type', $types)->sum('point');
            $residualType = $category === 'product' ? PaymentOrderPoint::RESIDUAL : PaymentOrderPoint::RESIDUAL_SERVICIO;
            $residual = (float) $payable->where('type', $residualType)->sum('point');
            $sponsorship[$category] = [
                'generated' => round($collectible + $blocked, 2),
                'pending' => round($blocked, 2),
                'total_commissions' => round($collectible + $blocked + $residual, 2),
            ];
        }

        return [
            'generated' => round($generated, 2),
            'pending' => round($pending, 2),
            'paid' => round($paid, 2),
            'available' => round(max(0, $generated - $pending - $paid), 2),
            'sponsorship_by_category' => $sponsorship,
            'last_paid_at' => (clone $requests)->where('state', CollectionRequestPatrocinioUser::PAID)->max('paid_at'),
        ];
    }
}
