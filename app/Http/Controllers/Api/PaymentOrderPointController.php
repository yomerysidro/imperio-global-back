<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\PaymentOrder;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\PaymentLog;
use App\Models\PaymentProductOrder;
use App\Models\PaymentOrderPoint;
use App\Models\PaymentProductOrderPoint;
use App\Services\Core\Calculator;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Exception;

class PaymentOrderPointController extends BaseController
{
    private $calculator;

    public function __construct()
    {
        $this->calculator = new Calculator();
    }
 /**
     * Get points for the authenticated user
     */
    public function points(Request $request)
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Usuario no autenticado'
                ], 401);
            }

            // Aquí tu lógica para obtener los puntos
            $data = [
                'points' => 1150,
                'groupPoints' => 0,
                'totalPoints' => 1150,
                'patrocinio' => 0,
                'resudial' => 0,
                'infinity' => 0,
                // ... otros datos
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    public function pointsUser()
    {
        try {
            $user_id = Auth::id();
            $userModel = User::with(['file', 'range.range.file'])->find($user_id);
            if (!$userModel) return $this->sendError("Usuario no encontrado");

            // --- LÓGICA DE TIEMPO Y PERIODO DE GRACIA ---
            $now = Carbon::now();
            $currentMonth = $now->month;
            $currentYear = $now->year;
            $mesAnterior = $now->copy()->subMonth();
            $isGracePeriod = $now->day <= 2;

            $servicePayment = PaymentLog::with(['paymentOrder.pack'])
                ->where("user_id", $user_id)->whereIn('state', [PaymentLog::PAGADO, 6])->orderBy('created_at', 'desc')->first();
            $productPayment = PaymentProductOrder::with(['pack'])
                ->where("user_id", $user_id)->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, 6])->orderBy('created_at', 'desc')->first();

            $ultimoPago = collect([$servicePayment, $productPayment])->filter()->sortByDesc(function ($p) {
                return Carbon::parse($p->created_at)->timestamp;
            })->first();

            $isActive = false;
            $mesFiltro = $currentMonth;
            $anioFiltro = $currentYear;
            $estadoVisual = 'INACTIVO'; // Para el frontend

            if ($ultimoPago) {
                $fechaPago = Carbon::parse($ultimoPago->created_at);
                if ($fechaPago->month == $currentMonth && $fechaPago->year == $currentYear) {
                    // Pagó ESTE mes -> ACTIVO
                    $isActive = true;
                    $estadoVisual = 'ACTIVO';
                } elseif ($fechaPago->month == $mesAnterior->month && $fechaPago->year == $mesAnterior->year) {
                    if ($isGracePeriod) {
                        // Periodo de gracia: puede pagar, pero está INACTIVO
                        $isActive = false;
                        $estadoVisual = 'INACTIVO';
                        $mesFiltro = $mesAnterior->month;
                        $anioFiltro = $mesAnterior->year;
                    }
                }
            }

            // Sincronizar estado en BD para consistencia
            if ($ultimoPago) {
                $nuevoEstado = $isActive ? PaymentLog::PAGADO : PaymentLog::TERMINADO;

                if ($ultimoPago instanceof PaymentLog) {
                    PaymentLog::where('id', $ultimoPago->id)->update(['state' => $nuevoEstado]);
                    $ultimoPago->state = $nuevoEstado;
                } elseif ($ultimoPago instanceof PaymentProductOrder) {
                    PaymentProductOrder::where('id', $ultimoPago->id)->update(['state' => $nuevoEstado]);
                    $ultimoPago->state = $nuevoEstado;
                }
            }

            $userModel->payment = $ultimoPago;
            $userModel->active = $isActive;
            $userModel->estado_visual = $estadoVisual;
            // -----------------------------------------------------------

            // OBTENEMOS A TODA LA RED SIN IMPORTAR EL MES (para mostrar árbol)
            $allDownlineCodes = $this->getRecursiveDownline([$userModel->uuid]);

            $rawNetworkPoints = PaymentOrderPoint::with(['userPoint.file', 'userPoint.paymentActive', 'sponsor'])
                ->whereIn('user_code', $allDownlineCodes)
                ->where('payment', 1)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->orderBy('created_at', 'desc')
                ->get();

            $networkPoints = $rawNetworkPoints->unique('user_code')->values();

            // OBTENEMOS LOS PUNTOS ESTRICTAMENTE DEL MES DE EVALUACIÓN
            $allPointsTable = PaymentOrderPoint::where('state', true)
                ->whereMonth('created_at', $mesFiltro)->whereYear('created_at', $anioFiltro)->get();
            $allProductPoints = PaymentProductOrderPoint::where('state', true)
                ->whereMonth('created_at', $mesFiltro)->whereYear('created_at', $anioFiltro)->get();

            // COMISIONES HISTÓRICAS ACUMULADAS (Patrocinio + Residual de TODOS los meses)
            $historicalCommissions = PaymentOrderPoint::where('state', true)
                ->where('sponsor_code', $userModel->uuid)
                ->whereIn('type', [
                    PaymentOrderPoint::PATROCINIO,
                    PaymentOrderPoint::PATROCINIO_SERVICIO,
                    PaymentOrderPoint::RESIDUAL,
                    PaymentOrderPoint::RESIDUAL_SERVICIO
                ])
                ->get();

            // Combinar puntos del mes + comisiones históricas
            $combinedPoints = $allPointsTable->merge($historicalCommissions);

            // TRANSFORMACIÓN DE NODOS
            $networkPoints->transform(function ($item) use ($allPointsTable, $allProductPoints, $currentMonth, $currentYear) {
                $item->user = $item->userPoint;

                // Verificar si está activo en el ciclo actual
                $hasBoughtThisCycle = PaymentOrderPoint::where('user_code', $item->user_code)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->where('payment', 1)
                    ->where('state', true)
                    ->whereMonth('created_at', $currentMonth)
                    ->whereYear('created_at', $currentYear)
                    ->exists();

                $item->active = $hasBoughtThisCycle;
                $item->state = $hasBoughtThisCycle ? PaymentLog::PAGADO : PaymentLog::TERMINADO;

                if ($item->userPoint) {
                    $item->points = $this->calculator->points($item->user_code, $allPointsTable, $allProductPoints);
                    $item->totalPoints = $this->calculator->pointsTotal($item->user_code, $allPointsTable, $allProductPoints);
                }
                return $item;
            });

            // BUSCAR PATROCINADOR REAL
            $myActivation = PaymentOrderPoint::with('sponsor')
                ->where('user_code', $userModel->uuid)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->first();

            if ($myActivation && $myActivation->sponsor) {
                $userModel->sponsor_name = $myActivation->sponsor->name;
                $userModel->sponsor_uuid = $myActivation->sponsor_code;
            } else {
                $userModel->sponsor_name = "Sistema";
                $userModel->sponsor_uuid = "--";
            }

            // CONTADORES DEL ÁRBOL
            $userModel->directos = $networkPoints->where('sponsor_code', $userModel->uuid)->count();
            $userModel->activos = $networkPoints->where('active', true)->count();
            $userModel->red_total = $networkPoints->count();

            // TUS PUNTOS: Si estás inactivo, TODO en 0 excepto comisiones históricas
            if (!$isActive) {
                $userModel->points = (object) [
                    "patrocinio" => 0,
                    "residual" => 0,
                    "compra" => (object) ["total_puntos" => 0, "total_gastado" => 0],
                    "pointGroup" => 0,
                    "personal" => 0,
                    "infinito" => 0,
                    "pointAfiliado" => 0,
                    "personalGlobal" => 0,
                    "patrocinioRequest" => 0,
                    "patrocinioServicio" => 0,
                    "residualServicio" => 0
                ];
                $userModel->totalPoints = 0;
            } else {
                $userModel->points = $this->calculator->points($userModel->uuid, $combinedPoints, $allProductPoints);
                $userModel->totalPoints = $this->calculator->pointsTotal($userModel->uuid, $combinedPoints, $allProductPoints);
            }

            return $this->sendResponse(["points" => $networkPoints, "user" => $userModel], 'Éxito');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * Trae recursivamente TODOS los códigos de la red histórica sin filtro de mes
     */
    private function getRecursiveDownline($uuids, $all = [])
    {
        $children = PaymentOrderPoint::whereIn('sponsor_code', $uuids)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->where('payment', 1)
            ->pluck('user_code')
            ->toArray();

        if (empty($children)) return [];

        $nextLevel = $this->getRecursiveDownline($children);
        return array_unique(array_merge($children, $nextLevel));
    }
}
