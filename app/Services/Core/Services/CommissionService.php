<?php

namespace App\Services\Core\Services;

use App\Models\PaymentOrder;
use App\Services\Core\CommissionService as CoreCommissionService;
use App\Services\Core\NetworkTreeService;
use Illuminate\Support\Facades\DB;

/**
 * Adaptador para las órdenes de producto. Conserva el endpoint existente y
 * delega toda la distribución al motor MLM central.
 */
class CommissionService
{
    public function distribute($productOrder, $user, $pack): void
    {
        if (DB::transactionLevel() === 0) {
            DB::transaction(fn () => $this->distribute($productOrder, $user, $pack), 3);
            return;
        }
        $network = new NetworkTreeService();
        $network->lockRootForMutation();
        $sponsorCode = $network->sponsorCode($user->uuid, true);
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
