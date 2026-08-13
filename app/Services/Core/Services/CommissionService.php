<?php

namespace App\Services\Core\Services;

use App\Models\PaymentOrder;
use App\Services\Core\CommissionService as CoreCommissionService;
use App\Services\Core\NetworkTreeService;

/**
 * Adaptador para las órdenes de producto. Conserva el endpoint existente y
 * delega toda la distribución al motor MLM central.
 */
class CommissionService
{
    public function distribute($productOrder, $user, $pack): void
    {
        $network = new NetworkTreeService();
        $sponsorCode = $network->sponsorCode($user->uuid);
        if (!$sponsorCode) return;

        $order = PaymentOrder::firstOrCreate(
            ['token' => 'PRODUCT-COMMISSION-' . $productOrder->id],
            [
                'currency' => $productOrder->currency ?? 'PEN',
                'amount' => $productOrder->amount ?? 0,
                'sponsor_code' => $sponsorCode,
                'pack_id' => $pack->id,
            ]
        );

        (new CoreCommissionService())->confirmPoint($order, $user, $pack, true);
    }
}
