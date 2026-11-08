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
use Illuminate\Support\Facades\DB;

class CommissionService
{
    private $networkTreeService;

    public function __construct()
    {
        $this->networkTreeService = new NetworkTreeService();
    }

    public function confirmPoint($paymentOrder, $userCurrent, $packCurrent, $reactiveAdmin = false)
    {
        if (DB::transactionLevel() === 0) {
            return DB::transaction(function () use ($paymentOrder, $userCurrent, $packCurrent, $reactiveAdmin) {
                $this->confirmPoint($paymentOrder, $userCurrent, $packCurrent, $reactiveAdmin);
            }, 3);
        }
        $this->networkTreeService->lockRootForMutation();

        // A confirmation retry after a genealogy move must not append newly
        // eligible beneficiaries to an earlier distribution. Successful
        // generation is transactional; existing commission rows therefore
        // mean this order has already started settlement and is immutable.
        $existingDistribution = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
            ->whereIn('type', [
                PaymentOrderPoint::PATROCINIO, PaymentOrderPoint::PATROCINIO_SERVICIO,
                PaymentOrderPoint::RESIDUAL, PaymentOrderPoint::RESIDUAL_SERVICIO,
                PaymentOrderPoint::INFINITO, PaymentOrderPoint::GRUPAL,
            ])->exists();
        if ($existingDistribution) return;


        // PaymentOrder keeps the sponsor selected when the order was created
        // for historical traceability. The active relation is authoritative
        // for commissions calculated now, especially after a branch move.
        $currentSponsorCode = $this->networkTreeService->sponsorCode($userCurrent->uuid, true);

        if (!$reactiveAdmin) {
            $existingPersonal = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->first();

            if (!$existingPersonal) {
                PaymentOrderPoint::create([
                    'payment_order_id' => $paymentOrder->id,
                    'user_code'        => $userCurrent->uuid,
                    'sponsor_code'     => $currentSponsorCode ?? '',
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
        $level                = 1;
        // Base única del plan: puntos configurados en el paquete adquirido.
        // Nunca usar price, amount ni subtotal para calcular patrocinio.
        $puntosBaseNuevoSocio = (float) $packCurrent->points;

        $tipoPatrocinioParaEstePack = (strtoupper($packCurrent->category ?? '') === 'PRODUCTO')
            ? PaymentOrderPoint::PATROCINIO
            : PaymentOrderPoint::PATROCINIO_SERVICIO;

        $visited = [];
        $maxNetworkLevel = max(5, (int) CommissionRule::where('state', true)->max('level'));
        $sponsorshipConfig = SponsorshipPoint::where('pack_id', $paymentOrder->pack_id)->first();
        while (!empty($currentSponsorCode) && $level <= $maxNetworkLevel) {
            $normalizedSponsor = strtoupper($currentSponsorCode);
            if (isset($visited[$normalizedSponsor])) break;
            $visited[$normalizedSponsor] = true;
            $sponsorUser = User::where('uuid', $currentSponsorCode)->first();
            if (!$sponsorUser) break;
            $isCompanySponsor = $sponsorUser->is_admin
                || app(CorporateRootService::class)->isRootCode($sponsorUser->uuid);

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

            if ($debeGenerarBono && (
                $isCompanySponsor
                || ($sponsorPack && app(ActivationService::class)->isActive($sponsorUser))
            )) {
                // El porcentaje corresponde al paquete que originó la afiliación,
                // no al paquete personal del beneficiario.
                if ($sponsorshipConfig) {
                    $percent = $sponsorshipConfig->percentageForLevel($level);

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

    public function confirmPointAfiliado(
        $userCurrent,
        $points,
        $manualReactivationId = null,
        $paymentOrderId = null,
        string $category = 'product'
    ): array
{
    if (DB::transactionLevel() === 0) {
        return DB::transaction(fn () => $this->confirmPointAfiliado(
            $userCurrent, $points, $manualReactivationId, $paymentOrderId, $category
        ), 3);
    }
    $this->networkTreeService->lockRootForMutation();

    // A residual stream is calculated as one transaction against one locked
    // genealogy. A callback retry after a move is a no-op, never a second pass
    // that could combine old and current beneficiaries.
    if ($paymentOrderId && PaymentOrderPoint::where('payment_order_id', $paymentOrderId)
        ->whereIn('type', [PaymentOrderPoint::RESIDUAL, PaymentOrderPoint::RESIDUAL_SERVICIO, PaymentOrderPoint::INFINITO])
        ->exists()) {
        return ['generated_count' => 0, 'generated_amount' => 0.0, 'blocked' => [
            ['reason' => 'distribution_already_started'],
        ]];
    }

    $summary = ['generated_count' => 0, 'generated_amount' => 0.0, 'blocked' => []];
    $category = strtolower(trim($category));
    if (!in_array($category, ['product', 'service'], true)) {
        $summary['blocked'][] = ['reason' => 'invalid_reactivation_category'];
        return $summary;
    }
    if ($manualReactivationId) {
        $reactivation = ManualReactivation::find($manualReactivationId);
        if (!$reactivation || (int) $reactivation->user_id !== (int) $userCurrent->id
            || $reactivation->state !== ManualReactivation::ACTIVE
            || $reactivation->category !== $category) {
            $summary['blocked'][] = ['reason' => 'source_reactivation_inactive'];
            return $summary;
        }
    }

    ActivationService::clearCache();
    $userCurrent->refresh();
    // Solo los extremos deben estar activos: quien origina el volumen y
    // quien recibe la comisión. La actividad de usuarios intermedios nunca
    // interviene ni corta el recorrido ascendente.
    if (!app(ActivationService::class)->isActiveForCategory($userCurrent, $category)) {
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
            $_paymentOrderPoints = $this->networkTreeService->loopTree([], $userCurrent->uuid, true);
            $policy = new ResidualCommissionPolicy();
            $countLevel          = 0;

            // La red termina en su raiz; el 1 % general no tiene limite de nivel.
            foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                $_paymentOrderPoint = (object) $_paymentOrderPoint;
                $countLevel++;
                
                $beneficiaryCode = $_paymentOrderPoint->sponsor_code;
                $beneficiary = User::where('uuid', $beneficiaryCode)->first();
                $isCompany = $beneficiary && (
                    $beneficiary->is_admin
                    || app(CorporateRootService::class)->isRootCode($beneficiary->uuid)
                );
                $beneficiaryRangeOrder = (int) ($beneficiary?->range?->range?->order ?? 0);
                $isActive = $beneficiary
                    ? app(ActivationService::class)->isActiveForCategory($beneficiary, $category)
                    : false;
                [$percent, $kind] = $policy->rate(
                    $category, $countLevel, $beneficiaryRangeOrder,
                    $countLevel > 7 && $beneficiary && $isActive
                        ? app(ActivationService::class)->hasRankActivity($beneficiary) : false,
                    (bool) $isCompany
                );
                $type = $kind === 'infinity' ? PaymentOrderPoint::INFINITO
                    : ($category === 'service'
                        ? PaymentOrderPoint::RESIDUAL_SERVICIO
                        : PaymentOrderPoint::RESIDUAL);
                $point = $isActive ? round((float) $points * $percent / 100, 2) : 0.0;
                $exists = PaymentOrderPoint::where('payment_order_id', $paymentOrderId)
                    ->where('user_code', $beneficiaryCode)
                    ->whereIn('type', [PaymentOrderPoint::RESIDUAL,
                        PaymentOrderPoint::RESIDUAL_SERVICIO, PaymentOrderPoint::INFINITO])
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
                        'type'             => $type,
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
                        'reason' => !$isActive ? 'beneficiary_inactive' : 'zero_percentage',
                    ];
                }
            }
    $summary['generated_amount'] = round($summary['generated_amount'], 2);
    return $summary;
}
}
