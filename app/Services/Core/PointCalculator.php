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

        $patrocinio = $userPoints->whereIn('type', ['P', 'S'])->sum('point');
        $residual   = $userPoints->where('type', PaymentOrderPoint::RESIDUAL)->sum('point');
        $compra = (object) ['total_puntos' => $userPoints->where('type', PaymentOrderPoint::COMPRA)->sum('point')];
        $pointGroup     = $userPoints->where('type', PaymentOrderPoint::GRUPAL)->sum('point');
        $personal       = $userPoints->where('type', PaymentOrderPoint::COMPRA)->sum('point');
        $infinito       = $userPoints->where('type', PaymentOrderPoint::INFINITO)->sum('point');
        $pointAfiliado  = 0;
        $personalGlobal = 0;

        return (object) [
            'patrocinio'          => $patrocinio,
            'residual'            => $residual,
            'compra'              => $compra,
            'pointGroup'          => $pointGroup,
            'personal'            => $personal,
            'infinito'            => $infinito,
            'pointAfiliado'       => $pointAfiliado,
            'personalGlobal'      => $personalGlobal,
            'puntos_personales'   => $personal,
            'puntos_red'          => $pointGroup,
            'ganancia_patrocinio' => $patrocinio,
            'total_general'       => $personal + $pointGroup + $residual
        ];
    }

    public function pointsTotal($userUuid, $paymentOrderPoints, $paymentProductOrderPoints)
    {
        $pointsObj = $this->points($userUuid, $paymentOrderPoints, $paymentProductOrderPoints);
        return $pointsObj->personal + $pointsObj->pointGroup + $pointsObj->residual;
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
        $ranges       = Range::where("state", true)->orderBy('points', 'asc')->get();
        $rangeCurrent = null;

        foreach ($ranges as $range) {
            if ($range->points <= $totalPoints && $range->childs <= (int) $directos) {
                $rangeCurrent = $range;
            }
        }

        if (!$rangeCurrent) {
            $bronce = Range::where('points', 1000)->where('childs', 1)->where('state', true)->first();
            if ($bronce && $totalPoints >= 1000 && $directos >= 1) {
                $rangeCurrent = $bronce;
            }
        }

        return $rangeCurrent;
    }
}