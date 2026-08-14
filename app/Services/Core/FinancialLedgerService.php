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
            ->where('state', true)
            ->where('point', '>', 0);
        if ($from && $to) $query->whereBetween('created_at', [$from, $to]);
        if ($userCode) $query->whereRaw('UPPER(user_code) = ?', [strtoupper($userCode)]);

        return $query->orderBy('id')->get()
            ->groupBy(fn (PaymentOrderPoint $row) => $this->logicalCommissionKey($row))
            // Si un proceso reintenta exactamente la misma comision, el
            // primer asiento es el original. Nunca elegir por mayor importe.
            ->map(fn (Collection $duplicates) => $duplicates->first())
            ->values();
    }

    public function logicalCommissionKey(PaymentOrderPoint $row): string
    {
        $type = $row->type === 'S' ? 'PS' : $row->type;

        return implode('|', [
            $row->payment_order_id ?: 'ROW-'.$row->id,
            strtoupper((string) $row->user_code),
            strtoupper((string) ($row->source_user_code ?? '')),
            $type,
            (int) ($row->level ?? 0),
            (int) ($row->manual_reactivation_id ?? 0),
        ]);
    }

    public function summary(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?string $userCode = null): array
    {
        $rows = $this->movements($from, $to, $userCode);
        $active = $rows;
        $sum = fn (Collection $items, array $types) => round((float) $items->whereIn('type', $types)->sum('point'), 2);
        $patrocinio = $sum($active, ['P', 'PS', 'S']);
        $residual = $sum($active, ['R', 'RS']);
        $infinito = $sum($active, ['I']);

        $bonoTotal = round($patrocinio + $residual + $infinito, 2);

        return [
            'patrocinio' => $patrocinio,
            'bono' => $patrocinio,
            'residual' => $residual,
            'infinito' => $infinito,
            'bono_total' => $bonoTotal,
            'bonos_totales' => $bonoTotal,
            'total_comisiones' => $bonoTotal,
            'ganancia_total' => $bonoTotal,
            'movimientos_validos' => $active->count(),
            'movimientos_anulados' => 0,
        ];
    }
}
