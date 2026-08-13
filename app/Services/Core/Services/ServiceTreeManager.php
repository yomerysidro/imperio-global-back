<?php

namespace App\Services\Core\Services;

use App\Models\PaymentOrderPoint;
use App\Models\SponsorshipPoint;
use App\Models\User;
use App\Services\Core\NetworkTreeService;

class ServiceTreeManager
{
    private const MAX_SPONSORSHIP_LEVEL = 5;
    private const MAX_NETWORK_LEVEL = 15;

    public function distributePoints($userCode, $orderId, $points, $packId, $level = 1, &$visited = [])
    {
        if ($level > self::MAX_NETWORK_LEVEL) return;

        $normalized = strtoupper($userCode);
        if (isset($visited[$normalized])) return;
        $visited[$normalized] = true;

        $tree = new NetworkTreeService();
        $sponsorCode = $tree->sponsorCode($userCode);
        if (!$sponsorCode) return;

        $sponsor = User::where('uuid', $sponsorCode)->first();
        if (!$sponsor) return;

        $commission = $level <= self::MAX_SPONSORSHIP_LEVEL
            ? $this->calculateServicePoints($points, $level, $packId)
            : 0;
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
        $config = SponsorshipPoint::where('pack_id', $packId)->first();
        $percent = (float) ($config?->{"level{$level}"} ?? 0);

        return (float) $totalPoints * $percent / 100;
    }
}
