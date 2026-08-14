<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use App\Models\PaymentProductOrder;
use App\Models\PaymentLog;
use App\Models\Range;
use App\Models\RangeUser;
use Carbon\Carbon;

class PointCalculator
{
    public function points($userUuid, $paymentOrderPoints, $paymentProductOrderPoints)
    {
        $userPoints = $paymentOrderPoints->filter(function ($point) use ($userUuid) {
            return strtoupper($point->user_code) === strtoupper($userUuid);
        })->values();

        $commissions = $userPoints->where('state', true)
            ->whereIn('type', ['P', 'PS', 'S', 'R', 'RS', 'I'])
            ->groupBy(function ($point) {
                $type = $point->type === 'S' ? 'PS' : $point->type;
                return implode('|', [
                    $point->payment_order_id ?: 'ROW-'.$point->id,
                    strtoupper((string) $point->user_code),
                    strtoupper((string) ($point->source_user_code ?? '')),
                    $type,
                    (int) ($point->level ?? 0),
                    (int) ($point->manual_reactivation_id ?? 0),
                ]);
            })->map(fn ($rows) => $rows->first())->values();

        $patrocinioProducto = $commissions->where('type', PaymentOrderPoint::PATROCINIO)->sum('point');
        $patrocinioServicio = $commissions
            ->whereIn('type', [PaymentOrderPoint::PATROCINIO_SERVICIO, 'S'])
            ->sum('point');
        $patrocinio = $patrocinioProducto + $patrocinioServicio;
        $residualProducto = $commissions->where('type', PaymentOrderPoint::RESIDUAL)->sum('point');
        $residualServicio = $commissions->where('type', PaymentOrderPoint::RESIDUAL_SERVICIO)->sum('point');
        $residual = $residualProducto + $residualServicio;
        $compra = (object) ['total_puntos' => $userPoints->where('type', PaymentOrderPoint::COMPRA)->sum('point')];
        $pointGroup     = $userPoints->where('type', PaymentOrderPoint::GRUPAL)->sum('point');
        $personal       = $userPoints->where('type', PaymentOrderPoint::COMPRA)->sum('point');
        $infinito       = $commissions->where('type', PaymentOrderPoint::INFINITO)->sum('point');
        $pointAfiliado  = 0;
        $personalGlobal = 0;

        return (object) [
            'patrocinio'          => $patrocinio,
            'patrocinioProducto'  => $patrocinioProducto,
            'patrocinioServicio'  => $patrocinioServicio,
            'residual'            => $residual,
            'residualProducto'    => $residualProducto,
            'residualServicio'    => $residualServicio,
            'compra'              => $compra,
            'pointGroup'          => $pointGroup,
            'personal'            => $personal,
            'infinito'            => $infinito,
            'bono'                => $patrocinio,
            'bono_total'          => $patrocinio + $residual + $infinito,
            'bonos_totales'       => $patrocinio + $residual + $infinito,
            'pointAfiliado'       => $pointAfiliado,
            'personalGlobal'      => $personalGlobal,
            'puntos_personales'   => $personal,
            'puntos_red'          => $pointGroup,
            'ganancia_patrocinio' => $patrocinio,
            'total_general'       => $personal + $pointGroup,
            'total_comisiones'    => $patrocinio + $residual + $infinito,
            'ganancia_total'      => $patrocinio + $residual + $infinito
        ];
    }

    public function pointsTotal($userUuid, $paymentOrderPoints, $paymentProductOrderPoints)
    {
        $pointsObj = $this->points($userUuid, $paymentOrderPoints, $paymentProductOrderPoints);
        return $pointsObj->personal + $pointsObj->pointGroup;
    }

    public function getUserPaymentStatus($userId)
    {
        $now           = Carbon::now();
        $currentMonth  = $now->month;
        $currentYear   = $now->year;
        $mesAnterior   = $now->copy()->subMonth();
        $isGracePeriod = $now->day <= 2;

        $servicePayment = PaymentLog::with(['paymentOrder.pack'])
            ->where("user_id", $userId)->whereIn('state', [2, 6])->orderBy('created_at', 'desc')->first();
        $productPayment = PaymentProductOrder::with(['pack'])
            ->where("user_id", $userId)->whereIn('state', [2, 3, 6])->orderBy('created_at', 'desc')->first();

        $ultimoPago = collect([$servicePayment, $productPayment])->filter()->sortByDesc('created_at')->first();

        $isActive   = false;
        $mesFiltro  = $currentMonth;
        $anioFiltro = $currentYear;

        if ($ultimoPago) {
            $fechaPago = Carbon::parse($ultimoPago->created_at);
            if ($fechaPago->month == $currentMonth && $fechaPago->year == $currentYear) {
                $isActive = true;
            } elseif ($fechaPago->month == $mesAnterior->month && $fechaPago->year == $mesAnterior->year) {
                if ($isGracePeriod) {
                    $isActive = true;
                    $mesFiltro = $mesAnterior->month;
                    $anioFiltro = $mesAnterior->year;
                }
            }
        }

        if (!$isActive && $ultimoPago) {
            $ultimoPago->state = 6;
        }

        return [
            'payment'     => $ultimoPago,
            'is_active'   => $isActive,
            'mes_filtro'  => $mesFiltro,
            'anio_filtro' => $anioFiltro
        ];
    }

    public function calculateRange($totalPoints, $directos)
    {
        $ranges       = Range::with('rule')->where("state", true)->orderBy('order')->get();
        $rangeCurrent = null;

        foreach ($ranges as $range) {
            if ($range->rule && $range->rule->required_points <= $totalPoints
                && $range->rule->required_active_lines <= (int) $directos) {
                $rangeCurrent = $range;
            }
        }

        return $rangeCurrent;
    }
}
