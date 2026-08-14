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
use App\Models\CommissionRule;
use App\Models\ManualReactivation;

class CommissionService
{
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
        $maxNetworkLevel = (int) CommissionRule::where('state', true)->max('level');
        while (!empty($currentSponsorCode) && $level <= $maxNetworkLevel) {
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

            if ($debeGenerarBono && $sponsorPack) {
                // El porcentaje corresponde al paquete que originó la afiliación,
                // no al paquete personal del beneficiario.
                $sponsorshipConfig = CommissionRule::where('bonus_type', CommissionRule::SPONSORSHIP)
                    ->where('pack_id', $paymentOrder->pack_id)->where('level', $level)->where('state', true)->first();

                if ($sponsorshipConfig) {
                    $percent = (float) $sponsorshipConfig->percentage;

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

    public function confirmPointAfiliado($userCurrent, $points, $manualReactivationId = null, $paymentOrderId = null): array
{
    $summary = ['generated_count' => 0, 'generated_amount' => 0.0, 'blocked' => []];
    if ($manualReactivationId) {
        $reactivation = ManualReactivation::find($manualReactivationId);
        if (!$reactivation || (int) $reactivation->user_id !== (int) $userCurrent->id
            || $reactivation->state !== ManualReactivation::ACTIVE) {
            $summary['blocked'][] = ['reason' => 'source_reactivation_inactive'];
            return $summary;
        }
    }

    ActivationService::clearCache();
    $userCurrent->refresh();
    if (!$userCurrent->active) {
        $summary['blocked'][] = ['reason' => 'source_user_inactive'];
        return $summary;
    }

    if (!$paymentOrderId) {
        $paymentOrderId = PaymentLog::where('user_id', $userCurrent->id)
            ->where('state', PaymentLog::PAGADO)
            ->latest('created_at')->value('payment_order_id');
    }
    if (!$paymentOrderId) {
        $summary['blocked'][] = ['reason' => 'missing_payment_order'];
        return $summary;
    }

            // 🔥 OBTENER EL ÁRBOL COMPLETO DEL USUARIO (línea ascendente)
            $_paymentOrderPoints = $this->networkTreeService->loopTree([], $userCurrent->uuid);
            $maxResidualLevel = (int) CommissionRule::where('bonus_type', CommissionRule::RESIDUAL)
                ->where('state', true)->max('level');
            $countLevel          = 0;

            // Recorrer la linea ascendente hasta el ultimo nivel residual configurado.
            foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                $_paymentOrderPoint = (object) $_paymentOrderPoint;
                $countLevel++;
                
                if ($countLevel > $maxResidualLevel) break;

                $point = 0;
                $beneficiaryCode = $_paymentOrderPoint->sponsor_code;
                $beneficiary = User::where('uuid', $beneficiaryCode)->first();
                $beneficiaryRangeOrder = (int) ($beneficiary?->range?->range?->order ?? 0);
                $rule = CommissionRule::with('minimumRange')->where('bonus_type', CommissionRule::RESIDUAL)
                    ->where('level', $countLevel)->where('state', true)->first();
                $requiredRangeOrder = (int) ($rule?->minimumRange?->order ?? 0);
                $isActive = $beneficiary?->active ?? false;
                $percent = ($rule && $beneficiary && $isActive && $beneficiaryRangeOrder >= $requiredRangeOrder)
                    ? (float) $rule->percentage : 0;
                $point = $points * $percent / 100;
                $exists = PaymentOrderPoint::where('payment_order_id', $paymentOrderId)
                    ->where('user_code', $beneficiaryCode)
                    ->where('type', PaymentOrderPoint::RESIDUAL)
                    ->where('level', $countLevel)
                    ->exists();

                // 🔥 Si el punto es mayor a 0, se crea el registro
                if ($point > 0 && $beneficiary && !$exists) {
                    PaymentOrderPoint::create([
                        'payment_order_id' => $paymentOrderId,
                        'manual_reactivation_id' => $manualReactivationId,
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
                    $summary['generated_count']++;
                    $summary['generated_amount'] += $point;
                } elseif ($beneficiary && !$exists) {
                    $summary['blocked'][] = [
                        'level' => $countLevel,
                        'user_code' => $beneficiaryCode,
                        'reason' => !$rule ? 'rule_not_configured'
                            : (!$isActive ? 'beneficiary_inactive'
                            : ($beneficiaryRangeOrder < $requiredRangeOrder ? 'minimum_range_not_met' : 'zero_percentage')),
                    ];
                }
            }
    $summary['generated_amount'] = round($summary['generated_amount'], 2);
    return $summary;
}
}
