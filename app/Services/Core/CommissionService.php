<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use App\Models\User;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\SponsorshipPoint;
use App\Models\ResidualPoint;
use Illuminate\Support\Facades\Cache;

class CommissionService
{
    private $networkTreeService;

    public function __construct()
    {
        $this->networkTreeService = new NetworkTreeService();
    }

    public function confirmPoint($paymentOrder, $userCurrent, $packCurrent, $reactiveAdmin = false)
    {
        if (!$reactiveAdmin) {
            $existingPersonal = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->first();

            if (!$existingPersonal) {
                PaymentOrderPoint::create([
                    'payment_order_id' => $paymentOrder->id,
                    'user_code'        => $userCurrent->uuid,
                    'sponsor_code'     => $paymentOrder->sponsor_code,
                    'point'            => $packCurrent->points,
                    'payment'          => 1,
                    'type'             => PaymentOrderPoint::COMPRA,
                    'user_id'          => $userCurrent->id,
                    'state'            => true,
                ]);
            }
        }

        $existingPackSameCategory = PaymentOrderPoint::where('user_code', $userCurrent->uuid)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->where('state', true)
            ->whereHas('paymentOrder.pack', function ($q) use ($packCurrent) {
                $q->where('category', $packCurrent->category);
            })
            ->where('payment_order_id', '!=', $paymentOrder->id)
            ->exists();

        $debeGenerarBono      = !$existingPackSameCategory;
        $currentSponsorCode   = $paymentOrder->sponsor_code;
        $level                = 1;
        $puntosBaseNuevoSocio = $packCurrent->points;

        $tipoPatrocinioParaEstePack = (strtoupper($packCurrent->category ?? '') === 'PRODUCTO')
            ? PaymentOrderPoint::PATROCINIO
            : PaymentOrderPoint::PATROCINIO_SERVICIO;

        $tipoFinal = substr($tipoPatrocinioParaEstePack, 0, 1);

        while (!empty($currentSponsorCode) && $level <= 15) {
            $sponsorUser = User::where('uuid', $currentSponsorCode)->first();
            if (!$sponsorUser) break;

            $sponsorPack = null;

            $sponsorLog = PaymentLog::where('user_id', $sponsorUser->id)
                ->whereIn('state', [PaymentLog::PAGADO, 2])
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($sponsorLog && $sponsorLog->payment_order_id) {
                $sponsorOrder = PaymentOrder::with('pack')->find($sponsorLog->payment_order_id);
                if ($sponsorOrder && $sponsorOrder->pack) {
                    $sponsorPack = $sponsorOrder;
                }
            }

            if (!$sponsorPack) {
                $sponsorOwnPoint = PaymentOrderPoint::where('user_code', $currentSponsorCode)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->where('state', true)
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->with('paymentOrder.pack')
                    ->first();

                if ($sponsorOwnPoint && $sponsorOwnPoint->paymentOrder && $sponsorOwnPoint->paymentOrder->pack) {
                    $sponsorPack = $sponsorOwnPoint->paymentOrder;
                }
            }

            if (!$sponsorPack) {
                $anyOrder = PaymentOrder::where('sponsor_code', $currentSponsorCode)
                    ->whereHas('payment_log', function ($q) {
                        $q->whereIn('state', [2, PaymentLog::PAGADO])
                            ->whereMonth('created_at', now()->month)
                            ->whereYear('created_at', now()->year);
                    })
                    ->with('pack')
                    ->first();

                if ($anyOrder && $anyOrder->pack) {
                    $sponsorPack = $anyOrder;
                }
            }

            $relation = PaymentOrderPoint::where('user_code', $currentSponsorCode)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->where('state', true)
                ->orderBy('created_at', 'asc')
                ->first();
            $superiorSponsorCode = $relation ? $relation->sponsor_code : '';

            $existingGrupal = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                ->where('user_code', $currentSponsorCode)
                ->where('type', PaymentOrderPoint::GRUPAL)
                ->first();

            if (!$existingGrupal) {
                PaymentOrderPoint::create([
                    'payment_order_id' => $paymentOrder->id,
                    'user_code'        => $currentSponsorCode,
                    'sponsor_code'     => $superiorSponsorCode,
                    'point'            => $puntosBaseNuevoSocio,
                    'payment'          => 0,
                    'type'             => PaymentOrderPoint::GRUPAL,
                    'user_id'          => $userCurrent->id,
                    'state'            => true,
                ]);
            }

            if ($debeGenerarBono && $level <= 5 && $sponsorPack) {
                $sponsorshipConfig = SponsorshipPoint::where('pack_id', $sponsorPack->pack_id)->first();

                if ($sponsorshipConfig) {
                    $field   = 'level' . $level;
                    $percent = floatval(str_replace(',', '.', $sponsorshipConfig->$field ?? 0));

                    if ($percent > 0) {
                        $montoDinero = ($puntosBaseNuevoSocio * $percent) / 100;

                        if ($montoDinero > 0) {
                            $existingBonus = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                                ->where('user_code', $currentSponsorCode)
                                ->where('type', $tipoFinal)
                                ->first();

                            if (!$existingBonus) {
                                PaymentOrderPoint::create([
                                    'payment_order_id' => $paymentOrder->id,
                                    'user_code'        => $currentSponsorCode,
                                    'sponsor_code'     => $superiorSponsorCode,
                                    'point'            => $montoDinero,
                                    'payment'          => 0,
                                    'type'             => $tipoFinal,
                                    'user_id'          => $userCurrent->id,
                                    'state'            => true,
                                ]);
                            }
                        }
                    }
                }
            }

            $currentSponsorCode = $superiorSponsorCode;
            $level++;
        }

        Cache::forget('existing_user_uuids');
    }

