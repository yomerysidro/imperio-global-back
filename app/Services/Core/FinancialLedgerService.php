<?php

namespace App\Services\Core;

use App\Models\PaymentOrderPoint;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class FinancialLedgerService
{
    public function movements(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $userCode = null): Collection
    {
        $query = PaymentOrderPoint::query()
            ->whereIn('type', ['P', 'PS', 'S', 'R', 'RS', 'I'])
            ->where('point', '>', 0);
        if ($from && $to) $query->whereBetween('created_at', [$from, $to]);
        if ($userCode) $query->whereRaw('UPPER(user_code) = ?', [strtoupper($userCode)]);

        return $query->orderBy('id')->get()->groupBy(function (PaymentOrderPoint $row) {
            $type = $row->type === 'S' ? 'PS' : $row->type;
            return implode('|', [
                $row->payment_order_id ?: 'ROW-'.$row->id,
                strtoupper((string) $row->user_code),
                (int) ($row->level ?? 0),
                $type,
            ]);
        })->map(function (Collection $duplicates) {
            // Una misma comision logica se paga una sola vez. Se conserva el
            // movimiento vigente de mayor importe para soportar datos legacy.
            return $duplicates->sort(function ($left, $right) {
                return [(int) $right->state, (float) $right->point, (int) $right->id]
                    <=> [(int) $left->state, (float) $left->point, (int) $left->id];
            })->first();
        })->values();
    }

    public function summary(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $userCode = null): array
    {
        $rows = $this->movements($from, $to, $userCode);
        $active = $rows->where('state', true);
        $sum = fn (Collection $items, array $types) => round((float) $items->whereIn('type', $types)->sum('point'), 2);
        $patrocinio = $sum($active, ['P', 'PS', 'S']);
        $residual = $sum($active, ['R', 'RS']);
        $infinito = $sum($active, ['I']);

        return [
            'patrocinio' => $patrocinio,
            'residual' => $residual,
            'infinito' => $infinito,
            'total_comisiones' => round($patrocinio + $residual + $infinito, 2),
            'movimientos_validos' => $active->count(),
            'movimientos_anulados' => $rows->where('state', false)->count(),
        ];
    }
}
