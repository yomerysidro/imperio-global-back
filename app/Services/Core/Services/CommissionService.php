<?php

namespace App\Services\Core\Services;

use App\Models\PaymentOrderPoint;
use App\Models\User;
use App\Models\PaymentLog;
use App\Models\SponsorshipPoint;
use App\Models\ResidualPoint;
use App\Models\Option;
use App\Models\GuestsTokenUser;

class CommissionService
{
    /**
     * Procesa la distribución de puntos asegurando Bono de Patrocinio para todo Pack.
     */
    public function distribute($paymentOrder, $userCurrent, $packCurrent, $isRecurring = false)
    {
        // 1. Detectar si es la PRIMERA VEZ que compra esta categoría (PRODUCTO o SERVICIO)
        $category = $packCurrent->category ?? 'PRODUCTO';
        $alreadyPurchasedCategory = PaymentLog::where('user_id', $userCurrent->id)
            ->whereIn('state', [2, 6])
            ->whereHas('paymentOrder.pack', function($q) use ($category) {
                $q->where('category', $category);
            })
            ->where('payment_order_id', '!=', $paymentOrder->id)
            ->exists();

        // Si es la primera vez para esta categoría, es PATROCINIO.
        $isFirstTimeForCategory = !$alreadyPurchasedCategory;

        // Buscar patrocinador real en la tabla de relaciones de invitados
        $sponsorUuid = isset($paymentOrder->sponsor_code) ? $paymentOrder->sponsor_code : null;

        if (!$sponsorUuid || $sponsorUuid === "COMPANY") {
            $relation = GuestsTokenUser::where('guest_user_code', $userCurrent->uuid)
                ->where('state', true)
                ->first();
            $sponsorUuid = $relation?->sponsor_user_code;
        }

        // 2. Registro de Puntos Personales (Tipo 'B' = Compra)
        PaymentOrderPoint::create([
            'payment_order_id' => $paymentOrder->id,
            'user_code'        => $userCurrent->uuid,
            'sponsor_code'     => $sponsorUuid ?? "COMPANY",
            'point'            => $packCurrent->points,
            'payment'          => true,
            'type'             => PaymentOrderPoint::COMPRA,
            'user_id'          => $userCurrent->id,
            'state'            => true
        ]);

        // 3. Recorrer la genealogía hacia arriba
        if ($sponsorUuid && $sponsorUuid !== "COMPANY") {
            $this->loopUpward($userCurrent->uuid, $paymentOrder, $packCurrent, $isFirstTimeForCategory);
        }
    }

    private function loopUpward($userCode, $paymentOrder, $packCurrent, $isFirstTimeForCategory, $level = 1)
    {
        if ($level > 10) return;

        // Genealogía basada en GuestsTokenUser (Relación fija de patrocinio)
        $relation = GuestsTokenUser::where('guest_user_code', $userCode)
            ->where('state', true)
            ->first();

        if ($relation && $relation->sponsor_user_code) {
            $sponsor = User::where('uuid', $relation->sponsor_user_code)->first();

            if ($sponsor) {
                // A. PUNTOS GRUPALES (Siempre suben para la red única - Puntos de Volumen)
                PaymentOrderPoint::create([
                    'payment_order_id' => $paymentOrder->id,
                    'user_code'        => User::find($paymentOrder->user_id)->uuid ?? $userCode, 
                    'sponsor_code'     => $sponsor->uuid,
                    'point'            => $packCurrent->points,
                    'payment'          => true,
                    'type'             => PaymentOrderPoint::GRUPAL,
                    'user_id'          => $sponsor->id,
                    'state'            => true
                ]);

                // B. BONOS DE RED (Patrocinio hasta Nivel 5 o Residual hasta Nivel 7)
                if ($isFirstTimeForCategory) {
                    $this->processSponsorshipBonus($sponsor, $paymentOrder, $packCurrent, $level);
                } else {
                    $this->processResidualBonus($sponsor, $paymentOrder, $packCurrent, $level);
                }

                $this->loopUpward($sponsor->uuid, $paymentOrder, $packCurrent, $isFirstTimeForCategory, $level + 1);
            }
        }
    }

    private function sponsorQualifies($sponsor, $category)
    {
        return PaymentLog::where('user_id', $sponsor->id)
            ->whereIn('state', [PaymentLog::PAGADO, 2, 6])
            ->whereHas('paymentOrder.pack', function ($q) use ($category) {
                $q->where('category', $category);
            })->exists();
    }

    private function processSponsorshipBonus($sponsor, $paymentOrder, $packCurrent, $level)
    {
        if ($level > 5) return;
        
        $config = SponsorshipPoint::where("pack_id", $paymentOrder->pack_id)->first();
        if ($config) {
            $percent = $config->{"level{$level}"} ?? 0;
            // Cálculo basado estrictamente en PUNTOS del paquete
            $points = floatval($packCurrent->points) * floatval($percent) / 100;
            
            $type = ($packCurrent->category == 'SERVICIO') ? PaymentOrderPoint::PATROCINIO_SERVICIO : PaymentOrderPoint::PATROCINIO;
            
            $this->createRecord($sponsor, $paymentOrder, $points, $type, $packCurrent->category);
        }
    }

    private function processResidualBonus($sponsor, $paymentOrder, $packCurrent, $level)
    {
        if ($level > 7) return;
        
        $config = ResidualPoint::first();
        if ($config) {
            $percent = $config->{"level{$level}"} ?? 0;
            // Para Residual también usamos los puntos del paquete/compra como base
            $points = floatval($packCurrent->points) * floatval($percent) / 100;

            $type = ($packCurrent->category == 'SERVICIO') ? PaymentOrderPoint::RESIDUAL_SERVICIO : PaymentOrderPoint::RESIDUAL;

            $this->createRecord($sponsor, $paymentOrder, $points, $type, $packCurrent->category);
        }
    }

    private function createRecord($sponsor, $paymentOrder, $points, $type, $category)
    {
        if ($points <= 0) return;

        $qualified = $this->sponsorQualifies($sponsor, $category);

        PaymentOrderPoint::create([
            'payment_order_id' => $paymentOrder->id,
            'user_code'        => User::find($paymentOrder->user_id)->uuid ?? "N/A",
            'sponsor_code'     => $sponsor->uuid,
            'point'            => $points,
            'payment'          => $qualified,
            'type'             => $type,
            'user_id'          => $sponsor->id,
            'state'            => true 
        ]);
    }
}

