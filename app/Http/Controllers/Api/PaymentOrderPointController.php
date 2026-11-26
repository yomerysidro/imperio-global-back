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
use App\Services\Core\RangeQualificationService;
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
            $userModel->estado_visual = $estadoVisual;
            // -----------------------------------------------------------

            // OBTENEMOS A TODA LA RED SIN IMPORTAR EL MES (para mostrar árbol)
            $graph = $this->networkTreeService->descendantGraph($userModel->uuid);
            $allDownlineCodes = $graph['codes'];
            $currentParents = SponsorRelation::whereIn('user_code', $allDownlineCodes)
                ->where('state', true)->pluck('sponsor_code', 'user_code');
            $parentByChild = [];
            foreach ($currentParents as $childCode => $parentCode) {
                $parentByChild[strtoupper((string) $childCode)] = $parentCode;
            }

            $rawNetworkPoints = PaymentOrderPoint::query()
                ->whereIn('user_code', $allDownlineCodes)
                ->where('payment', 1)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->orderBy('created_at', 'desc')
                ->get();

            $networkPoints = $rawNetworkPoints->unique('user_code')->values();
            unset($rawNetworkPoints);
            $networkPoints->load(['userPoint.file', 'userPoint.paymentActive', 'sponsor']);

            // Una relación genealógica puede existir aunque el movimiento B
            // histórico falte o haya sido desactivado. Esos socios también
            // pertenecen al árbol y deben aparecer como nodos sin puntos.
            $codesWithPoint = $networkPoints->pluck('user_code')
                ->map(fn ($code) => strtoupper($code))->flip();
            $missingCodes = array_values(array_filter(
                $allDownlineCodes,
                fn ($code) => !$codesWithPoint->has(strtoupper($code))
            ));
            $missingUsers = User::with('file')->whereIn('uuid', $missingCodes)->get()->keyBy(
                fn ($user) => strtoupper($user->uuid)
            );
            foreach ($missingCodes as $missingCode) {
                $placeholder = new PaymentOrderPoint();
                $placeholder->user_code = $missingCode;
                $placeholder->type = PaymentOrderPoint::COMPRA;
                $placeholder->point = 0;
                $placeholder->payment = 0;
                $placeholder->state = 0;
                $placeholder->setRelation('userPoint', $missingUsers->get(strtoupper($missingCode)));
                $networkPoints->push($placeholder);
            }

            // OBTENEMOS LOS PUNTOS ESTRICTAMENTE DEL MES DE EVALUACIÓN
            $monthlyFrom = $now->copy()->setDate($anioFiltro, $mesFiltro, 1)->startOfDay();
            $monthlyTo = $monthlyFrom->copy()->addMonth();
            $allPointsTable = PaymentOrderPoint::query()
                ->whereIn('user_code', [...$allDownlineCodes, $userModel->uuid])
                ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
                ->where('created_at', '>=', $monthlyFrom)->where('created_at', '<', $monthlyTo)->get();
            $allPointsTable->whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
                ->load('paymentOrder:id,pack_id', 'paymentOrder.pack:id,category');
            $pointsByUser = $allPointsTable->groupBy(fn ($row) => strtoupper($row->user_code));
            $allProductPoints = PaymentProductOrderPoint::query()
                ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
                ->where('created_at', '>=', $monthlyFrom)->where('created_at', '<', $monthlyTo)
                ->get(['created_at', 'state', 'points']);

            [$visibleFrom, $visibleTo] = $activation->visiblePeriod($now);
            $rootProductVolume = $this->calculator->productVolume($allProductPoints, $mesFiltro, $anioFiltro, $isGracePeriod);
            $nodeProductVolume = $visibleFrom->month === $mesFiltro && $visibleFrom->year === $anioFiltro
                ? $rootProductVolume
                : $this->calculator->productVolume($allProductPoints, $visibleFrom->month, $visibleFrom->year, $isGracePeriod);
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
            $rootMonthlyPoints = $pointsByUser->get(strtoupper($userModel->uuid), new \Illuminate\Database\Eloquent\Collection());
            $combinedPoints = $rootMonthlyPoints->merge($historicalCommissions);

            // TRANSFORMACIÓN DE NODOS
            $profiles = $networkPoints->toBase()->flatMap(fn (PaymentOrderPoint $item) => [
                $item->userPoint, $item->relationLoaded('sponsor') ? $item->getRelation('sponsor') : null,
            ])->push($userModel)->filter()->unique('id');
            $activityById = $activation->activityForUsers($profiles);
            $knownActivity = $profiles->mapWithKeys(fn (User $profile) => [
                strtoupper($profile->uuid) => $activityById[$profile->id],
            ])->all();
            $networkPoints->transform(function ($item) use (
                $pointsByUser, $nodeProductVolume, $allProductPoints, $activityById, $parentByChild,
                $visibleFrom, $isGracePeriod
            ) {
                // Financial point rows retain their historical sponsor snapshot.
                // Tree edges always come from the current active genealogy.
                $item->sponsor_code = $parentByChild[strtoupper((string) $item->user_code)] ?? null;
                $item->parent_uuid = $item->sponsor_code;

                // Verificar si está activo en el ciclo actual
                $profile = $item->userPoint;
                $hasBoughtThisCycle = $profile ? $activityById[$profile->id] : false;

                $item->active = $hasBoughtThisCycle;
                $item->state = $hasBoughtThisCycle ? PaymentLog::PAGADO : PaymentLog::TERMINADO;

                if ($profile) {
                    $rows = $pointsByUser->get(strtoupper($item->user_code), []);
                    $item->points = strcasecmp($profile->uuid, $item->user_code) === 0
                        ? $this->calculator->pointsForKnownUser(
                            $item->user_code, $rows, $nodeProductVolume, $visibleFrom->month, $visibleFrom->year, $isGracePeriod
                        )
                        : $this->calculator->points(
                            $item->user_code, $rows, $allProductPoints, $visibleFrom->month, $visibleFrom->year, $isGracePeriod
                        );
                    $item->totalPoints = $this->calculator->volumeTotal($item->points);
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
            $userModel->directos = count($graph['direct']);
            $networkMetrics = app(\App\Services\Core\NetworkActivityMetrics::class)
                ->forUser($userModel, $allDownlineCodes, $graph['direct'], $knownActivity);
            $userModel->activos = $networkMetrics['total_active'];
            $userModel->red_total = $graph['edge_count'];
            $userModel->personas_red = $userModel->red_total;

            // TUS PUNTOS: Si estás inactivo, TODO en 0 excepto comisiones históricas
            if (!$isActive) {
                $historical = $this->calculator->pointsForKnownUser($userModel->uuid, $historicalCommissions, 0, $currentMonth, $currentYear, false);
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
            } else {
                $userModel->points = $this->calculator->pointsForKnownUser(
                    $userModel->uuid, $combinedPoints, $rootProductVolume,
                    $mesFiltro, $anioFiltro, $isGracePeriod
                );
            }
            $volume = app(RangeQualificationService::class)
                ->volumeBreakdownFor($userModel, $visibleFrom, $visibleTo, $isGracePeriod, $allDownlineCodes);
            $rootPoints = $userModel->points;
            $rootPoints->personal = $rootPoints->puntos_personales = $volume['personal'];
            $rootPoints->pointGroup = $rootPoints->puntos_red = $volume['network'];
            $rootPoints->compra->total_puntos = $volume['personal'];
            $rootPoints->total_general = $userModel->totalPoints = $volume['total'];
            $userModel->points = $rootPoints;

            $packageNames = User::packageNamesFor($profiles);
            $serializeProfile = function (?User $profile) use ($packageNames, $activityById): ?array {
                if (!$profile) {
                    return null;
                }

                return (clone $profile)->setAppends([])->toArray() + [
                    'active' => $activityById[$profile->id],
                    'package_name' => $packageNames[$profile->id],
                ];
            };
            $serializedPoints = $networkPoints->map(function (PaymentOrderPoint $item) use ($serializeProfile) {
                $row = $item->attributesToArray();
                $row['user_point'] = $serializeProfile($item->userPoint);
                $row['user'] = $row['user_point'];
                if ($item->relationLoaded('sponsor')) {
                    $row['sponsor'] = $serializeProfile($item->getRelation('sponsor'));
                }
                return $row;
            })->values();
            $pointsByCode = $serializedPoints->keyBy(fn (array $item) => strtoupper((string) $item['user_code']));
            $childrenByParent = [];
            foreach ($parentByChild as $childCode => $parentCode) {
                $childrenByParent[strtoupper((string) $parentCode)][] = $childCode;
            }
            $path = [];
            $buildNode = function (string $code) use (&$buildNode, &$path, $pointsByCode, $childrenByParent): ?array {
                $key = strtoupper($code);
                if (isset($path[$key])) return null;
                $item = $pointsByCode->get($key);
                if (!$item) return null;
                $path[$key] = true;
                $node = $item;
                $node['uuid'] = $item['user_code'];
                $node['parent_uuid'] = $item['sponsor_code'];
                $node['children'] = [];
                foreach ($childrenByParent[$key] ?? [] as $childCode) {
                    $childNode = $buildNode($childCode);
                    if ($childNode !== null) $node['children'][] = $childNode;
                }
                $node['has_children'] = count($node['children']) > 0;
                unset($path[$key]);
                return $node;
            };
            $root = [
                'uuid' => $userModel->uuid, 'user_code' => $userModel->uuid,
                'parent_uuid' => null, 'name' => $userModel->name,
                'children' => [], 'has_children' => $graph['direct'] !== [],
            ];
            foreach ($childrenByParent[strtoupper($userModel->uuid)] ?? [] as $childCode) {
                $childNode = $buildNode($childCode);
                if ($childNode !== null) $root['children'][] = $childNode;
            }

            return $this->sendResponse([
                'points' => $serializedPoints, 'user' => $serializeProfile($userModel),
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
