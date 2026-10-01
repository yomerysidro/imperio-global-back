<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController;
use App\Services\Core\FinanceIGService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

/** Controller exclusivo de finanzas de la empresa. No modifica comisiones. */
class FinanceIGController extends BaseController
{
    public function __construct(private readonly FinanceIGService $finance) {}

    public function inventoryMovements(Request $request)
    {
        if (!$this->isAdmin()) return $this->sendError('Solo administradores.', [], 403);

        $validator = Validator::make($request->all(), [
            'year' => 'nullable|required_with:month|integer|between:2000,2100',
            'month' => 'nullable|required_with:year|integer|between:1,12',
            'direction' => 'nullable|in:entry,exit',
            'movement_type' => 'nullable|in:INITIAL_ENTRY,PURCHASE_ENTRY,SALE_EXIT,SALE_CANCELLATION,RETURN_ENTRY,ADJUSTMENT_ENTRY,ADJUSTMENT_EXIT',
            'product_id' => 'nullable|uuid',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|between:1,100',
        ]);
        if ($validator->fails()) return $this->sendError('Filtros invalidos.', $validator->errors(), 422);

        return $this->sendResponse(
            $this->finance->inventoryMovements($validator->validated()),
            'Movimientos de inventario.'
        );
    }

    public function registerEntry(Request $request)
    {
        if (!$this->isAdmin()) return $this->sendError('Solo administradores.', [], 403);

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:INITIAL_ENTRY,PURCHASE_ENTRY',
            'movement_date' => 'nullable|date',
            'description' => 'nullable|string|max:500',
            'lines' => 'required|array|min:1',
            'lines.*.product_id' => 'required|uuid',
            'lines.*.quantity' => 'required|integer|min:1',
            'lines.*.company_unit_cost' => 'required|numeric|min:0',
            'lines.*.additional_cost' => 'nullable|numeric|min:0',
            'lines.*.lot_code' => 'nullable|string|max:100',
            'lines.*.expiration_at' => 'nullable|date',
        ]);
        if ($validator->fails()) return $this->sendError('Datos invalidos.', $validator->errors(), 422);

        $data = $validator->validated();
        $id = $this->finance->registerEntry(
            $data['type'], $data['lines'], $data['movement_date'] ?? null, $data['description'] ?? null
        );
        return $this->sendResponse(['movement_id' => $id], 'Entrada registrada.');
    }

    public function registerSale(Request $request)
    {
        if (!$this->isAdmin()) return $this->sendError('Solo administradores.', [], 403);

        $validator = Validator::make($request->all(), [
            'order_id' => 'required|uuid',
            'allocations' => 'nullable|array',
            'allocations.*' => 'array|min:1',
            'allocations.*.*.quantity' => 'required|integer|min:1',
            'allocations.*.*.lot_code' => 'nullable|string|max:100',
            'allocations.*.*.expiration_at' => 'nullable|date',
            'snapshots' => 'nullable|array',
            'snapshots.*.public_unit_price_snapshot' => 'required|numeric|min:0',
            'snapshots.*.discount_percentage' => 'required|numeric|between:0,100',
            'snapshots.*.discount_amount' => 'required|numeric|min:0',
            'snapshots.*.sale_unit_price' => 'required|numeric|min:0',
        ]);
        if ($validator->fails()) return $this->sendError('Datos invalidos.', $validator->errors(), 422);

        $data = $validator->validated();
        $id = $this->finance->registerSale($data['order_id'], $data['allocations'] ?? [], $data['snapshots'] ?? []);
        return $this->sendResponse(['movement_id' => $id], 'Salida registrada.');
    }

    public function registerCancellation(Request $request)
    {
        if (!$this->isAdmin()) return $this->sendError('Solo administradores.', [], 403);
        $validator = Validator::make($request->all(), ['order_id' => 'required|uuid']);
        if ($validator->fails()) return $this->sendError('Datos invalidos.', $validator->errors(), 422);

        $id = $this->finance->registerCancellation($request->input('order_id'));
        return $this->sendResponse(['movement_id' => $id], 'Anulacion registrada.');
    }

    public function monthlyReport(Request $request)
    {
        if (!$this->isAdmin()) return $this->sendError('Solo administradores.', [], 403);

        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|between:2000,2100',
            'month' => 'required|integer|between:1,12',
        ]);
        if ($validator->fails()) return $this->sendError('Periodo invalido.', $validator->errors(), 422);

        return $this->sendResponse(
            $this->finance->monthlyReport((int) $request->year, (int) $request->month),
            'Reporte financiero mensual.'
        );
    }

    public function affiliationPacks(Request $request)
    {
        if (!$this->isAdmin()) return $this->sendError('Solo administradores.', [], 403);

        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|between:2000,2100',
            'month' => 'required|integer|between:1,12',
        ]);
        if ($validator->fails()) return $this->sendError('Periodo invalido.', $validator->errors(), 422);

        return $this->sendResponse(
            $this->finance->affiliationPackDetails((int) $request->year, (int) $request->month),
            'Detalle de packs de afiliacion.'
        );
    }

    public function productSalesDetails(Request $request)
    {
        if (!$this->isAdmin()) return $this->sendError('Solo administradores.', [], 403);

        $validator = Validator::make($request->all(), [
            'year' => 'required|integer|between:2000,2100',
            'month' => 'required|integer|between:1,12',
            'source' => 'required|in:store,reactivations',
        ]);
        if ($validator->fails()) return $this->sendError('Filtros invalidos.', $validator->errors(), 422);

        return $this->sendResponse(
            $this->finance->productSalesDetails((int) $request->year, (int) $request->month, $request->source),
            'Detalle de ventas por producto.'
        );
    }

    private function isAdmin(): bool
    {
        return (bool) Auth::guard('api')->user()?->is_admin;
    }
}