    public function confirmPointAfiliado($userCurrent, $points)
{
    $paymentLog = PaymentLog::where("user_id", $userCurrent->id)
        ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])->orderBy('created_at', 'desc')->first();
        
    if ($paymentLog != null) {
        $paymentLogsCount = PaymentLog::where("user_id", $userCurrent->id)
            ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])->count();

        if ($paymentLogsCount > 1) {
            // 🔥 OBTENER EL ÁRBOL COMPLETO DEL USUARIO (línea ascendente)
            $_paymentOrderPoints = $this->networkTreeService->loopTree([], $userCurrent->uuid);
            $_userCurrent        = User::with(['paymentActive', 'range'])->where('uuid', $userCurrent->uuid)->first();
            $countLevel          = 0;

            // 🔥 RECORRER EL ÁRBOL Y ASIGNAR RESIDUALES (MÁXIMO 7 NIVELES)
            foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                $_paymentOrderPoint = (object) $_paymentOrderPoint;
                $countLevel++;
                
                // Límite máximo: 7 niveles (según tu tabla)
                if ($countLevel > 7) break;

                $point = 0;
                // Porcentajes exactos según tu tabla:
                // Nivel 1: 14%, Nivel 2: 10%, Nivel 3: 18%, Nivel 4: 8%, Nivel 5: 6%, Nivel 6: 0.5%, Nivel 7: 0.5%
                if ($countLevel == 1) $point = $points * 14 / 100;
                if ($countLevel == 2) $point = $points * 10 / 100;
                if ($countLevel == 3) $point = $points * 18 / 100;
                if ($countLevel == 4) $point = $points * 8 / 100;
                if ($countLevel == 5) $point = $points * 6 / 100;
                if ($countLevel == 6) $point = $points * 0.5 / 100;
                if ($countLevel == 7) $point = $points * 0.5 / 100;

                // 🔥 Si el punto es mayor a 0, se crea el registro
                if ($point > 0) {
                    PaymentOrderPoint::create([
                        'payment_order_id' => $paymentLog->payment_order_id,
                        'user_code'        => $_paymentOrderPoint->user_code,
                        'sponsor_code'     => $_paymentOrderPoint->sponsor_code,
                        'point'            => $point,
                        'payment'          => 0, // No es pago directo
                        'type'             => PaymentOrderPoint::RESIDUAL, // Tipo R (Residual)
                        'user_id'          => $userCurrent->id,
                        'state'            => 1 // Activo
                    ]);
                }
            }
        }
    }
}
}