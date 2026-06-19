<?php

namespace App\Services\Core\Services;

use App\Models\PaymentOrderPoint;
use App\Models\User;
use App\Models\SponsorshipPoint;

class ServiceTreeManager
{
    public function distributePoints($userCode, $orderId, $points, $packId, $level = 1)
    {
        if ($level > 5) return;

        // Relación de patrocinio
        $relation = PaymentOrderPoint::where('user_code', $userCode)
            ->whereIn('type', [PaymentOrderPoint::PATROCINIO, PaymentOrderPoint::COMPRA, 'P', 'B'])
            ->where('payment', 1)
            ->first();

        if ($relation && $relation->sponsor_code) {
            $sponsor = User::where('uuid', $relation->sponsor_code)->first();

            if ($sponsor) {
                // 1. BONO ECONÓMICO (Calculado con el % del pack)
                $commission = $this->calculateServicePoints($points, $level, $packId);

                if ($commission > 0) {
                    PaymentOrderPoint::create([
                        'payment_order_id' => $orderId,
                        'user_code'        => $userCode,
                        'sponsor_code'     => $sponsor->uuid,
                        'point'            => $commission,
                        'payment'          => true,
                        'type'             => PaymentOrderPoint::PATROCINIO_SERVICIO,
                        'user_id'          => $sponsor->id,
                        'state'            => true
                    ]);
                }

                // 2. PUNTOS GRUPALES (Volumen 100% para Rango)
                PaymentOrderPoint::create([
                    'payment_order_id' => $orderId,
                    'user_code'        => $userCode,
                    'sponsor_code'     => $sponsor->uuid,
                    'point'            => $points,
                    'payment'          => false,
                    'type'             => PaymentOrderPoint::GRUPAL,
                    'user_id'          => $sponsor->id,
                    'state'            => true
                ]);

                $this->distributePoints($sponsor->uuid, $orderId, $points, $packId, $level + 1);
            }
        }
    }

    private function calculateServicePoints($totalPoints, $level, $packId)
    {
        $config = SponsorshipPoint::where('pack_id', $packId)->first();
        if ($config) {
            $percent = $config->{"level{$level}"} ?? 0;
            return floatval($totalPoints) * (floatval($percent) / 100);
        }
        return 0;
    }
}
