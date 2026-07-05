<?php

namespace App\Services\Core;

use App\Models\User;
use App\Models\PaymentOrderPoint;
use App\Models\GuestsTokenUser;
use App\Models\PaymentLog;
use Carbon\Carbon;

class NetworkTreeService
{
    public function getAllNetworkUsers($userCode, &$visited = [])
    {
        if (in_array($userCode, $visited)) {
            return [];
        }
        $visited[] = $userCode;
        $users     = [$userCode];

        $transactionalChildren = PaymentOrderPoint::where('sponsor_code', $userCode)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->where('state', true)
            ->pluck('user_code')
            ->unique()
            ->toArray();

        $historicalChildren = GuestsTokenUser::where('sponsor_user_code', $userCode)
            ->where('state', true)
            ->pluck('guest_user_code')
            ->toArray();

        $allChildren = array_unique(array_merge($transactionalChildren, $historicalChildren));

        foreach ($allChildren as $child) {
            $childUsers = $this->getAllNetworkUsers($child, $visited);
            $users      = array_merge($users, $childUsers);
        }

        return array_unique($users);
    }

    public function countTotalNetworkRecursive($userCode, &$visited = [])
    {
        if (in_array($userCode, $visited)) {
            return 0;
        }
        $visited[] = $userCode;

        $count         = 0;
        $now           = Carbon::now();
        $currentMonth  = $now->month;
        $currentYear   = $now->year;
        $mesAnterior   = $now->copy()->subMonth();
        $isGracePeriod = $now->day <= 2;

        $transactionalChildren = PaymentOrderPoint::where('sponsor_code', $userCode)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->where('state', true)
            ->where('payment', 1)
            ->where(function ($query) use ($currentMonth, $currentYear, $mesAnterior, $isGracePeriod) {
                $query->whereMonth('created_at', $currentMonth)->whereYear('created_at', $currentYear);
                if ($isGracePeriod) {
                    $query->orWhere(function ($q) use ($mesAnterior) {
                        $q->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year);
                    });
                }
            })
            ->pluck('user_code')
            ->toArray();

        $historicalChildren = GuestsTokenUser::where('sponsor_user_code', $userCode)
            ->where('state', true)
            ->pluck('guest_user_code')
            ->toArray();

        $historicalChildrenActive = [];
        foreach ($historicalChildren as $childCode) {
            $user = User::where('uuid', $childCode)->first();
            if ($user) {
                $hasPayment = PaymentLog::where('user_id', $user->id)
                    ->whereIn('state', [2, 6])
                    ->where(function ($query) use ($currentMonth, $currentYear, $mesAnterior, $isGracePeriod) {
                        $query->whereMonth('created_at', $currentMonth)->whereYear('created_at', $currentYear);
                        if ($isGracePeriod) {
                            $query->orWhere(function ($q) use ($mesAnterior) {
                                $q->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year);
                            });
                        }
                    })
                    ->exists();
                if ($hasPayment) {
                    $historicalChildrenActive[] = $childCode;
                }
            }
        }

        $allChildren = array_unique(array_merge($transactionalChildren, $historicalChildrenActive));

        foreach ($allChildren as $child) {
            $count++;
            $count += $this->countTotalNetworkRecursive($child, $visited);
        }

        return $count;
    }

    public function buildDescendantTree($userCode, $currentLevel = 0, $maxLevel = 15)
    {
        if ($currentLevel >= $maxLevel) {
            return null;
        }

        $user = User::where('uuid', $userCode)->first();

        $node = [
            'user_code'      => $userCode,
            'user_name'      => $user ? $user->name : 'Usuario no encontrado',
            'email'          => $user ? $user->email : null,
            'level'          => $currentLevel,
            'children' => [],
            'total_children' => 0
        ];

        $transactionalChildren = PaymentOrderPoint::where('sponsor_code', $userCode)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->where('state', true)
            ->where('payment', 1)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($transactionalChildren as $child) {
            $childNode = $this->buildDescendantTree($child->user_code, $currentLevel + 1, $maxLevel);
            if ($childNode) {
                $childNode['source'] = 'transactional';
                $node['children'][]  = $childNode;
            }
        }

        $historicalChildren = GuestsTokenUser::where('sponsor_user_code', $userCode)
            ->where('state', true)
            ->get();

        foreach ($historicalChildren as $child) {
            $exists = false;
            foreach ($node['children'] as $existing) {
                if ($existing['user_code'] === $child->guest_user_code) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $childNode = $this->buildDescendantTree($child->guest_user_code, $currentLevel + 1, $maxLevel);
                if ($childNode) {
                    $childNode['source'] = 'historical';
                    $node['children'][]  = $childNode;
                }
            }
        }

        $node['total_children'] = count($node['children']);
        return $node;
    }

    public function countNodes($tree)
    {
        if (!$tree) {
            return 0;
        }
        $count = 1;
        if (isset($tree['children']) && is_array($tree['children'])) {
            foreach ($tree['children'] as $child) {
                $count += $this->countNodes($child);
            }
        }
        return $count;
    }

    public function loopTree(array $a_paymentOrderPoint, string $userCode)
    {
        if (strtoupper($userCode) == 'DOSB') {
            return $a_paymentOrderPoint;
        }

        $paymentOrderPoint = PaymentOrderPoint::select('user_code', 'sponsor_code')
            ->where("user_code", $userCode)
            ->whereIn("type", [PaymentOrderPoint::COMPRA, PaymentOrderPoint::PATROCINIO])
            ->where("state", true)
            ->orderBy('created_at', 'asc')
            ->first();

        if ($paymentOrderPoint == null) {
            $guest = GuestsTokenUser::select('guest_user_code as user_code', 'sponsor_user_code as sponsor_code')
                ->where("guest_user_code", $userCode)
                ->where("state", true)
                ->first();

            if ($guest) {
                $paymentOrderPoint = $guest;
            }
        }

        if ($paymentOrderPoint != null && !empty($paymentOrderPoint->sponsor_code)) {
            if ($paymentOrderPoint->sponsor_code == $userCode) {
                return $a_paymentOrderPoint;
            }

            array_push($a_paymentOrderPoint, $paymentOrderPoint);
            return $this->loopTree($a_paymentOrderPoint, $paymentOrderPoint->sponsor_code);
        }

        return $a_paymentOrderPoint;
    }
}