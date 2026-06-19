<?php

namespace App\Http\Controllers\Api\Services;

use App\Http\Controllers\BaseController;
use App\Models\Pack;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint; // Importamos el modelo de puntos
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Services\Core\Services\ServiceTreeManager;

class ServiceOrderController extends BaseController
{
    public function buyService(Request $request)
    {
        $request->validate([
            'pack_id' => 'required|exists:packs,id'
        ]);

        $user = Auth::user();
        $pack = Pack::find($request->pack_id);

        DB::beginTransaction();
        try {
            // BUSCAR EL SPONSOR ORIGINAL
            $originalOrder = PaymentOrder::whereHas('paymentLog', function ($q) use ($user) {
                $q->where('user_id', $user->id)->where('state', 2);
            })->first();

            if (!$originalOrder) {
                return $this->sendError("El usuario debe tener un árbol de productos activo primero.");
            }

            // 1. Crear la Orden de Servicio
            $order = PaymentOrder::create([
                'currency' => 'PEN',
                'amount' => $pack->price,
                'sponsor_code' => $originalOrder->sponsor_code,
                'pack_id' => $pack->id,
                'token' => 'SERVICE-' . uniqid()
            ]);

            // 2. Crear Log de Pago
            PaymentLog::create([
                'payment_order_id' => $order->id,
                'user_id' => $user->id,
                'state' => 2, // PAGADO
                'confirm' => 1
            ]);

            // --- ESTO ES LO QUE FALTABA PARA EL TOTAL ---
            // 2.5 Registrar los puntos como COMPRA PERSONAL para el usuario que compra
            // Esto hará que $calculator->compra aumente y por tanto el totalPoints también.
            PaymentOrderPoint::create([
                'payment_order_id' => $order->id,
                'user_code' => $user->uuid,
                'sponsor_code' => $originalOrder->sponsor_code,
                'point' => $pack->points, // Aquí van los 80 puntos
                'payment' => true,
                'type' => PaymentOrderPoint::COMPRA, // Usamos el tipo 'B' existente
                'user_id' => $user->id,
                'state' => true
            ]);

            // 3. DISTRIBUIR PUNTOS A LOS PATROCINADORES (Hacia arriba)
            $treeManager = new ServiceTreeManager();
            $treeManager->distributePoints($user->uuid, $order->id, $pack->points);

            DB::commit();
            return $this->sendResponse($order, 'Paquete de servicio activado exitosamente.');
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage());
        }
    }
}