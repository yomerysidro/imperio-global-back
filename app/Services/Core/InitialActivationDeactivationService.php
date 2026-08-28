<?php

namespace App\Services\Core;

use App\Models\ManualReactivation;
use App\Models\PaymentLog;
use App\Models\PaymentOrderPoint;
use App\Models\ReactivationRule;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class InitialActivationDeactivationService
{
    public function deactivate(User $user, string $category): array
    {
        $paymentLog = $this->activeInitialPaymentLogQuery($user, $category)
            ->lockForUpdate()->latest('created_at')->first();

        if (! $paymentLog) {
            throw new \DomainException('No existe una activacion inicial activa en esta categoria que pueda desactivarse.');
        }

        $paymentOrderId = $paymentLog->payment_order_id;
        $affectedPoints = PaymentOrderPoint::where('payment_order_id', $paymentOrderId)
            ->whereNull('manual_reactivation_id')->get(['id', 'point']);

        if ($affectedPoints->isEmpty()) {
            throw new \DomainException('La activacion inicial no tiene puntos trazables. No se realizo ningun cambio.');
        }

        $removedPoints = (float) $affectedPoints->sum('point');
        PaymentOrderPoint::whereIn('id', $affectedPoints->pluck('id'))->update([
            'state' => false,
            'point' => 0,
            'type' => PaymentOrderPoint::RESET,
        ]);
        $paymentLog->update(['state' => PaymentLog::RESET]);
        ActivationService::clearCache();

        return [
            'payment_order_id' => $paymentOrderId,
            'payment_log_id' => $paymentLog->id,
            'affected_point_rows' => $affectedPoints->count(),
            'removed_points_and_commissions' => $removedPoints,
        ];
    }

    public function canDeactivate(User $user, string $category): bool
    {
        return $this->activeInitialPaymentLogQuery($user, $category)->exists();
    }

    public function wasManuallyDeactivated(User $user, string $category): bool
    {
        return $this->initialPaymentLogQuery($user, $category)
            ->where('payment_logs.state', PaymentLog::RESET)
            ->whereHas('paymentOrder.points', fn (Builder $query) => $query
                ->whereNull('manual_reactivation_id')
                ->where('type', PaymentOrderPoint::RESET)
                ->where('state', false)
                ->where('point', 0))
            ->exists();
    }

    private function activeInitialPaymentLogQuery(User $user, string $category): Builder
    {
        return $this->initialPaymentLogQuery($user, $category)
            ->where('payment_logs.state', PaymentLog::PAGADO)
            ->whereBetween('payment_logs.created_at', [now()->startOfMonth(), now()->endOfMonth()]);
    }

    private function initialPaymentLogQuery(User $user, string $category): Builder
    {
        $storedCategory = strtolower(trim($category)) === ReactivationRule::SERVICE ? 'servicio' : 'producto';
        $reactivationLogIds = ManualReactivation::where('user_id', $user->id)
            ->get(['payment_log_ids'])->pluck('payment_log_ids')->filter()->flatten()
            ->filter()->unique()->values();

        return PaymentLog::where('user_id', $user->id)
            ->when($reactivationLogIds->isNotEmpty(), fn (Builder $query) => $query->whereNotIn('payment_logs.id', $reactivationLogIds))
            ->whereHas('paymentOrder.pack', fn (Builder $query) => $query
                ->whereRaw('LOWER(TRIM(category)) = ?', [$storedCategory]));
    }
}
