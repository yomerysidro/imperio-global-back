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
     * Obtiene TODOS los puntos (P, R, G, B, etc.) del mes solicitado.
     * con sus relaciones (user_point, payment_order, etc.).
     * Esto es lo que espera el Frontend en finance.component.ts
     * Ahora acepta el filtro por mes y año desde el Request.
     */
    public function points(Request $request)
    {
        try {
            $now = Carbon::now();

            // Tomar los parámetros del request, o usar el mes actual por defecto
            $month = $request->query('month', $now->month);
            $year = $request->query('year', $now->year);

            // Validación básica de parámetros
            if (!is_numeric($month) || $month < 1 || $month > 12) {
                $month = $now->month;
            }
            if (!is_numeric($year) || $year < 2000) {
                $year = $now->year;
            }

            // --- OBTENER TODOS LOS PUNTOS DEL MES FILTRADO (SIN DATOS HISTÓRICOS) ---
            $paymentOrderPoints = PaymentOrderPoint::with([
                    'paymentOrder.paymentLog', 
                    'userPoint.paymentActive'
                ])
                ->where('state', true)
                ->whereMonth('created_at', $month)
                ->whereYear('created_at', $year)
                ->orderBy('created_at', 'desc')
                ->get();

            // Devolvemos el array completo tal como lo espera el Frontend
            // Si el mes no tiene datos, devolverá un array vacío y el front sumará 0.
            return $this->sendResponse($paymentOrderPoints, 'Lista de puntos del ciclo seleccionado');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    /**
     * Get points for the authenticated user's tree (Dashboard particular)
     * Esta función NO se usa para el módulo de Finanzas globales del Admin.
     * Se mantiene intacta sin eliminar funcionalidades.
     */
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