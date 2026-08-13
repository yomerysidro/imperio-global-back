<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use App\Models\User;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\SponsorshipPoint;
use App\Models\ResidualPoint;
use Illuminate\Support\Facades\Cache;
use App\Models\SponsorRelation;
use App\Models\PaymentProductOrder;

class CommissionService
{
    private const MAX_SPONSORSHIP_LEVEL = 5;
    private const MAX_NETWORK_LEVEL = 15;

    private $networkTreeService;

    public function __construct()
    {
        $this->networkTreeService = new NetworkTreeService();
    }

    public function confirmPoint($paymentOrder, $userCurrent, $packCurrent, $reactiveAdmin = false)
    {
        if (!empty($paymentOrder->sponsor_code)
            && strcasecmp($paymentOrder->sponsor_code, $userCurrent->uuid) !== 0
            && User::where('uuid', $paymentOrder->sponsor_code)->exists()) {
            SponsorRelation::firstOrCreate(
                ['user_code' => $userCurrent->uuid],
                ['sponsor_code' => $paymentOrder->sponsor_code, 'source' => 'purchase', 'state' => true]
            );
        }

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
        // Base única del plan: puntos configurados en el paquete adquirido.
        // Nunca usar price, amount ni subtotal para calcular patrocinio.
        $puntosBaseNuevoSocio = (float) $packCurrent->points;

        $tipoPatrocinioParaEstePack = (strtoupper($packCurrent->category ?? '') === 'PRODUCTO')
            ? PaymentOrderPoint::PATROCINIO
            : PaymentOrderPoint::PATROCINIO_SERVICIO;

        $visited = [];
        while (!empty($currentSponsorCode) && $level <= self::MAX_NETWORK_LEVEL) {
            $normalizedSponsor = strtoupper($currentSponsorCode);
            if (isset($visited[$normalizedSponsor])) break;
            $visited[$normalizedSponsor] = true;
            $sponsorUser = User::where('uuid', $currentSponsorCode)->first();
            if (!$sponsorUser) break;

            $sponsorPack = null;

            $sponsorLog = PaymentLog::where('user_id', $sponsorUser->id)
                ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])
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
                $productOrder = PaymentProductOrder::where('user_id', $sponsorUser->id)
                    ->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO])
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->with('pack')
                    ->orderByDesc('created_at')
                    ->first();

                if ($productOrder && $productOrder->pack) {
                    $sponsorPack = $productOrder;
                }
            }

            $superiorSponsorCode = $this->networkTreeService->sponsorCode($currentSponsorCode) ?? '';

            $existingGrupal = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                ->where('user_code', $currentSponsorCode)
                ->where('type', PaymentOrderPoint::GRUPAL)
                ->first();

            if (!$existingGrupal) {
                PaymentOrderPoint::create([
                    'payment_order_id' => $paymentOrder->id,
                    'user_code'        => $currentSponsorCode,
                    'sponsor_code'     => $superiorSponsorCode,
                    'source_user_code' => $userCurrent->uuid,
                    'point'            => $puntosBaseNuevoSocio,
                    'payment'          => 0,
                    'type'             => PaymentOrderPoint::GRUPAL,
                    'user_id'          => $userCurrent->id,
                    'state'            => true,
                ]);
            }

            if ($debeGenerarBono && $level <= self::MAX_SPONSORSHIP_LEVEL && $sponsorPack) {
                // El porcentaje corresponde al paquete que originó la afiliación,
                // no al paquete personal del beneficiario.
                $sponsorshipConfig = SponsorshipPoint::where('pack_id', $paymentOrder->pack_id)->first();

                if ($sponsorshipConfig) {
                    $field   = 'level' . $level;
                    $percent = floatval(str_replace(',', '.', $sponsorshipConfig->$field ?? 0));

                    if ($percent > 0) {
                        $montoDinero = round(($puntosBaseNuevoSocio * $percent) / 100, 2);

                        if ($montoDinero > 0) {
                            $existingBonus = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                                ->where('user_code', $currentSponsorCode)
                                ->where('type', $tipoPatrocinioParaEstePack)
                                ->first();

                            if (!$existingBonus) {
                                PaymentOrderPoint::create([
                                    'payment_order_id' => $paymentOrder->id,
                                    'user_code'        => $currentSponsorCode,
                                    'sponsor_code'     => $superiorSponsorCode,
                                    'source_user_code' => $userCurrent->uuid,
                                    'point'            => $montoDinero,
                                    'payment'          => 0,
                                    'type'             => $tipoPatrocinioParaEstePack,
                                    'level'            => $level,
                                    'user_id'          => $sponsorUser->id,
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
            $residualConfig      = ResidualPoint::first();
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
                $percent = (float) ($residualConfig?->{'level' . $countLevel} ?? 0);
                $point = $points * $percent / 100;
                $beneficiaryCode = $_paymentOrderPoint->sponsor_code;
                $beneficiary = User::where('uuid', $beneficiaryCode)->first();
                $exists = PaymentOrderPoint::where('payment_order_id', $paymentLog->payment_order_id)
                    ->where('user_code', $beneficiaryCode)
                    ->where('type', PaymentOrderPoint::RESIDUAL)
                    ->where('level', $countLevel)
                    ->exists();

                // 🔥 Si el punto es mayor a 0, se crea el registro
                if ($point > 0 && $beneficiary && !$exists) {
                    PaymentOrderPoint::create([
                        'payment_order_id' => $paymentLog->payment_order_id,
                        'user_code'        => $beneficiaryCode,
                        'sponsor_code'     => $this->networkTreeService->sponsorCode($beneficiaryCode) ?? '',
                        'source_user_code' => $userCurrent->uuid,
                        'point'            => $point,
                        'payment'          => 0, // No es pago directo
                        'type'             => PaymentOrderPoint::RESIDUAL, // Tipo R (Residual)
                        'level'            => $countLevel,
                        'user_id'          => $beneficiary->id,
                        'state'            => 1 // Activo
                    ]);
                }
            }
        }
    }
}
}
