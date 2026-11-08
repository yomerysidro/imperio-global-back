<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\BaseController as BaseController;
use App\Models\PaymentOrder;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\PaymentLog;
use App\Models\PaymentProductOrder;
use App\Models\PaymentOrderPoint;
use App\Models\SponsorRelation;
use App\Models\PaymentProductOrderPoint;
use App\Services\Core\Calculator;
use App\Services\Core\NetworkTreeService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Exception;

class PaymentOrderPointController extends BaseController
{
    private $calculator;
    private $networkTreeService;

    public function __construct()
    {
        $this->calculator = new Calculator();
        $this->networkTreeService = new NetworkTreeService();
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
            $now = Carbon::now('America/Lima');
            $activation = app(\App\Services\Core\ActivationService::class);
            $isGracePeriod = $activation->isMonthlyGracePeriod($now);
            $requestedCurrentPeriod = (int) $request->query('month', $now->month) === $now->month
                && (int) $request->query('year', $now->year) === $now->year;
            // El frontend envía el mes calendario actual aun durante la
            // gracia. En esos dos días esa selección representa el ciclo
            // visible anterior, no el mes nuevo todavía vacío.
            $usingGracePeriod = $isGracePeriod && (
                (!$request->has('month') && !$request->has('year')) || $requestedCurrentPeriod
            );
            $defaultPeriod = $usingGracePeriod ? $now->copy()->subMonthNoOverflow() : $now;

            // Tomar los parámetros del request, o usar el mes actual por defecto
            $month = $request->query('month', $defaultPeriod->month);
            $year = $request->query('year', $defaultPeriod->year);

            // Validación básica de parámetros
            if (!is_numeric($month) || $month < 1 || $month > 12) {
                $month = $now->month;
            }
            if (!is_numeric($year) || $year < 2000) {
                $year = $now->year;
            }
            if ($usingGracePeriod) {
                $month = $defaultPeriod->month;
                $year = $defaultPeriod->year;
            }

            // --- OBTENER TODOS LOS PUNTOS DEL MES FILTRADO (SIN DATOS HISTÓRICOS) ---
            $paymentOrderPoints = PaymentOrderPoint::with([
                    'paymentOrder.paymentLog', 
                    'userPoint.paymentActive'
                ])
                ->when(!$usingGracePeriod, fn ($query) => $query->where('state', true))
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
            $now = Carbon::now('America/Lima');
            $currentMonth = $now->month;
            $currentYear = $now->year;
            $mesAnterior = $now->copy()->subMonth();
            $activation = app(\App\Services\Core\ActivationService::class);
            $isGracePeriod = $activation->isMonthlyGracePeriod($now);

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
                        // El ciclo anterior conserva su actividad durante la gracia.
                        $isActive = true;
                        $estadoVisual = 'ACTIVO';
                        $mesFiltro = $mesAnterior->month;
                        $anioFiltro = $mesAnterior->year;
                    }
                }
            }

            $userModel->payment = $ultimoPago;
            $userModel->active = $isActive;
            $userModel->estado_visual = $estadoVisual;
            // -----------------------------------------------------------

            // OBTENEMOS A TODA LA RED SIN IMPORTAR EL MES (para mostrar árbol)
            $allDownlineCodes = array_values(array_filter(
                $this->networkTreeService->getAllNetworkUsers($userModel->uuid),
                fn ($code) => strcasecmp($code, $userModel->uuid) !== 0
            ));
            $currentParents = SponsorRelation::whereIn('user_code', $allDownlineCodes)
                ->where('state', true)->pluck('sponsor_code', 'user_code');
            $parentByChild = [];
            foreach ($currentParents as $childCode => $parentCode) {
                $parentByChild[strtoupper((string) $childCode)] = $parentCode;
            }

            $rawNetworkPoints = PaymentOrderPoint::with(['userPoint.file', 'userPoint.paymentActive', 'sponsor'])
                ->whereIn('user_code', $allDownlineCodes)
                ->where('payment', 1)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->orderBy('created_at', 'desc')
                ->get();

            $networkPoints = $rawNetworkPoints->unique('user_code')->values();

            // Una relación genealógica puede existir aunque el movimiento B
            // histórico falte o haya sido desactivado. Esos socios también
            // pertenecen al árbol y deben aparecer como nodos sin puntos.
            $codesWithPoint = $networkPoints->pluck('user_code')
                ->map(fn ($code) => strtoupper($code))->all();
            $missingCodes = array_values(array_filter(
                $allDownlineCodes,
                fn ($code) => !in_array(strtoupper($code), $codesWithPoint, true)
            ));
            $missingUsers = User::with('file')->whereIn('uuid', $missingCodes)->get()->keyBy(
                fn ($user) => strtoupper($user->uuid)
            );
            foreach ($missingCodes as $missingCode) {
                $placeholder = new PaymentOrderPoint();
                $placeholder->user_code = $missingCode;
                $placeholder->sponsor_code = $this->networkTreeService->sponsorCode($missingCode);
                $placeholder->type = PaymentOrderPoint::COMPRA;
                $placeholder->point = 0;
                $placeholder->payment = 0;
                $placeholder->state = 0;
                $placeholder->setRelation('userPoint', $missingUsers->get(strtoupper($missingCode)));
                $networkPoints->push($placeholder);
            }

            // OBTENEMOS LOS PUNTOS ESTRICTAMENTE DEL MES DE EVALUACIÓN
            $allPointsTable = PaymentOrderPoint::query()
                ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
                ->whereMonth('created_at', $mesFiltro)->whereYear('created_at', $anioFiltro)->get();
            $allProductPoints = PaymentProductOrderPoint::query()
                ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
                ->whereMonth('created_at', $mesFiltro)->whereYear('created_at', $anioFiltro)->get();

            // COMISIONES HISTÓRICAS ACUMULADAS (Patrocinio + Residual de TODOS los meses)
            $historicalCommissions = PaymentOrderPoint::where('state', true)
                ->where('user_code', $userModel->uuid)
                ->whereIn('type', [
                    PaymentOrderPoint::PATROCINIO,
                    PaymentOrderPoint::PATROCINIO_SERVICIO,
                    PaymentOrderPoint::RESIDUAL,
                    PaymentOrderPoint::RESIDUAL_SERVICIO,
                    PaymentOrderPoint::INFINITO
                ])
                ->get();

            // Combinar puntos del mes + comisiones históricas
            $combinedPoints = $allPointsTable->merge($historicalCommissions);

            // TRANSFORMACIÓN DE NODOS
            $networkPoints->transform(function ($item) use ($allPointsTable, $allProductPoints, $activation, $parentByChild) {
                $item->user = $item->userPoint;
                // Financial point rows retain their historical sponsor snapshot.
                // Tree edges always come from the current active genealogy.
                $item->sponsor_code = $parentByChild[strtoupper((string) $item->user_code)] ?? null;
                $item->parent_uuid = $item->sponsor_code;

                // Verificar si está activo en el ciclo actual
                $hasBoughtThisCycle = $item->userPoint
                    ? $activation->isActive($item->userPoint)
                    : false;

                $item->active = $hasBoughtThisCycle;
                $item->state = $hasBoughtThisCycle ? PaymentLog::PAGADO : PaymentLog::TERMINADO;

                if ($item->userPoint) {
                    [$from] = $activation->visiblePeriod();
                    $includeClosed = $activation->isMonthlyGracePeriod();
                    $item->points = $this->calculator->points(
                        $item->user_code, $allPointsTable, $allProductPoints,
                        $from->month, $from->year, $includeClosed
                    );
                    $item->totalPoints = $this->calculator->pointsTotal(
                        $item->user_code, $allPointsTable, $allProductPoints,
                        $from->month, $from->year, $includeClosed
                    );
                }
                return $item;
            });

            // La genealogia existe desde el registro, incluso antes de que el
            // socio realice su primera compra. sponsor_relations es la fuente
            // actual y el servicio conserva el respaldo historico.
            $sponsorCode = $this->networkTreeService->sponsorCode($userModel->uuid);
            $sponsor = $sponsorCode
                ? User::whereRaw('UPPER(uuid) = ?', [strtoupper($sponsorCode)])->first()
                : null;

            if ($sponsor) {
                $userModel->sponsor_name = $sponsor->name;
                $userModel->sponsor_uuid = $sponsor->uuid;
            } else {
                $userModel->sponsor_name = "Sistema";
                $userModel->sponsor_uuid = "--";
            }

            // CONTADORES DEL ÁRBOL
            $userModel->directos = count($this->networkTreeService->directUserCodes($userModel->uuid));
            $networkMetrics = app(\App\Services\Core\NetworkActivityMetrics::class)->forUser($userModel);
            $userModel->activos = $networkMetrics['total_active'];
            $userModel->red_total = $this->networkTreeService->countTotalNetworkRecursive($userModel->uuid);
            $userModel->personas_red = $userModel->red_total;

            // TUS PUNTOS: Si estás inactivo, TODO en 0 excepto comisiones históricas
            if (!$isActive) {
                $historical = $this->calculator->points($userModel->uuid, $historicalCommissions, collect());
                $userModel->points = (object) [
                    "patrocinio" => $historical->patrocinio,
                    "bono" => $historical->patrocinio,
                    "residual" => $historical->residual,
                    "compra" => (object) ["total_puntos" => 0, "total_gastado" => 0],
                    "pointGroup" => 0,
                    "personal" => 0,
                    "infinito" => $historical->infinito,
                    "bono_total" => $historical->bono_total,
                    "total_comisiones" => $historical->total_comisiones,
                    "pointAfiliado" => 0,
                    "personalGlobal" => 0,
                    "patrocinioRequest" => 0,
                    "patrocinioServicio" => 0,
                    "residualServicio" => 0
                ];
                $userModel->totalPoints = 0;
            } else {
                $userModel->points = $this->calculator->points(
                    $userModel->uuid, $combinedPoints, $allProductPoints,
                    $mesFiltro, $anioFiltro, $isGracePeriod
                );
                $userModel->totalPoints = $this->calculator->pointsTotal(
                    $userModel->uuid, $combinedPoints, $allProductPoints,
                    $mesFiltro, $anioFiltro, $isGracePeriod
                );
            }

            $pointsByCode = $networkPoints->keyBy(fn ($item) => strtoupper((string) $item->user_code));
            $childrenByParent = [];
            foreach ($parentByChild as $childCode => $parentCode) {
                $childrenByParent[strtoupper((string) $parentCode)][] = $childCode;
            }
            $buildNode = function (string $code, array $path = []) use (&$buildNode, $pointsByCode, $childrenByParent): ?array {
                $key = strtoupper($code);
                if (isset($path[$key])) return null;
                $item = $pointsByCode->get($key);
                if (!$item) return null;
                $path[$key] = true;
                $node = $item->toArray();
                $node['uuid'] = $item->user_code;
                $node['parent_uuid'] = $item->sponsor_code;
                $node['children'] = [];
                foreach ($childrenByParent[$key] ?? [] as $childCode) {
                    $childNode = $buildNode($childCode, $path);
                    if ($childNode !== null) $node['children'][] = $childNode;
                }
                $node['has_children'] = count($node['children']) > 0;
                return $node;
            };
            $root = [
                'uuid' => $userModel->uuid, 'user_code' => $userModel->uuid,
                'parent_uuid' => null, 'name' => $userModel->name,
                'children' => [], 'has_children' => count($this->networkTreeService->directUserCodes($userModel->uuid)) > 0,
            ];
            foreach ($childrenByParent[strtoupper($userModel->uuid)] ?? [] as $childCode) {
                $childNode = $buildNode($childCode);
                if ($childNode !== null) $root['children'][] = $childNode;
            }

            return $this->sendResponse([
                'points' => $networkPoints->values(), 'user' => $userModel,
                'network_tree' => $root,
                'network_meta' => [
                    ...$networkMetrics,
                    'returned_nodes' => $pointsByCode->count(),
                    'complete' => $pointsByCode->count() === count($allDownlineCodes),
                ],
            ], 'Éxito');
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
