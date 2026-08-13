<?php

namespace App\Services\Core\Services;

use App\Models\PaymentOrderPoint;
use App\Models\CommissionRule;
use App\Models\RangeRule;
use App\Models\User;
use App\Services\Core\NetworkTreeService;

class ServiceTreeManager
{
    public function distributePoints($userCode, $orderId, $points, $packId, $level = 1, &$visited = [])
    {
        $maxNetworkLevel = (int) RangeRule::where('state', true)->max('depth_to');
        if ($level > $maxNetworkLevel) return;

        $normalized = strtoupper($userCode);
        if (isset($visited[$normalized])) return;
        $visited[$normalized] = true;

        $tree = new NetworkTreeService();
        $sponsorCode = $tree->sponsorCode($userCode);
        if (!$sponsorCode) return;

        $sponsor = User::where('uuid', $sponsorCode)->first();
        if (!$sponsor) return;

        $commission = $this->calculateServicePoints($points, $level, $packId);
        if ($commission > 0) {
            PaymentOrderPoint::firstOrCreate([
                'payment_order_id' => $orderId,
                'user_code' => $sponsor->uuid,
                'type' => PaymentOrderPoint::PATROCINIO_SERVICIO,
                'level' => $level,
            ], [
                'sponsor_code' => $tree->sponsorCode($sponsor->uuid) ?? '',
                'source_user_code' => $userCode,
                'point' => $commission,
                'payment' => false,
                'user_id' => $sponsor->id,
                'state' => true,
            ]);
        }

        PaymentOrderPoint::firstOrCreate([
            'payment_order_id' => $orderId,
            'user_code' => $sponsor->uuid,
            'type' => PaymentOrderPoint::GRUPAL,
        ], [
            'sponsor_code' => $tree->sponsorCode($sponsor->uuid) ?? '',
            'source_user_code' => $userCode,
            'point' => $points,
            'payment' => false,
            'level' => $level,
            'user_id' => $sponsor->id,
            'state' => true,
        ]);

        $this->distributePoints($sponsor->uuid, $orderId, $points, $packId, $level + 1, $visited);
    }

    private function calculateServicePoints($totalPoints, $level, $packId)
    {
        $config = CommissionRule::where('bonus_type', CommissionRule::SPONSORSHIP)
            ->where('pack_id', $packId)->where('level', $level)->where('state', true)->first();
        $percent = (float) ($config?->percentage ?? 0);

        return (float) $totalPoints * $percent / 100;
    }
}
