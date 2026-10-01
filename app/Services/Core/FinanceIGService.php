<?php

namespace App\Services\Core;

use App\Models\PaymentProductOrder;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Finanzas de la empresa e inventario. No genera ni modifica comisiones MLM.
 * Las tablas se nombran sin ig_: la conexión MySQL aplica ese prefijo.
 */
class FinanceIGService
{
    private const MOVEMENTS = 'product_inventory_movements';
    private const DETAILS = 'product_inventory_movement_details';

    /** Consulta paginada de entradas y salidas, con sus productos y costos. */
    public function inventoryMovements(array $filters = []): array
    {
        $entryTypes = ['INITIAL_ENTRY', 'PURCHASE_ENTRY', 'SALE_CANCELLATION', 'RETURN_ENTRY', 'ADJUSTMENT_ENTRY'];
        $exitTypes = ['SALE_EXIT', 'ADJUSTMENT_EXIT'];
        $query = DB::table(self::MOVEMENTS . ' as m')
            ->select('m.id', 'm.movement_type', 'm.movement_date', 'm.description', 'm.created_at')
            ->when(isset($filters['year']), fn ($q) => $q->whereYear('m.movement_date', $filters['year']))
            ->when(isset($filters['month']), fn ($q) => $q->whereMonth('m.movement_date', $filters['month']))
            ->when(isset($filters['movement_type']), fn ($q) => $q->where('m.movement_type', $filters['movement_type']))
            ->when(isset($filters['direction']), fn ($q) => $q->whereIn(
                'm.movement_type', $filters['direction'] === 'entry' ? $entryTypes : $exitTypes
            ))
            ->when(isset($filters['product_id']), function ($q) use ($filters) {
                $q->whereExists(function ($details) use ($filters) {
                    $details->selectRaw('1')->from(self::DETAILS . ' as filter_detail')
                        ->whereColumn('filter_detail.inventory_movement_id', 'm.id')
                        ->where('filter_detail.product_id', $filters['product_id']);
                });
            })
            ->orderByDesc('m.movement_date')->orderByDesc('m.id');

        $movements = $query->paginate((int) ($filters['per_page'] ?? 20));
        $ids = $movements->getCollection()->pluck('id');
        $detailsByMovement = $ids->isEmpty() ? collect() : DB::table(self::DETAILS . ' as d')
            ->join('products as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('payment_product_order_details as od', 'od.id', '=', 'd.payment_product_order_detail_id')
            ->whereIn('d.inventory_movement_id', $ids)
            ->orderBy('d.id')
            ->get([
                'd.id', 'd.inventory_movement_id', 'd.product_id', 'p.title as product_title',
                'd.payment_product_order_detail_id', 'od.payment_product_order_id as order_id',
                'd.quantity', 'd.company_unit_cost', 'd.additional_cost', 'd.total_cost',
                'd.lot_code', 'd.expiration_at',
            ])->groupBy('inventory_movement_id');

        $movements->getCollection()->transform(function ($movement) use ($detailsByMovement, $entryTypes) {
            $lines = $detailsByMovement->get($movement->id, collect());
            $movement->direction = in_array($movement->movement_type, $entryTypes, true) ? 'entry' : 'exit';
            $movement->total_quantity = (int) $lines->sum('quantity');
            $movement->total_cost = $this->decimal($lines->sum(fn ($line) => $this->cents($line->total_cost)));
            $movement->details = $lines->values()->all();
            return $movement;
        });

        return $movements->toArray();
    }

    /** Conserva el precio publico cuando se crea la orden, antes de cobrarla. */
    public function captureOrderPricing(string $orderId): void
    {
        DB::transaction(function () use ($orderId) {
            $order = PaymentProductOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            $details = DB::table('payment_product_order_details')
                ->where('payment_product_order_id', $orderId)->orderBy('id')->get();
            if ($details->isEmpty()) {
                throw ValidationException::withMessages(['order_id' => 'La orden no tiene productos.']);
            }
            if ($details->every(fn ($detail) => $detail->public_unit_price_snapshot !== null
                && $detail->sale_unit_price !== null)) return;

            $products = DB::table('products')->whereIn('id', $details->pluck('product_id'))
                ->get(['id', 'price', 'company_unit_cost'])->keyBy('id');
            $gross = 0;
            foreach ($details as $detail) {
                if (!$products->has($detail->product_id)) {
                    throw ValidationException::withMessages(['order_id' => 'Un producto de la orden ya no existe.']);
                }
                if ($this->cents($products[$detail->product_id]->company_unit_cost) <= 0) {
                    throw ValidationException::withMessages(['order_id' => 'Registre el costo inicial del producto antes de crear la compra.']);
                }
                $gross += $this->cents($products[$detail->product_id]->price) * (int) $detail->quantity;
            }
            $paid = $this->cents($order->amount);
            if ($gross <= 0 || $paid < 0) {
                throw ValidationException::withMessages(['order_id' => 'Importe de venta invalido.']);
            }

            // El importe de la cabecera manda para la utilidad del pack completo.
            // El descuento proporcional solo presenta precios por producto.
            $ratio = min(1, $paid / $gross);
            $percentage = round((1 - $ratio) * 100, 2);
            foreach ($details as $detail) {
                $public = $this->cents($products[$detail->product_id]->price);
                $sale = (int) round($public * $ratio);
                DB::table('payment_product_order_details')->where('id', $detail->id)->update([
                    'public_unit_price_snapshot' => $this->decimal($public),
                    'discount_percentage' => number_format($percentage, 2, '.', ''),
                    'discount_amount' => $this->decimal($public - $sale),
                    'sale_unit_price' => $this->decimal($sale),
                    'updated_at' => now(),
                ]);
            }
        });
    }

    public function registerEntry(string $type, array $lines, ?string $date = null, ?string $description = null): int
    {
        if (!in_array($type, ['INITIAL_ENTRY', 'PURCHASE_ENTRY'], true) || $lines === []) {
            throw ValidationException::withMessages(['lines' => 'Tipo de entrada o productos invalidos.']);
        }

        return DB::transaction(function () use ($type, $lines, $date, $description) {
            $now = now();
            $productIds = array_values(array_unique(array_column($lines, 'product_id')));

            $products = DB::table('products')->whereIn('id', $productIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($products->count() !== count($productIds)) {
                throw ValidationException::withMessages(['lines' => 'Hay productos que no existen.']);
            }

            $movementId = DB::table(self::MOVEMENTS)->insertGetId([
                'movement_type' => $type,
                'movement_date' => $date ?? $now,
                'description' => $description,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach (collect($lines)->groupBy('product_id') as $productId => $productLines) {
                $product = $products[$productId];
                $currentStock = (int) $product->stock;
                $totalQuantity = $productLines->sum(fn ($line) => (int) $line['quantity']);
                if ($type === 'INITIAL_ENTRY') {
                    $alreadyRecorded = DB::table(self::DETAILS)->where('product_id', $product->id)->exists();
                    if ($alreadyRecorded || $currentStock !== $totalQuantity) {
                        throw ValidationException::withMessages(['lines' => 'La entrada inicial debe reflejar el stock actual y registrarse una sola vez.']);
                    }
                } elseif ($currentStock > 0 && $this->cents($product->company_unit_cost) === 0) {
                    throw ValidationException::withMessages(['lines' => 'Registre el costo del stock inicial antes de comprar mas unidades.']);
                }
                $priorEntry = DB::table(self::DETAILS . ' as d')
                    ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
                    ->where('d.product_id', $product->id)
                    ->whereIn('m.movement_type', ['INITIAL_ENTRY', 'PURCHASE_ENTRY'])
                    ->select('d.lot_code')->first();
                $lotModes = $productLines->map(fn ($line) => !empty($line['lot_code']))->unique();
                if ($lotModes->count() !== 1 || ($priorEntry && (($priorEntry->lot_code !== null) !== $lotModes->first()))) {
                    throw ValidationException::withMessages(['lines' => 'Un producto debe usar lotes en todas sus entradas o en ninguna.']);
                }

                $incomingValue = 0;
                foreach ($productLines as $line) {
                    $quantity = (int) $line['quantity'];
                    $unitCost = $this->cents($line['company_unit_cost']);
                    $additional = $this->cents($line['additional_cost'] ?? 0);
                    if ($quantity < 1 || $unitCost < 0 || $additional < 0) {
                        throw ValidationException::withMessages(['lines' => 'Cantidad y costos invalidos.']);
                    }
                    $incomingValue += $quantity * $unitCost + $additional;
                    DB::table(self::DETAILS)->insert([
                        'inventory_movement_id' => $movementId,
                        'product_id' => $product->id,
                        'payment_product_order_detail_id' => null,
                        'quantity' => $quantity,
                        'company_unit_cost' => $this->decimal($unitCost),
                        'additional_cost' => $this->decimal($additional),
                        'lot_code' => $line['lot_code'] ?? null,
                        'expiration_at' => $line['expiration_at'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                $oldValue = $currentStock * $this->cents($product->company_unit_cost);
                $newStock = $type === 'INITIAL_ENTRY' ? $currentStock : $currentStock + $totalQuantity;
                $newValue = $type === 'INITIAL_ENTRY' ? $incomingValue : $oldValue + $incomingValue;
                DB::table('products')->where('id', $product->id)->update([
                    'stock' => $newStock,
                    'company_unit_cost' => $this->decimal((int) round($newValue / $newStock)),
                    'updated_at' => $now,
                ]);
            }

            return $movementId;
        });
    }

    /**
     * Reactivacion: registra el costo; su flujo existente ya desconto stock.
     * Tienda: registra el costo y descuenta el mismo stock compartido.
     */
    public function registerSale(
        string $orderId,
        array $allocations = [],
        array $snapshots = [],
        bool $stockAlreadyDiscounted = false
    ): int
    {
        return DB::transaction(function () use ($orderId, $allocations, $snapshots, $stockAlreadyDiscounted) {
            $order = PaymentProductOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if (!in_array((int) $order->state, [
                PaymentProductOrder::PAGADO,
                PaymentProductOrder::ENVIADO,
                PaymentProductOrder::TERMINADO,
            ], true)) {
                throw ValidationException::withMessages(['order_id' => 'La compra aun no esta pagada o fue anulada.']);
            }

            $details = DB::table('payment_product_order_details')
                ->where('payment_product_order_id', $orderId)->orderBy('id')->get();
            if ($details->isEmpty()) {
                throw ValidationException::withMessages(['order_id' => 'La compra no tiene productos.']);
            }
            $detailIds = $details->pluck('id')->all();
            $existingMovementId = DB::table(self::DETAILS . ' as d')
                ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
                ->whereIn('d.payment_product_order_detail_id', $detailIds)
                ->where('m.movement_type', 'SALE_EXIT')->value('m.id');
            if ($existingMovementId !== null) {
                return (int) $existingMovementId;
            }

            $isReactivation = $stockAlreadyDiscounted || DB::table('manual_reactivations')
                ->where('payment_product_order_id', $orderId)->exists();
            $required = $details->groupBy('product_id')->map(fn ($rows) => $rows->sum('quantity'));
            $productIds = $required->keys();
            $products = DB::table('products')->whereIn('id', $productIds)
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($productIds as $productId) {
                $product = $products->get($productId);
                if (!$product || $this->cents($product->company_unit_cost) === 0) {
                    throw ValidationException::withMessages(['order_id' => 'Registre el costo inicial del producto antes de venderlo.']);
                }
                if (!$isReactivation && (int) $product->stock < (int) $required[$productId]) {
                    throw ValidationException::withMessages(['order_id' => 'Stock insuficiente para la venta de tienda.']);
                }
            }

            $now = now();
            $prefix = DB::connection()->getTablePrefix();
            $movementId = DB::table(self::MOVEMENTS)->insertGetId([
                'movement_type' => 'SALE_EXIT',
                'movement_date' => $now,
                'description' => 'Compra de productos ' . $orderId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($details as $detail) {
                $snapshot = $snapshots[$detail->id] ?? [
                    'public_unit_price_snapshot' => $detail->public_unit_price_snapshot,
                    'discount_amount' => $detail->discount_amount,
                    'sale_unit_price' => $detail->sale_unit_price,
                    'discount_percentage' => $detail->discount_percentage,
                ];
                $public = $this->cents($snapshot['public_unit_price_snapshot'] ?? -1);
                $discount = $this->cents($snapshot['discount_amount'] ?? -1);
                $sale = $this->cents($snapshot['sale_unit_price'] ?? -1);
                $percentage = (float) ($snapshot['discount_percentage'] ?? -1);
                if ($public < 0 || $discount < 0 || $sale < 0 || $percentage < 0 || $percentage > 100
                    || $public - $discount !== $sale) {
                    throw ValidationException::withMessages(['snapshots' => 'Precio o descuento inconsistente en el detalle ' . $detail->id . '.']);
                }
                DB::table('payment_product_order_details')->where('id', $detail->id)->update([
                    'public_unit_price_snapshot' => $this->decimal($public),
                    'discount_percentage' => number_format($percentage, 2, '.', ''),
                    'discount_amount' => $this->decimal($discount),
                    'sale_unit_price' => $this->decimal($sale),
                    'updated_at' => $now,
                ]);

                $usesLots = DB::table(self::DETAILS . ' as d')
                    ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
                    ->where('d.product_id', $detail->product_id)
                    ->whereIn('m.movement_type', ['INITIAL_ENTRY', 'PURCHASE_ENTRY'])
                    ->whereNotNull('d.lot_code')->exists();
                $parts = $allocations[$detail->id] ?? ($usesLots
                    ? $this->allocateAvailableLots($detail->product_id, (int) $detail->quantity)
                    : [['quantity' => (int) $detail->quantity]]);
                if (!is_array($parts) || $parts === []) {
                    throw ValidationException::withMessages(['allocations' => 'La distribucion por lote esta vacia.']);
                }
                $allocated = 0;
                foreach ($parts as $part) {
                    $quantity = (int) ($part['quantity'] ?? 0);
                    if ($quantity < 1) {
                        throw ValidationException::withMessages(['allocations' => 'Cantidad de salida invalida.']);
                    }
                    $hasLot = isset($part['lot_code']) && $part['lot_code'] !== '';
                    if ($usesLots !== $hasLot) {
                        throw ValidationException::withMessages(['allocations' => 'Indique el lote de cada salida de un producto con lotes.']);
                    }
                    if ($hasLot) {
                        $lotCode = $part['lot_code'];
                        $lotMovements = DB::table(self::DETAILS . ' as d')
                            ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
                            ->where('d.product_id', $detail->product_id)
                            ->where('d.lot_code', $lotCode)
                            ->whereIn('m.movement_type', [
                                'INITIAL_ENTRY', 'PURCHASE_ENTRY', 'RETURN_ENTRY',
                                'ADJUSTMENT_ENTRY', 'SALE_EXIT', 'ADJUSTMENT_EXIT', 'SALE_CANCELLATION',
                            ])
                            ->selectRaw("{$prefix}m.movement_type, SUM({$prefix}d.quantity) as quantity")
                            ->groupBy('m.movement_type')->pluck('quantity', 'movement_type');
                        $available = (int) ($lotMovements['INITIAL_ENTRY'] ?? 0)
                            + (int) ($lotMovements['PURCHASE_ENTRY'] ?? 0)
                            + (int) ($lotMovements['RETURN_ENTRY'] ?? 0)
                            + (int) ($lotMovements['ADJUSTMENT_ENTRY'] ?? 0)
                            + (int) ($lotMovements['SALE_CANCELLATION'] ?? 0)
                            - (int) ($lotMovements['SALE_EXIT'] ?? 0)
                            - (int) ($lotMovements['ADJUSTMENT_EXIT'] ?? 0);
                        if ($available < $quantity) {
                            throw ValidationException::withMessages(['allocations' => 'Stock insuficiente en el lote seleccionado.']);
                        }
                    }
                    $allocated += $quantity;
                    DB::table(self::DETAILS)->insert([
                        'inventory_movement_id' => $movementId,
                        'product_id' => $detail->product_id,
                        'payment_product_order_detail_id' => $detail->id,
                        'quantity' => $quantity,
                        'company_unit_cost' => $products[$detail->product_id]->company_unit_cost,
                        'additional_cost' => '0.00',
                        'lot_code' => $part['lot_code'] ?? null,
                        'expiration_at' => $part['expiration_at'] ?? null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                if ($allocated !== (int) $detail->quantity) {
                    throw ValidationException::withMessages(['allocations' => 'Las unidades por lote no coinciden con la compra.']);
                }
            }

            if (!$isReactivation) {
                foreach ($required as $productId => $quantity) {
                    DB::table('products')->where('id', $productId)->update([
                        'stock' => (int) $products[$productId]->stock - (int) $quantity,
                        'updated_at' => $now,
                    ]);
                }
            }

            return $movementId;
        });
    }

    /** Revierte el costo y los lotes; el flujo de reactivacion ya repone stock. */
    public function registerCancellation(string $orderId, bool $stockAlreadyRestored = false): ?int
    {
        return DB::transaction(function () use ($orderId, $stockAlreadyRestored) {
            $order = PaymentProductOrder::whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ((int) $order->state !== PaymentProductOrder::ANULADO) {
                throw ValidationException::withMessages(['order_id' => 'La compra aun no esta anulada.']);
            }

            $details = DB::table('payment_product_order_details')
                ->where('payment_product_order_id', $orderId)->get(['id', 'product_id']);
            $saleLines = DB::table(self::DETAILS . ' as d')
                ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
                ->whereIn('d.payment_product_order_detail_id', $details->pluck('id'))
                ->where('m.movement_type', 'SALE_EXIT')
                ->get(['d.payment_product_order_detail_id', 'd.product_id', 'd.quantity',
                    'd.company_unit_cost', 'd.additional_cost', 'd.lot_code', 'd.expiration_at']);
            if ($saleLines->isEmpty()) return null;

            $existing = DB::table(self::DETAILS . ' as d')
                ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
                ->whereIn('d.payment_product_order_detail_id', $details->pluck('id'))
                ->where('m.movement_type', 'SALE_CANCELLATION')->value('m.id');
            if ($existing !== null) return (int) $existing;

            $isReactivation = $stockAlreadyRestored || DB::table('manual_reactivations')
                ->where('payment_product_order_id', $orderId)->exists();
            $products = DB::table('products')->whereIn('id', $details->pluck('product_id')->unique())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $now = now();
            $movementId = DB::table(self::MOVEMENTS)->insertGetId([
                'movement_type' => 'SALE_CANCELLATION',
                'movement_date' => $now,
                'description' => 'Anulacion de compra ' . $orderId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($saleLines as $line) {
                DB::table(self::DETAILS)->insert([
                    'inventory_movement_id' => $movementId,
                    'product_id' => $line->product_id,
                    'payment_product_order_detail_id' => $line->payment_product_order_detail_id,
                    'quantity' => $line->quantity,
                    'company_unit_cost' => $line->company_unit_cost,
                    'additional_cost' => $line->additional_cost,
                    'lot_code' => $line->lot_code,
                    'expiration_at' => $line->expiration_at,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            if (!$isReactivation) {
                foreach ($saleLines->groupBy('product_id') as $productId => $lines) {
                    DB::table('products')->where('id', $productId)->update([
                        'stock' => (int) $products[$productId]->stock + $lines->sum('quantity'),
                        'updated_at' => $now,
                    ]);
                }
            }
            return $movementId;
        });
    }

    public function monthlyReport(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->utc();
        $to = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->endOfMonth()->utc();
        $prefix = DB::connection()->getTablePrefix();

        // Cada venta y su anulacion pertenecen al mes de su propio movimiento.
        // manual_reactivations.amount conserva el importe si la orden se puso a cero.
        $saleEvents = DB::table(self::DETAILS . ' as d')
            ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
            ->join('payment_product_order_details as od', 'od.id', '=', 'd.payment_product_order_detail_id')
            ->join('payment_product_orders as o', 'o.id', '=', 'od.payment_product_order_id')
            ->leftJoin('manual_reactivations as r', 'r.payment_product_order_id', '=', 'o.id')
            ->whereBetween('m.movement_date', [$from, $to])
            ->whereIn('m.movement_type', ['SALE_EXIT', 'SALE_CANCELLATION'])
            ->where('o.currency', 'PEN')
            ->selectRaw("{$prefix}m.id, {$prefix}m.movement_type, {$prefix}o.amount as order_amount,
                {$prefix}r.id as reactivation_id, {$prefix}r.amount as reactivation_amount,
                SUM({$prefix}d.total_cost) as cost,
                SUM({$prefix}d.quantity * {$prefix}od.public_unit_price_snapshot) as public_total")
            ->groupBy('m.id', 'm.movement_type', 'o.amount', 'r.id', 'r.amount')->get();

        $reactivationSales = $reactivationCost = $storeSales = $storeCost = $publicProductSales = 0;
        foreach ($saleEvents as $event) {
            $sign = $event->movement_type === 'SALE_CANCELLATION' ? -1 : 1;
            $amount = $this->cents($event->reactivation_amount ?? $event->order_amount);
            $cost = $this->cents($event->cost);
            $publicProductSales += $sign * $this->cents($event->public_total ?? 0);
            if ($event->reactivation_id !== null) {
                $reactivationSales += $sign * $amount;
                $reactivationCost += $sign * $cost;
            } else {
                $storeSales += $sign * $amount;
                $storeCost += $sign * $cost;
            }
        }
        $affiliationSales = $this->cents($this->affiliationOrders($from, $to)->sum('po.amount'));
        $sales = $reactivationSales + $storeSales + $affiliationSales;
        $grossPublicSales = $publicProductSales + $affiliationSales;
        $costOfSales = $reactivationCost + $storeCost;
        $purchases = $this->cents(DB::table(self::DETAILS . ' as d')
            ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
            ->whereBetween('m.movement_date', [$from, $to])
            ->where('m.movement_type', 'PURCHASE_ENTRY')->sum('d.total_cost'));
        $isClosedPeriod = sprintf('%04d-%02d', $year, $month) !== now('America/Lima')->format('Y-m');
        $commissions = app(FinancialLedgerService::class)->summary($from, $to, null, $isClosedPeriod);
        $generated = $this->cents($commissions['total_comisiones']);
        $hasPaymentTracking = Schema::hasColumn('collection_request_patrocinio_users', 'amount')
            && Schema::hasColumn('collection_request_patrocinio_users', 'paid_at');
        $paid = $this->cents(DB::table('collection_request_patrocinio_users')
            ->where('state', 2)
            ->whereBetween($hasPaymentTracking ? 'paid_at' : 'confirm', [$from, $to])
            ->sum($hasPaymentTracking ? 'amount' : 'points'));

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'currency' => 'PEN',
            'gross_public_sales' => $this->decimal($grossPublicSales),
            'price_adjustment' => $this->decimal($grossPublicSales - $sales),
            'sales_collected' => $this->decimal($sales),
            'cost_of_sales' => $this->decimal($costOfSales),
            'gross_profit' => $this->decimal($sales - $costOfSales),
            'reactivations' => [
                'sales' => $this->decimal($reactivationSales),
                'cost_of_sales' => $this->decimal($reactivationCost),
                'gross_profit' => $this->decimal($reactivationSales - $reactivationCost),
            ],
            'store' => [
                'sales' => $this->decimal($storeSales),
                'cost_of_sales' => $this->decimal($storeCost),
                'gross_profit' => $this->decimal($storeSales - $storeCost),
            ],
            'affiliation_packs' => [
                'sales' => $this->decimal($affiliationSales),
                'gross_profit_before_other_costs' => $this->decimal($affiliationSales),
            ],
            'commissions_generated' => [
                'sponsorship' => $commissions['patrocinio'],
                'residual' => $commissions['residual'],
                'infinity' => $commissions['infinito'],
                'total' => $this->decimal($generated),
            ],
            'profit_after_commissions' => $this->decimal($sales - $costOfSales - $generated),
            'merchandise_purchased' => $this->decimal($purchases),
            'commissions_paid' => $this->decimal($paid),
            'net_cash_flow' => $this->decimal($sales - $purchases - $paid),
        ];
    }

    /** Detalle de las mismas ordenes que forman el total mensual de packs. */
    public function affiliationPackDetails(int $year, int $month): array
    {
        $from = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->utc();
        $to = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->endOfMonth()->utc();
        $orders = $this->affiliationOrders($from, $to)
            ->join('packs as pack', 'pack.id', '=', 'po.pack_id')
            ->get(['po.id', 'po.amount', 'pack.title as pack']);
        $ids = $orders->pluck('id');
        if ($ids->isEmpty()) {
            return ['period' => sprintf('%04d-%02d', $year, $month), 'total' => '0.00', 'items' => []];
        }

        $buyers = DB::table('payment_logs as pl')
            ->join('users as u', 'u.id', '=', 'pl.user_id')
            ->whereIn('pl.payment_order_id', $ids)
            ->where('pl.confirm', true)->whereIn('pl.state', [2, 6, 7])
            ->orderBy('pl.created_at')->orderBy('pl.id')
            ->get(['pl.payment_order_id', 'u.name as buyer'])
            ->unique('payment_order_id')->keyBy('payment_order_id');
        $dates = DB::table('payment_order_points as p')
            ->whereIn('p.payment_order_id', $ids)
            ->where('p.type', 'B')->whereBetween('p.created_at', [$from, $to])
            ->orderBy('p.created_at')->orderBy('p.id')
            ->get(['p.payment_order_id', 'p.created_at'])
            ->unique('payment_order_id')->keyBy('payment_order_id');

        $items = $orders->map(function ($order) use ($buyers, $dates) {
            return [
                'date' => Carbon::parse($dates[$order->id]->created_at, 'UTC')
                    ->setTimezone('America/Lima')->toDateTimeString(),
                'buyer' => $buyers[$order->id]->buyer,
                'pack' => $order->pack,
                'amount' => $this->decimal($this->cents($order->amount)),
            ];
        })->sortByDesc('date')->values()->all();

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'total' => $this->decimal($orders->sum(fn ($order) => $this->cents($order->amount))),
            'items' => $items,
        ];
    }

    /** Ventas por producto con los mismos movimientos y montos del reporte mensual. */
    public function productSalesDetails(int $year, int $month, string $source): array
    {
        $from = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->utc();
        $to = Carbon::create($year, $month, 1, 0, 0, 0, 'America/Lima')->endOfMonth()->utc();
        $rows = DB::table(self::DETAILS . ' as d')
            ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
            ->join('payment_product_order_details as od', 'od.id', '=', 'd.payment_product_order_detail_id')
            ->join('payment_product_orders as o', 'o.id', '=', 'od.payment_product_order_id')
            ->join('users as u', 'u.id', '=', 'o.user_id')
            ->leftJoin('manual_reactivations as r', 'r.payment_product_order_id', '=', 'o.id')
            ->whereBetween('m.movement_date', [$from, $to])
            ->whereIn('m.movement_type', ['SALE_EXIT', 'SALE_CANCELLATION'])
            ->where('o.currency', 'PEN')
            ->when($source === 'reactivations', fn ($query) => $query->whereNotNull('r.id'),
                fn ($query) => $query->whereNull('r.id'))
            ->orderByDesc('m.movement_date')->orderByDesc('m.id')->orderBy('d.id')
            ->get([
                'm.id as movement_id', 'm.movement_type', 'm.movement_date',
                'o.id as order_id', 'o.amount as order_amount', 'r.amount as reactivation_amount',
                'u.name as buyer', 'od.id as order_detail_id', 'od.product_title as product',
                'od.sale_unit_price', 'od.price as detail_price',
                'd.quantity', 'd.total_cost',
            ]);

        $orders = $rows->groupBy('movement_id')->map(function ($movementRows) {
            $first = $movementRows->first();
            $sign = $first->movement_type === 'SALE_CANCELLATION' ? -1 : 1;
            $amount = $this->cents($first->reactivation_amount ?? $first->order_amount);
            $parts = $movementRows->groupBy('order_detail_id')->values();
            $weights = $parts->map(function ($part) {
                $row = $part->first();
                $unitPrice = $row->sale_unit_price ?? $row->detail_price;
                return max(0, $this->cents($unitPrice) * (int) $part->sum('quantity'));
            });
            $totalWeight = $weights->sum();
            if ($totalWeight === 0) {
                $weights = $parts->map(fn ($part) => (int) $part->sum('quantity'));
                $totalWeight = $weights->sum();
            }

            $allocated = 0;
            $items = [];
            foreach ($parts as $index => $part) {
                $line = $part->first();
                $lineAmount = $index === $parts->count() - 1
                    ? $amount - $allocated
                    : (int) floor($amount * $weights[$index] / $totalWeight);
                $allocated += $lineAmount;
                $lineCost = $part->sum(fn ($row) => $this->cents($row->total_cost));
                $items[] = [
                    'product' => $line->product,
                    'quantity' => (int) $part->sum('quantity'),
                    'sales' => $this->decimal($sign * $lineAmount),
                    'cost_of_sales' => $this->decimal($sign * $lineCost),
                    'gross_profit' => $this->decimal($sign * ($lineAmount - $lineCost)),
                ];
            }

            $cost = $movementRows->sum(fn ($row) => $this->cents($row->total_cost));
            return [
                'order_id' => $first->order_id,
                'movement_id' => (int) $first->movement_id,
                'movement_type' => $first->movement_type,
                'date' => Carbon::parse($first->movement_date, 'UTC')
                    ->setTimezone('America/Lima')->toDateTimeString(),
                'buyer' => $first->buyer,
                'sales' => $this->decimal($sign * $amount),
                'cost_of_sales' => $this->decimal($sign * $cost),
                'gross_profit' => $this->decimal($sign * ($amount - $cost)),
                'items' => $items,
            ];
        })->values()->all();

        return [
            'period' => sprintf('%04d-%02d', $year, $month),
            'source' => $source,
            'orders' => $orders,
        ];
    }

    private function affiliationOrders(Carbon $from, Carbon $to): Builder
    {
        // Solo las ordenes con importe registrado representan ventas. Las
        // antiguas asignaciones administrativas de importe cero no prueban cobro.
        // La fila B fecha la activacion y evita contar referencias mensuales.
        return DB::table('payment_orders as po')
            ->where('po.currency', 'PEN')->where('po.amount', '>', 0)
            ->whereExists(function ($query) {
                $query->selectRaw('1')->from('payment_logs as pl')
                    ->whereColumn('pl.payment_order_id', 'po.id')
                    ->where('pl.confirm', true)->whereIn('pl.state', [2, 6, 7]);
            })
            ->whereExists(function ($query) use ($from, $to) {
                $query->selectRaw('1')->from('payment_order_points as p')
                    ->whereColumn('p.payment_order_id', 'po.id')
                    ->where('p.type', 'B')->whereBetween('p.created_at', [$from, $to]);
            });
    }

    private function cents(int|float|string $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    /** Selecciona lotes con saldo, por fecha de entrada. No guarda otro stock. */
    private function allocateAvailableLots(string $productId, int $quantity): array
    {
        $prefix = DB::connection()->getTablePrefix();
        $lots = DB::table(self::DETAILS . ' as d')
            ->join(self::MOVEMENTS . ' as m', 'm.id', '=', 'd.inventory_movement_id')
            ->where('d.product_id', $productId)
            ->whereNotNull('d.lot_code')
            ->whereIn('m.movement_type', [
                'INITIAL_ENTRY', 'PURCHASE_ENTRY', 'RETURN_ENTRY', 'ADJUSTMENT_ENTRY',
                'SALE_EXIT', 'ADJUSTMENT_EXIT', 'SALE_CANCELLATION',
            ])
            ->selectRaw("{$prefix}d.lot_code, MIN({$prefix}m.movement_date) as first_entry,
                SUM(CASE WHEN {$prefix}m.movement_type IN ('INITIAL_ENTRY', 'PURCHASE_ENTRY', 'RETURN_ENTRY',
                    'ADJUSTMENT_ENTRY', 'SALE_CANCELLATION') THEN {$prefix}d.quantity
                    ELSE -{$prefix}d.quantity END) as available")
            ->groupBy('d.lot_code')->orderBy('first_entry')->get();

        $selected = [];
        foreach ($lots as $lot) {
            $available = max(0, (int) $lot->available);
            if ($available === 0) continue;
            $take = min($quantity, $available);
            $selected[] = ['quantity' => $take, 'lot_code' => $lot->lot_code];
            $quantity -= $take;
            if ($quantity === 0) return $selected;
        }

        throw ValidationException::withMessages(['allocations' => 'No hay suficientes unidades disponibles en los lotes.']);
    }
}
