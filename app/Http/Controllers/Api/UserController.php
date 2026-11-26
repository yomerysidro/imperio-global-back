<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\User;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentLog;
use App\Services\Core\FileUpload;
use App\Services\Core\ActivationService;
use App\Http\Resources\PaginationCollection;
use App\Models\Pack;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
use App\Models\Range;
use App\Services\Core\PointCalculator;
use App\Services\Core\NetworkTreeService;
use App\Services\Core\CommissionService;
use App\Models\PaymentProductOrderPoint;
use App\Models\PaymentProductOrder;
use App\Models\RangeUser;
use Maatwebsite\Excel\Excel as BaseExcel;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\UsersPointExport;
use App\Mail\UsersPointExcel;
use App\Models\InviteUser;
use App\Models\GuestsTokenUser;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;
use Illuminate\Support\Str;
use App\Mail\InivitedSponsorUser;
use App\Services\Core\CodeGenerator;
use App\Models\VerificationCodeUser;
use App\Models\ManualReactivation;
use App\Services\Core\RangeQualificationService;
use App\Services\Core\UserNetworkDeletionService;
use App\Services\Core\FinancialLedgerService;

class UserController extends BaseController
{
    private $fileUpload;
    private $fileUploadPath;
    private $calculator;
    private $networkTreeService;
    private $commissionService;

    public function __construct()
    {
        $this->fileUpload         = new FileUpload();
        $this->fileUploadPath     = 'avatar';
        $this->calculator         = new PointCalculator();
        $this->networkTreeService = new NetworkTreeService();
        $this->commissionService  = new CommissionService();
    }

        public function show($id)
    {
        try {
            $user = User::with(['file', 'range.range.file'])->find($id);

            if (!$user) {
                return $this->sendError("Usuario no encontrado.");
            }

            $now           = Carbon::now('America/Lima');
            $currentMonth  = $now->month;
            $currentYear   = $now->year;
            $mesAnterior   = $now->copy()->subMonth();
            $isGracePeriod = app(\App\Services\Core\ActivationService::class)->isMonthlyGracePeriod($now);

            $servicePayment = PaymentLog::with(['paymentOrder.pack'])
                ->where("user_id", $user->id)->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])->orderBy('created_at', 'desc')->first();
            $productPayment = PaymentProductOrder::with(['pack'])
                ->where("user_id", $user->id)->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO])->orderBy('created_at', 'desc')->first();

            $ultimoPago = collect([$servicePayment, $productPayment])->filter()->sortByDesc('created_at')->first();
            $displayPayment = $this->latestOwnedPackagePayment($user->id);

            $isActive   = false;
            $mesFiltro  = $currentMonth;
            $anioFiltro = $currentYear;

            if ($ultimoPago) {
                $fechaPago = Carbon::parse($ultimoPago->created_at);
                if ($fechaPago->month == $currentMonth && $fechaPago->year == $currentYear) {
                    $isActive = true;
                } elseif ($fechaPago->month == $mesAnterior->month && $fechaPago->year == $mesAnterior->year) {
                    if ($isGracePeriod) {
                        $isActive = true;
                        $mesFiltro = $mesAnterior->month;
                        $anioFiltro = $mesAnterior->year;
                    }
                }
            }

            if (!$isActive && $ultimoPago) $ultimoPago->state = 6;
            $isActive = app(\App\Services\Core\ActivationService::class)->isActive($user);
            $user->payment = $this->displayPaymentPayload($displayPayment, $isActive);
            $user->active = $isActive;

            // =========================================================
            // 🔥 CÁLCULO DE PUNTOS (EXACTO AL DE auth())
            // =========================================================
            $paymentOrderPoints = PaymentOrderPoint::query()
                ->when(!$isGracePeriod, fn ($query) => $query->where('state', 1))
                ->whereMonth('created_at', $mesFiltro)
                ->whereYear('created_at', $anioFiltro)
                ->whereIn('type', ['B', 'G', 'R', 'RS', 'P', 'PS', 'S', 'I'])
                ->get();

            $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $user->id)
                ->when(!$isGracePeriod, fn ($query) => $query->where("state", true))
                ->whereMonth('created_at', $mesFiltro)
                ->whereYear('created_at', $anioFiltro)
                ->get();

            $paymentOrderPointsUser = $paymentOrderPoints->filter(function ($point) use ($user) {
                return strtoupper($point->user_code) == strtoupper($user->uuid);
            })->values();

            $puntosPersonales = $paymentOrderPointsUser->where('type', 'B')->sum('point');
            $puntosRed        = $paymentOrderPointsUser->where('type', 'G')->sum('point');
            $puntosResiduales = $this->commissionTotal($paymentOrderPointsUser, ['R', 'RS']);
            $gananciaPatrocinio = $this->sponsorshipTotal($paymentOrderPointsUser);
            $puntosInfinito   = $paymentOrderPointsUser->where('type', 'I')->sum('point');

            $totalPoints = $puntosPersonales + $puntosRed;

            // 🔥 CONSTRUIR OBJETO DE PUNTOS
            $user->points = (object) [
                'patrocinio'          => $gananciaPatrocinio,
                'residual'            => $puntosResiduales,
                'compra'              => (object) ['total_puntos' => $puntosPersonales],
                'pointGroup'          => $puntosRed,
                'personal'            => $puntosPersonales,
                'infinito'            => $puntosInfinito,
                'bono'                => $gananciaPatrocinio,
                'bono_total'          => $gananciaPatrocinio + $puntosResiduales + $puntosInfinito,
                'bonos_totales'       => $gananciaPatrocinio + $puntosResiduales + $puntosInfinito,
                'ganancia_total'      => $gananciaPatrocinio + $puntosResiduales + $puntosInfinito,
                'total_comisiones'    => $gananciaPatrocinio + $puntosResiduales + $puntosInfinito,
                'pointAfiliado'       => 0,
                'personalGlobal'      => 0,
                'patrocinioRequest'   => 0,
                'patrocinioServicio'  => 0,
                'residualServicio'    => 0,
                'puntos_personales'   => $puntosPersonales,
                'puntos_red'          => $puntosRed,
                'ganancia_patrocinio' => $gananciaPatrocinio,
                'total_general'       => $totalPoints
            ];

            $user->totalPoints = $totalPoints;

            // La propiedad del pack es historica y no depende de que la
            // activacion mensual siga vigente. Se excluyen solamente las
            // ordenes creadas por reactivaciones administrativas.
            $manualReactivations = ManualReactivation::where('user_id', $user->id)->get([
                'payment_product_order_id', 'payment_log_ids',
            ]);
            $reactivationProductOrderIds = $manualReactivations
                ->pluck('payment_product_order_id')->filter()->unique()->values();
            $reactivationPaymentLogIds = $manualReactivations
                ->pluck('payment_log_ids')->filter()->flatten()->filter()->unique()->values();

            $packagePurchases = PaymentLog::with(['paymentOrder.pack'])
                ->where('user_id', $user->id)
                ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO, PaymentLog::RESET])
                ->when($reactivationPaymentLogIds->isNotEmpty(), fn ($query) =>
                    $query->whereNotIn('id', $reactivationPaymentLogIds))
                ->latest('created_at')->get();
            $servicePurchases = $packagePurchases->filter(fn (PaymentLog $payment) =>
                strcasecmp(trim((string) $payment->paymentOrder?->pack?->category), 'Servicio') === 0
            )->values();
            $productPackPurchases = $packagePurchases->filter(fn (PaymentLog $payment) =>
                strcasecmp(trim((string) $payment->paymentOrder?->pack?->category), 'Producto') === 0
            )->values();

            $productPurchases = PaymentProductOrder::with('pack')
                ->where('user_id', $user->id)
                ->whereIn('state', [
                    PaymentProductOrder::PAGADO,
                    PaymentProductOrder::ENVIADO,
                    PaymentProductOrder::TERMINADO,
                ])
                ->when($reactivationProductOrderIds->isNotEmpty(), fn ($query) =>
                    $query->whereNotIn('id', $reactivationProductOrderIds))
                ->whereHas('pack', fn ($query) =>
                    $query->whereRaw('LOWER(TRIM(category)) = ?', ['producto']))
                ->latest('created_at')->get();

            // Campos compatibles con el modal actual.
            $user->payment_services = $servicePurchases->map(fn (PaymentLog $payment) => [
                'id' => $payment->id,
                'pack_id' => $payment->paymentOrder?->pack_id,
                'state' => $payment->state,
                'pack' => $payment->paymentOrder?->pack,
                'created_at' => $payment->created_at,
            ])->values();
            $user->payment_product_orders = $productPurchases;

            $latestProductPack = collect([
                $productPackPurchases->first() ? [
                    'pack' => $productPackPurchases->first()->paymentOrder?->pack,
                    'created_at' => $productPackPurchases->first()->created_at,
                ] : null,
                $productPurchases->first() ? [
                    'pack' => $productPurchases->first()->pack,
                    'created_at' => $productPurchases->first()->created_at,
                ] : null,
            ])->filter(fn ($purchase) => $purchase && $purchase['pack'])
                ->sortByDesc('created_at')->first();

            $activation = app(\App\Services\Core\ActivationService::class);
            $user->packs_by_category = [
                'product' => [
                    'owned' => $latestProductPack !== null,
                    'active' => $activation->isActiveForCategory($user, 'product'),
                    'pack' => $latestProductPack['pack'] ?? null,
                ],
                'service' => [
                    'owned' => $servicePurchases->isNotEmpty(),
                    'active' => $activation->isActiveForCategory($user, 'service'),
                    'pack' => $servicePurchases->first()?->paymentOrder?->pack,
                ],
            ];
            if ($user->assignedPack) {
                $user->packs_by_category = $this->assignedPackCategories($user, $activation);
            }


            // 🔥 AGREGAR USER_DETAIL (LO QUE EL FRONTEND NECESITA)
            $user->user_detail = [
                'puntos_personales'   => $puntosPersonales,
                'puntos_red'          => $puntosRed,
                'ganancia_patrocinio' => $gananciaPatrocinio,
                'puntos_residuales'   => $puntosResiduales,
                'total_puntos'        => $totalPoints,
                'paquete_actual'      => $user->package_name ?? 'Sin paquete',
                'rango_actual'        => $user->range?->range?->title ?? 'Sin rango'
            ];

            // Network cards use the live sponsor graph, independently of
            // point eligibility, package state, and historical snapshots.
            $networkMetrics = app(\App\Services\Core\NetworkActivityMetrics::class)->forUser($user);
            $user->directos = $networkMetrics['total_directs'];
            $user->directos_patrocinados = $networkMetrics['total_directs'];
            $user->directos_activos = $networkMetrics['total_directs_active'];
            $user->activos = $networkMetrics['total_active'];
            $user->red_total = $networkMetrics['total_network'];
            $user->personas_red = $networkMetrics['total_network'];
            $user->network_summary = $networkMetrics;

            // 🔥 LOG PARA DEPURACIÓN EN EL BACKEND (revisa storage/logs/laravel.log)
            \Log::info('✅ show() - Usuario consultado:', [
                'uuid' => $user->uuid,
                'user_detail' => $user->user_detail,
                'points' => $user->points
            ]);

            return $this->sendResponse($user, 'Usuario encontrado');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function auth()
{
    try {
        $user_id   = Auth::id();
        $userModel = User::with('file')->select("*", "created_at as creatxlssed")->find($user_id);

        if (!$userModel) return $this->sendError("Usuario no encontrado");

        $now           = Carbon::now('America/Lima');
        $currentMonth  = $now->month;
        $currentYear   = $now->year;
        $mesAnterior   = $now->copy()->subMonth();
        $isGracePeriod = app(\App\Services\Core\ActivationService::class)->isMonthlyGracePeriod($now);

        $servicePayment = PaymentLog::query()
            ->where("user_id", $user_id)->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])->orderBy('created_at', 'desc')->first();
        $productPayment = PaymentProductOrder::query()
            ->where("user_id", $user_id)->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO])->orderBy('created_at', 'desc')->first();

        $ultimoPago = collect([$servicePayment, $productPayment])->filter()->sortByDesc('created_at')->first();
        $displayPayment = $this->latestOwnedPackagePayment($user_id);

        $isActive   = false;
        $mesFiltro  = $currentMonth;
        $anioFiltro = $currentYear;

        if ($ultimoPago) {
            $fechaPago = Carbon::parse($ultimoPago->created_at);
            if ($fechaPago->month == $currentMonth && $fechaPago->year == $currentYear) {
                $isActive = true;
            } elseif ($fechaPago->month == $mesAnterior->month && $fechaPago->year == $mesAnterior->year) {
                if ($isGracePeriod) {
                    $isActive = true;
                    $mesFiltro = $mesAnterior->month;
                    $anioFiltro = $mesAnterior->year;
                }
            }
        }

        if ($userModel->is_admin || strcasecmp((string) $userModel->uuid, app(\App\Services\Core\CorporateRootService::class)->uuid()) === 0) {
            $isActive = true;
            if ($servicePayment) {
                $servicePayment->state = PaymentLog::PAGADO;
            }
            if ($userModel->is_admin && !$ultimoPago) {
                $defaultPack = Pack::where('title', 'Pack Empresario')->first();
                if ($defaultPack) {
                    $paymentOrder = PaymentOrder::create([
                        'currency'     => 'PEN',
                        'amount'       => 0,
                        'sponsor_code' => $userModel->uuid,
                        'pack_id'      => $defaultPack->id,
                        'token'        => 'ADMIN-' . uniqid()
                    ]);
                    $ultimoPago = PaymentLog::create([
                        'payment_order_id' => $paymentOrder->id,
                        'user_id'          => $user_id,
                        'state'            => PaymentLog::PAGADO,
                        'confirm'          => true,
                        'message'          => 'Admin activo por defecto'
                    ]);
                    $servicePayment = $ultimoPago;
                }
            }
        }

        if (!$isActive && $ultimoPago) {
            $ultimoPago->state = 6;
            PaymentLog::where('id', $ultimoPago->id)->update(['state' => 6]);
        }

        $isActive = app(\App\Services\Core\ActivationService::class)->isActive($userModel);
        $userModel->payment      = $this->displayPaymentPayload($displayPayment, $isActive);
        $packageName = $userModel->package_name;

        // La cuenta corporativa debe mostrar el mismo ciclo visible que el
        // resto del sistema. Durante la gracia ese ciclo es el mes anterior,
        // incluso si la raíz corporativa tiene un registro administrativo del mes actual.
        if ($userModel->is_admin || strcasecmp((string) $userModel->uuid, app(\App\Services\Core\CorporateRootService::class)->uuid()) === 0) {
            [$visibleFrom] = app(\App\Services\Core\ActivationService::class)->visiblePeriod($now);
            $mesFiltro = $visibleFrom->month;
            $anioFiltro = $visibleFrom->year;
        }

        // 🔥 CORRECCIÓN 1: OBTENER TODOS LOS PUNTOS ACTIVOS (state = 1) DEL MES FILTRADO
        // Incluimos todos los tipos: B, G, R, P, S, I
        $paymentOrderPointsUser = PaymentOrderPoint::query()
            ->whereRaw('UPPER(user_code) = ?', [strtoupper($userModel->uuid)])
            ->when(!$isGracePeriod, fn ($query) => $query->where('state', 1))
            ->whereMonth('created_at', $mesFiltro)
            ->whereYear('created_at', $anioFiltro)
            ->whereIn('type', ['B', 'G', 'R', 'RS', 'P', 'PS', 'S', 'I']) // TODOS LOS TIPOS
            ->get();
        // El Home muestra solo las ganancias del periodo seleccionado.
        // El historial permanece disponible en los reportes financieros.


        // 🔥 CORRECCIÓN 3: CALCULAR PUNTOS POR TIPO (Todos los tipos)
        $puntosPersonales = $paymentOrderPointsUser->where('type', 'B')->sum('point'); // COMPRA
        $puntosRed        = $paymentOrderPointsUser->where('type', 'G')->sum('point'); // GRUPAL
        $residualProducto = $this->commissionTotal($paymentOrderPointsUser, ['R']);
        $residualServicio = $this->commissionTotal($paymentOrderPointsUser, ['RS']);
        $puntosResiduales = $residualProducto + $residualServicio;
        $gananciaPatrocinio = $this->sponsorshipTotal($paymentOrderPointsUser);
        $puntosInfinito   = $paymentOrderPointsUser->where('type', 'I')->sum('point'); // INFINITO

        // la raíz corporativa es la cuenta corporativa y no depende de plan, actividad ni
        // rango. Su Home usa el mismo libro que Finanzas/Excel para evitar que
        // una segunda lógica deje en cero comisiones que sí existen.
        if ($userModel->is_admin || strcasecmp((string) $userModel->uuid, app(\App\Services\Core\CorporateRootService::class)->uuid()) === 0) {
            $companyCommissions = app(FinancialLedgerService::class)->summary(
                Carbon::create($anioFiltro, $mesFiltro, 1)->startOfMonth(),
                Carbon::create($anioFiltro, $mesFiltro, 1)->endOfMonth(),
                $userModel->uuid,
                $isGracePeriod
            );
            $gananciaPatrocinio = (float) $companyCommissions['patrocinio'];
            $residualProducto = (float) $companyCommissions['residualProducto'];
            $residualServicio = (float) $companyCommissions['residualServicio'];
            $puntosResiduales = (float) $companyCommissions['residual'];
            $puntosInfinito = (float) $companyCommissions['infinito'];
        }

        // 🔥 CORRECCIÓN 4: TOTAL DE PUNTOS PARA RANGO = COMPRA + GRUPAL + RESIDUAL
        // El volumen para rango contiene solamente puntos de compra y de red.
        // Las comisiones son dinero y nunca incrementan el volumen grupal.
        $totalPoints = $puntosPersonales + $puntosRed;
        $totalComisiones = $gananciaPatrocinio + $puntosResiduales + $puntosInfinito;

        // Las invitaciones antiguas no representan socios activos ni deben
        // alimentar los indicadores actuales del Home.
        $legacyTokens = collect();

        // 🔥 CORRECCIÓN 5: LÓGICA PARA la raíz corporativa (CORPORATIVO)
        $descendantCodes = null;
        if (app(\App\Services\Core\CorporateRootService::class)->isRootCode($userModel->uuid)) {
            $descendantCodes = $this->networkTreeService->descendantCodes($userModel->uuid);

            $monthlyGroupVolume = DB::query()->fromSub(
                PaymentOrderPoint::select('payment_order_id', DB::raw('MAX(point) as point'))
                    ->whereIn('user_code', $descendantCodes)
                    ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->whereMonth('created_at', $mesFiltro)
                    ->whereYear('created_at', $anioFiltro)
                    ->groupBy('payment_order_id'),
                'monthly_network_purchases'
            )->sum('point');

            // Se conserva la clave por compatibilidad con el frontend, pero
            // nunca se mezcla el acumulado historico con el periodo actual.
            $userModel->volumen_grupal_historico = (float) $monthlyGroupVolume;
            $userModel->volumen_grupal_mensual = (float) $monthlyGroupVolume;
            $totalPoints = (float) $monthlyGroupVolume;

            $userModel->points = (object) [
                'patrocinio'         => (float) $gananciaPatrocinio,
                'residual'           => (float) $puntosResiduales,
                'residualProducto'   => (float) $residualProducto,
                'residualServicio'   => (float) $residualServicio,
                'compra'             => (object) ['total_puntos' => 0],
                'pointGroup'         => (float) $monthlyGroupVolume,
                'pointGroupMonthly'  => (float) $monthlyGroupVolume,
                'personal'           => 0,
                'infinito'           => (float) $puntosInfinito,
                'bono'               => (float) $gananciaPatrocinio,
                'bono_total'         => (float) $totalComisiones,
                'bonos_totales'      => (float) $totalComisiones,
                'ganancia_total'     => (float) $totalComisiones,
                'pointAfiliado'      => 0,
                'personalGlobal'     => 0,
                'patrocinioRequest'  => 0,
                'patrocinioServicio' => 0,
                'legacy_bonus'       => 0,
                'total_general'      => (float) $monthlyGroupVolume,
                'total_comisiones'   => (float) $totalComisiones
            ];
        } else {
            // 🔥 LÓGICA PARA USUARIOS NORMALES
            $userModel->points = (object) [
                'patrocinio'          => $gananciaPatrocinio, // Bono por reclutar (P + S)
                'residual'            => $puntosResiduales,   // Bono residual (R)
                'residualProducto'    => $residualProducto,
                'residualServicio'    => $residualServicio,
                'compra'              => (object) ['total_puntos' => $puntosPersonales], // Puntos personales (B)
                'pointGroup'          => $puntosRed,          // Puntos grupales (G)
                'personal'            => $puntosPersonales,   // Puntos personales (B)
                'infinito'            => $puntosInfinito,     // Bono infinito (I)
                'bono'                => $gananciaPatrocinio,
                'bono_total'          => $totalComisiones,
                'bonos_totales'       => $totalComisiones,
                'ganancia_total'      => $totalComisiones,
                'pointAfiliado'       => 0,
                'personalGlobal'      => 0,
                'patrocinioRequest'   => 0,
                'patrocinioServicio'  => 0,
                'puntos_personales'   => $puntosPersonales,
                'puntos_red'          => $puntosRed,
                'ganancia_patrocinio' => $gananciaPatrocinio,
                'total_general'       => $totalPoints,
                'total_comisiones'    => $totalComisiones
            ];
        }

        $networkMetrics = app(\App\Services\Core\NetworkActivityMetrics::class)->forUser($userModel, $descendantCodes);
        $userModel->directos = $networkMetrics['total_directs'];
        $userModel->directos_patrocinados = $networkMetrics['total_directs'];
        $userModel->directos_activos = $networkMetrics['total_directs_active'];
        $userModel->activos = $networkMetrics['total_active'];
        $userModel->red_total = $networkMetrics['total_network'];
        $userModel->personas_red = $networkMetrics['total_network'];
        $userModel->totalPoints = $totalPoints;

        // Sincronizar el rango con el mismo volumen B + G que muestra el perfil.
        app(RangeQualificationService::class)->recalculateUser($userModel, isActive: $isActive);
        $userModel->load('range.range.rule');
        $rangeCurrent = $userModel->range?->range;
        $nextRange = Range::with('rule')->where('state', true)
            ->when($rangeCurrent, fn ($query) => $query->where('order', '>', $rangeCurrent->order))
            ->orderBy('order')->first();
        $progressTarget = $nextRange ?: $rangeCurrent;
        $requiredPoints = (float) ($progressTarget?->rule?->required_points ?? $progressTarget?->points ?? 0);
        $remainingPoints = max(0, $requiredPoints - (float) $totalPoints);
        $rangeProgress = $requiredPoints > 0
            ? ($remainingPoints > 0 ? min(99.99, max(0, round($totalPoints / $requiredPoints * 100, 2))) : 100)
            : 0;

        $responsePayload = $userModel->setAppends([])->toArray() + [
            'active' => $isActive,
            'package_name' => $packageName,
        ];
        // `points` conserva el resumen tipado que consume el home. Exponer los
        // movimientos aparte evita sumar comisiones R/P/I como volumen B/G.
        $responsePayload['point_records'] = $paymentOrderPointsUser->values()->toArray();
        $responsePayload['volume_records'] = $paymentOrderPointsUser
            ->whereIn('type', ['B', 'G'])->values()->toArray();
        $responsePayload['commission_records'] = $paymentOrderPointsUser
            ->whereIn('type', ['P', 'PS', 'S', 'R', 'RS', 'I'])->values()->toArray();
        $responsePayload['legacy_count']    = $legacyTokens->count();
        $responsePayload['network_summary'] = [
            ...$networkMetrics,
            'has_legacy_network' => $legacyTokens->count() > 0
        ];

        $responsePayload['user_detail'] = [
            'puntos_personales'   => $puntosPersonales,
            'puntos_red'          => $puntosRed,
            'ganancia_patrocinio' => $gananciaPatrocinio,
            'puntos_residuales'   => $puntosResiduales,
            'residual_producto'   => $residualProducto,
            'residual_servicio'   => $residualServicio,
            'bono'                => $gananciaPatrocinio,
            'residual'            => $puntosResiduales,
            'infinito'            => $puntosInfinito,
            'bono_total'          => $gananciaPatrocinio + $puntosResiduales + $puntosInfinito,
            'bonos_totales'       => $gananciaPatrocinio + $puntosResiduales + $puntosInfinito,
            'ganancia_total'      => $gananciaPatrocinio + $puntosResiduales + $puntosInfinito,
            'total_puntos'        => $totalPoints,
            'paquete_actual'      => $packageName,
            'rango_actual'        => $rangeCurrent ? $rangeCurrent->title : 'Sin rango'
        ];
        $responsePayload['current_range'] = $rangeCurrent;
        $responsePayload['next_range'] = $nextRange;
        $responsePayload['range_progress'] = $rangeProgress;
        $responsePayload['points_to_next_range'] = $nextRange ? $remainingPoints : 0;

        $responsePayload['gender'] = $userModel->genger;

        return $this->sendResponse((object)$responsePayload, 'Perfil sincronizado');
    } catch (Exception $e) {
        return $this->sendError("Fallo de integridad: " . $e->getMessage());
    }
}

    public function authUpdate(Request $request)
    {
        $profile = $request->validate([
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'gender' => ['nullable', 'string', 'in:M,F'],
        ]);

        if (array_key_exists('gender', $profile)) {
            $profile['genger'] = $profile['gender'];
            unset($profile['gender']);
        }

        User::whereKey($request->user()->id)->update($profile);

        return $this->sendResponse(true, 'Perfil actualizado.');
    }

    public function authUpdateAvatar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|max:5120',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            $fileId  = 0;
            $user_id = Auth::id();

            if ($request->hasfile('file')) $fileId = $this->fileUpload->upload($request->file('file'), $this->fileUploadPath);

            User::where("id", $user_id)->update(["photo" => $fileId]);
            $userModel = User::with(['file'])->find($user_id);

            DB::commit();
            return $this->sendResponse($userModel, 'User');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage());
        }
    }

    public function findAll(Request $request)
{
    try {
        $limit = $this->limit;
        if ($request->has('limit')) $limit = intval($request->query('limit'));

        $user_id   = Auth::id();
        $userModel = User::with(['file'])->find($user_id);

        if (!$userModel->is_admin) {
            if ($request->has('code')) {
                $targetCode = $request->query('code');
                $isBelongingToNetwork = $this->networkTreeService->belongsToNetwork(
                    $userModel->uuid,
                    $targetCode
                );
                if (!$isBelongingToNetwork) {
                    return $this->sendError("No tiene permisos para ver la información de este usuario.");
                }
            } else {
                return $this->sendError("Acceso restringido: debe especificar un código de socio válido.");
            }
        }

        $userList = User::with('file')->where('is_admin', false);

        if ($request->has('code') && !empty($request->query('code'))) $userList = $userList->where("uuid", 'like', $request->query('code'));
        if ($request->has('email') && !empty($request->query('email'))) $userList = $userList->where("email", 'like', $request->query('email'));
        if ($request->has('name') && !empty($request->query('name'))) $userList = $userList->where("name", 'like', '%' . ($request->query('name')) . '%');

        if ($request->has('plan') && !empty($request->query('plan'))) {
            $plan = $request->query('plan');
            $prefix = \App\Models\Option::ASSIGNED_PACK_PREFIX;
            $assignedUsers = DB::table('options')
                ->whereRaw('BINARY LEFT(option_key, ?) = ?', [strlen($prefix), $prefix])
                ->selectRaw('CAST(SUBSTRING(option_key, ?) AS UNSIGNED)', [strlen($prefix) + 1]);

            if ($plan == -1) {
                // Un pack adquirido es permanente. Que el periodo haya terminado o
                // sido reseteado no significa que el usuario nunca tuvo un plan.
                $serviceOwners = PaymentLog::whereIn('state', [
                    PaymentLog::PAGADO,
                    PaymentLog::TERMINADO,
                    PaymentLog::RESET,
                ])->whereNotNull('user_id')->select('user_id');
                $productOwners = PaymentProductOrder::whereIn('state', [
                    PaymentProductOrder::PAGADO,
                    PaymentProductOrder::ENVIADO,
                    PaymentProductOrder::TERMINADO,
                ])->whereNotNull('user_id')->select('user_id');
                $userList->whereNotIn('id', $assignedUsers)->whereNotIn('id', $serviceOwners->union($productOwners));
            } else {
                $serviceOwners = PaymentLog::whereIn('state', [
                    PaymentLog::PAGADO,
                    PaymentLog::TERMINADO,
                    PaymentLog::RESET,
                ])->whereHas("paymentOrder.pack", function ($q) use ($plan) {
                    $q->where('id', $plan);
                })->select('user_id');
                $productOwners = PaymentProductOrder::whereIn('state', [
                    PaymentProductOrder::PAGADO,
                    PaymentProductOrder::ENVIADO,
                    PaymentProductOrder::TERMINADO,
                ])->where('pack_id', $plan)->select('user_id');
                $userList->where(fn ($query) => $query->whereIn('id', (clone $assignedUsers)->where('option_value', $plan))
                    ->orWhere(fn ($legacy) => $legacy->whereNotIn('id', $assignedUsers)
                        ->whereIn('id', $serviceOwners->union($productOwners))));
            }
        }

        $userList = $userList->with('assignedPackOption.pack')->orderBy('created_at', 'desc')->paginate($limit);

        if ($userList->isEmpty()) {
            return $this->sendResponse(new PaginationCollection($userList), 'Lista obtenida correctamente');
        }

        $pageUsers = collect($userList->items());
        $userIds = $pageUsers->pluck('uuid')->all();
        $normalizedUserCodes = array_map('strtoupper', $userIds);
        $paginatedUserIds = $pageUsers->pluck('id')->all();
        $ownedLogs = PaymentLog::with('paymentOrder:id,pack_id', 'paymentOrder.pack')
            ->whereIn('user_id', $paginatedUserIds)
            ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO, PaymentLog::RESET])
            ->orderBy('created_at')->get(['id', 'user_id', 'payment_order_id', 'state', 'created_at'])->groupBy('user_id');
        $ownedOrders = PaymentProductOrder::with('pack')
            ->whereIn('user_id', $paginatedUserIds)
            ->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO])
            ->orderBy('created_at')->get(['id', 'user_id', 'pack_id', 'created_at'])->groupBy('user_id');
        $latestLogs = $ownedLogs->map(fn ($logs) => $logs->sortByDesc('created_at')->first());
        $latestOrders = $ownedOrders->map(fn ($orders) => $orders->sortByDesc('created_at')->first());
        $displayLogs = PaymentLog::with('paymentOrder.pack')
            ->whereKey($latestLogs->pluck('id')->all())->get()->keyBy('user_id');
        $sponsorCodes = $this->networkTreeService->sponsorCodes($userIds);
        $sponsors = User::whereIn(DB::raw('UPPER(uuid)'), $sponsorCodes
            ->map(fn ($code) => strtoupper($code))->unique()->values()->all())->get(['uuid', 'name'])
            ->keyBy(fn (User $sponsor) => strtoupper($sponsor->uuid));

        $now = Carbon::now('America/Lima');
        $currentMonth = $now->month;
        $currentYear = $now->year;
        $mesAnterior = $now->copy()->subMonth();
        $isGracePeriod = app(\App\Services\Core\ActivationService::class)->isMonthlyGracePeriod($now);

        // 🔥 Obtener TODOS los puntos activos del mes
        $allPaymentOrderPoints = PaymentOrderPoint::query()
            ->where('state', 1)
            ->whereIn(DB::raw('UPPER(user_code)'), $normalizedUserCodes)
            ->whereMonth('created_at', $currentMonth)->whereYear('created_at', $currentYear)
            ->get()->groupBy(fn ($point) => strtoupper($point->user_code));

        $allProductOrderPoints = PaymentProductOrderPoint::where("state", true)
            ->whereIn('user_id', $paginatedUserIds)
            ->whereMonth('created_at', $currentMonth)->whereYear('created_at', $currentYear)
            ->get()->groupBy('user_id');

        // 🔥 Puntos del mes anterior (período de gracia)
        $allPaymentOrderPointsLastMonth = $isGracePeriod ? PaymentOrderPoint::query()
            ->whereIn(DB::raw('UPPER(user_code)'), $normalizedUserCodes)
            ->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year)
            ->get()->groupBy(fn ($point) => strtoupper($point->user_code)) : collect();

        $allProductOrderPointsLastMonth = $isGracePeriod ? PaymentProductOrderPoint::query()
            ->whereIn('user_id', $paginatedUserIds)
            ->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year)
            ->get()->groupBy('user_id') : collect();

        $activeManualReactivationUserIds = ManualReactivation::whereIn('user_id', $paginatedUserIds)
            ->where('state', ManualReactivation::ACTIVE)
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        // 🔥 Sumar los bonos históricos (P, S, R, RS) - esto es para el frontend como dato adicional
        $historicalBonuses = PaymentOrderPoint::select('user_code', DB::raw('SUM(point) as total_bono'))
            ->whereIn('user_code', $userIds)->where('state', 1)
            ->whereMonth('created_at', $currentMonth)
            ->whereYear('created_at', $currentYear)
            ->whereIn('type', [PaymentOrderPoint::PATROCINIO, PaymentOrderPoint::PATROCINIO_SERVICIO, PaymentOrderPoint::RESIDUAL, PaymentOrderPoint::RESIDUAL_SERVICIO, PaymentOrderPoint::INFINITO])
            ->groupBy('user_code')->pluck('total_bono', 'user_code');

        $activeRanges = Range::where('state', true)->orderBy('points')->get();
        $rangeRules = app(RangeQualificationService::class)->activeRules();

        $items = $userList->getCollection()->map(function (User $user) use (
            $ownedLogs, $ownedOrders, $currentMonth, $mesAnterior, $isGracePeriod,
            $activeManualReactivationUserIds, $allPaymentOrderPoints, $allProductOrderPoints,
            $allPaymentOrderPointsLastMonth, $allProductOrderPointsLastMonth, $rangeRules,
            $historicalBonuses, $sponsorCodes, $sponsors, $displayLogs, $latestOrders
        ) {
            $displayPayment = collect([$displayLogs->get($user->id), $latestOrders->get($user->id)])
                ->filter()->sortByDesc('created_at')->first();

            $mesFiltro = $currentMonth;

            $logs = $ownedLogs->get($user->id, collect());
            $orders = $ownedOrders->get($user->id, collect());
            if ($isGracePeriod) {
                $serviceDate = $logs->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])->last()?->created_at;
                $productDate = $orders->last()?->created_at;
                $fechaPago = $serviceDate && (!$productDate || $serviceDate->gte($productDate))
                    ? $serviceDate
                    : $productDate;
                if ($fechaPago && $fechaPago->month == $mesAnterior->month && $fechaPago->year == $mesAnterior->year) {
                    $mesFiltro = $mesAnterior->month;
                }
            }

            $activation = app(\App\Services\Core\ActivationService::class);
            $isActive = $activation->isActive($user);
            $sponsorCode = $sponsorCodes->get(strtoupper($user->uuid));
            $sponsor = $sponsorCode ? $sponsors->get(strtoupper($sponsorCode)) : null;
            $user->payment = $this->displayPaymentPayload($displayPayment, $isActive);
            $packageName = $user->packageNameFromPurchases($logs, $orders);
            $user->sponsor_code = $sponsor?->uuid;
            $user->sponsor_uuid = $sponsor?->uuid;
            $user->sponsor_name = $sponsor?->name;
            $user->sponsor = $sponsor ? [
                'uuid' => $sponsor->uuid,
                'name' => $sponsor->name,
            ] : null;
            $packsByCategory = $this->ownedPacksByCategory($user, $activation, $logs, $orders);
            $user->active_product = $packsByCategory['product']['active'];
            $user->active_service = $packsByCategory['service']['active'];
            $user->packs_by_category = $packsByCategory;
            // Esta marca solo corresponde a reactivaciones mensuales registradas.
            // Una compra normal de paquete o productos no habilita la desactivación.
            $user->manual_reactivation_active = in_array(
                (int) $user->id,
                $activeManualReactivationUserIds,
                true
            );

            // 🔧 Seleccionar los puntos según el mes filtrado
            $puntosDisponibles = ($mesFiltro == $currentMonth) ? $allPaymentOrderPoints : $allPaymentOrderPointsLastMonth;
            $productosDisponibles = ($mesFiltro == $currentMonth) ? $allProductOrderPoints : $allProductOrderPointsLastMonth;

            // 🔧 Filtrar SOLO los puntos del usuario actual
            $popUsuario = $puntosDisponibles->get(strtoupper($user->uuid), collect());

            $ppopUsuario = $productosDisponibles->get($user->id, collect());

            // 🔥 Esto genera el objeto points CON TODOS LOS TIPOS correctos
            $userPoints = $this->calculator->points($user->uuid, $popUsuario, $ppopUsuario);
            
            // 🔥 El total de puntos es el que ya calcula el objeto points (personal + pointGroup + residual)
            $user->totalPoints = $userPoints->total_general;

            // 🔥 ASIGNAR EL OBJETO COMPLETO AL USUARIO
            $user->points = $userPoints;

            // Keep the modal payload aligned with the `points` object used by the table.
            $user->user_detail = [
                'paquete_actual'      => $packageName,
                'activo'              => $isActive,
                'puntos_personales'   => (float) $userPoints->personal,
                'puntos_red'          => (float) $userPoints->pointGroup,
                'total_puntos'        => (float) $userPoints->total_general,
                'ganancia_patrocinio' => (float) $userPoints->patrocinio,
                'ganancia_residual'   => (float) $userPoints->residual,
                'bono_infinito'       => (float) $userPoints->infinito,
                'total_comisiones'    => (float) $userPoints->total_comisiones,
                'ganancia_total'      => (float) $userPoints->ganancia_total,
            ];

            // Usar el mismo motor de calificacion que el perfil y el CRON.
            app(RangeQualificationService::class)->recalculateUser($user, $rangeRules, $isActive);
            $user->bonos_totales_historico = $historicalBonuses->get($user->uuid, 0);
            return $user;
        });

        $items->load('range.range.file');
        $items = $items->map(function (User $user) use ($activeRanges) {
            $currentRange = $user->range?->range;
            $totalPoints = (float) $user->totalPoints;

            $nextRange = $activeRanges->first(function ($candidate) use ($currentRange) {
                return !$currentRange || (int) $candidate->order > (int) $currentRange->order;
            });
            $progressTarget = $nextRange ?: $currentRange;
            $progress = $progressTarget && (float) $progressTarget->points > 0
                ? min(100, round(($totalPoints / (float) $progressTarget->points) * 100, 2))
                : 0;

            $user->next_range = $nextRange;
            $user->range_progress = $progress;
            $user->points_to_next_range = $nextRange
                ? max(0, (float) $nextRange->points - $totalPoints)
                : 0;

            return $user->setAppends([])->toArray() + [
                'active' => $user->user_detail['activo'],
                'package_name' => $user->user_detail['paquete_actual'],
            ];
        });
        $userList->setCollection($items);

        return $this->sendResponse(new PaginationCollection($userList), 'Lista obtenida correctamente');
    } catch (\Throwable $th) {
        return $this->sendError($th->getMessage());
    }
}

    public function modifyUser(Request $request)
    {
        if (!Auth::user()?->is_admin) {
            return $this->sendError('Solo un administrador puede modificar usuarios.', [], 403);
        }

        if (is_string($request->input('userEmail'))) {
            $request->merge(['userEmail' => Str::lower(trim($request->string('userEmail')->toString()))]);
        }

        $validator = Validator::make($request->all(), [
            'userCode'     => 'required',
            'userFullName' => 'required',
            'userEmail'    => 'sometimes|required|email',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            if ($request->filled('packId') || $request->filled('serviceId')) {
                $this->networkTreeService->lockRootForMutation();
            }

            $userUpdated = User::where("uuid", $request->userCode)->first();
            if (!$userUpdated) {
                DB::rollBack();
                return $this->sendError("Usuario no encontrado");
            }

            if ($request->filled('userEmail')) {
                $newEmail = $request->userEmail;
                $emailExists = User::where('email', $newEmail)
                    ->where('id', '!=', $userUpdated->id)
                    ->exists();
                if ($emailExists) {
                    DB::rollBack();
                    return $this->sendError('El correo ya pertenece a otro usuario.', [], 422);
                }
            }

            $userUpdated->update([
                'name' => $request->userFullName,
                'email' => $request->filled('userEmail') ? $newEmail : $userUpdated->email,
            ]);

            // El patrocinador pertenece a la genealogia desde el registro y no
            // depende de que el usuario ya tenga un punto de compra tipo B.
            $currentSponsor = $this->networkTreeService->sponsorCode($userUpdated->uuid);

            $packId = $request->input('packId') ?: null;
            $serviceId = $request->input('serviceId') ?: null;
            $productPack = $packId ? Pack::find($packId) : null;
            $servicePack = $serviceId ? Pack::find($serviceId) : null;
            $assignedPack = $userUpdated->assignedPack;
            if ($assignedPack || $productPack?->isStarter() || $servicePack?->isStarter()) {
                $selected = collect([$productPack, $servicePack])->filter()
                    ->reject(fn (Pack $pack) => $pack->id === $assignedPack?->id);
                if (($packId && (!$productPack || strcasecmp($productPack->category, 'Producto') !== 0))
                    || ($serviceId && (!$servicePack || strcasecmp($servicePack->category, 'Servicio') !== 0))
                    || $selected->count() > 1 || ($selected->isNotEmpty() && !$selected->first()->state)) {
                    DB::rollBack();
                    return $this->sendError('Selecciona un solo paquete vigente para la asignacion.', [], 422);
                }

                if ($selected->isNotEmpty()) {
                    $userUpdated->assignPack($selected->first());
                }

                DB::commit();
                return $this->sendResponse(true, 'Usuario actualizado. Paquete asignado.');
            }

            if (($packId || $serviceId) && !$currentSponsor) {
                DB::rollBack();
                return $this->sendError(
                    'El usuario no tiene un patrocinador valido para asignarle un pack.',
                    [],
                    422
                );
            }

            $processPackAdd = function ($packId, $categoryTarget) use ($userUpdated, $request, $currentSponsor) {
                if (!$packId) return;

                $pack = Pack::find($packId);
                if (!$pack) return;

                $existingPackRecord = PaymentOrderPoint::where('user_code', $userUpdated->uuid)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->where('state', true)
                    ->whereHas('paymentOrder.pack', function ($q) use ($categoryTarget) {
                        $q->where('category', $categoryTarget);
                    })
                    ->with('paymentOrder.pack')
                    ->first();

                if ($existingPackRecord) {
                    // Saving an unchanged pack in the profile editor is not a
                    // purchase. Preserve its order and complete distribution.
                    if ((string) $existingPackRecord->paymentOrder?->pack_id === (string) $pack->id) {
                        return;
                    }
                    $existingPackRecord->update(['state' => false]);
                    if ($existingPackRecord->paymentOrder) {
                        PaymentLog::where('payment_order_id', $existingPackRecord->payment_order_id)
                            ->update(['state' => PaymentLog::TERMINADO]);
                    }
                }

                $newOrder = PaymentOrder::create([
                    'currency'     => "PEN",
                    'amount'       => $pack->price,
                    'sponsor_code' => $currentSponsor,
                    'pack_id'      => $pack->id,
                    "token"        => 'AUTO-' . uniqid()
                ]);

                PaymentLog::create([
                    'payment_order_id' => $newOrder->id,
                    'user_id'          => $userUpdated->id,
                    'state'            => PaymentLog::PAGADO,
                    'confirm'          => true,
                    'message'          => "Actualización de paquete: " . $pack->title
                ]);

                // Delegamos en el nuevo servicio
                $this->commissionService->confirmPoint($newOrder, $userUpdated, $pack);
            };

            $processPackAdd($packId, 'Producto');
            $processPackAdd($serviceId, 'Servicio');

            DB::commit();
            return $this->sendResponse(true, 'Usuario actualizado. Los puntos han subido a la red de patrocinio.');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError("Error: " . $e->getMessage());
        }
    }

    public function deleteNetwork(string $userCode)
    {
        return $this->deleteUser($userCode, true);
    }

    public function deleteSingle(string $userCode)
    {
        return $this->deleteUser($userCode, false);
    }

    private function deleteUser(string $userCode, bool $withNetwork)
    {
        $admin = Auth::user();
        if (!$admin || !$admin->is_admin) {
            return $this->sendError('No tiene permisos para eliminar usuarios.', [], 403);
        }

        $user = User::where('uuid', $userCode)->first();
        if (!$user) {
            return $this->sendError('Usuario no encontrado.', [], 404);
        }

        if ((int) $user->id === (int) $admin->id) {
            return $this->sendError('No puede eliminar su propio usuario.', [], 422);
        }

        try {
            $result = app(UserNetworkDeletionService::class)->delete($user, $withNetwork);
            $message = $withNetwork
                ? 'Usuario y toda su red fueron eliminados correctamente.'
                : 'Usuario eliminado y sus hijos fueron reasignados correctamente.';
            return $this->sendResponse($result, $message);
        } catch (\RuntimeException $e) {
            return $this->sendError($e->getMessage(), [], 422);
        } catch (\Throwable $e) {
            report($e);
            return $this->sendError('No se pudo eliminar el usuario y su red.', [], 500);
        }
    }

    public function search(Request $request)
    {
        try {
            $user_id   = Auth::id();
            $userModel = User::with(['file'])->find($user_id);

            if (!$userModel) return $this->sendError('La sesión no identifica un usuario válido.', [], 401);
            if (!$userModel->is_admin) return $this->sendError('Solo administradores pueden buscar socios.', [], 403);

            $userList = User::with(['file']);

            if ($request->filled('code')) {
                $userList->whereRaw('UPPER(uuid) = ?', [strtoupper(trim((string) $request->query('code')))]);
            }
            if ($request->filled('email')) {
                $userList->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $request->query('email')))]);
            }
            if ($request->filled('name')) {
                $userList->where('name', 'like', '%' . trim((string) $request->query('name')) . '%');
            }

            $userList = $userList->orderBy('created_at', 'desc')->get();

            foreach ($userList as $key => $user) {
                $ownedPayment = $this->latestOwnedPackagePayment($user->id);
                $userList[$key]->payment = $this->displayPaymentPayload(
                    $ownedPayment,
                    app(\App\Services\Core\ActivationService::class)->isActive($user)
                );
                $userList[$key]->package_name = $user->package_name;
                $sponsorCode = $this->networkTreeService->sponsorCode($user->uuid);
                $sponsor = $sponsorCode
                    ? User::with('file')->whereRaw('UPPER(uuid) = ?', [strtoupper($sponsorCode)])->first()
                    : null;
                $userList[$key]->current_sponsor_code = $sponsor?->uuid;
                $userList[$key]->sponsor_code = $sponsor?->uuid;
                $userList[$key]->sponsor_name = $sponsor?->name;
                $userList[$key]->sponsor = $sponsor ? [
                    'uuid' => $sponsor->uuid,
                    'name' => $sponsor->name,
                    'file' => $sponsor->file,
                ] : null;
            }

            return $this->sendResponse($userList, 'Lista');
        } catch (\Throwable $th) {
            report($th);
            return $this->sendError('No fue posible buscar socios por un error interno.', [], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $userList = User::with(['file'])->where('is_admin', false)->get();

            $paymentOrderPoints        = PaymentOrderPoint::with(['paymentOrder.paymentLog'])->where('state', true)->get();
            $paymentProductOrderPoints = PaymentProductOrderPoint::where("state", true)->get();

            $_userList = [];
            $ranges    = Range::where("state", true)->orderBy('points', 'asc')->get();

            foreach ($userList as $key => $user) {
                $payment = $this->latestOwnedPackagePayment($user->id);
                $_userId                    = $user->id;
                $_paymentProductOrderPoints = array_filter(
                    $paymentProductOrderPoints->toArray(),
                    function ($p) use ($_userId) {
                        return $p['user_id'] == $_userId;
                    }
                );

                $calculatorPoint = $this->calculator->points($user->uuid, $paymentOrderPoints, $_paymentProductOrderPoints);

                $uuid                = $user->uuid;
                $_paymentOrderPoints = array_filter(
                    $paymentOrderPoints->toArray(),
                    function ($p) use ($uuid) {
                        return strtoupper($p['sponsor_code']) == strtoupper($uuid) && $p['state'] == true && $p['payment'] == true && $p['type'] != PaymentOrderPoint::GRUPAL;
                    }
                );

                $totalPoints  = $calculatorPoint->personal + $calculatorPoint->pointGroup;
                $rangeCurrent = null;
                foreach ($ranges as $key => $range) {
                    if ($range->point <= $totalPoints && $range->childs == count($_paymentOrderPoints)) {
                        $rangeCurrent   = $range;
                        break;
                    }
                }

                array_push($_userList, (object) [
                    "estado"            => $payment == null ? "" : (app(ActivationService::class)->isActive($user) ? "Activo" : "Desactivo"),
                    "nombres"           => $user->name,
                    "codigo"            => $user->uuid,
                    "plan"              => $payment == null
                        ? "Sin plan"
                        : ($payment->paymentOrder?->pack?->title ?? $payment->pack?->title ?? "Sin plan"),
                    "bono_personal"     => $calculatorPoint->personal,
                    "bono_pratocinio"   => $calculatorPoint->patrocinio,
                    "bono_residual"     => $calculatorPoint->residual,
                    "bono_totales"      => $calculatorPoint->patrocinio + $calculatorPoint->residual + $calculatorPoint->infinito,
                    "punto_grupales"    => $calculatorPoint->pointGroup,
                    "punto_plan_actual" => $calculatorPoint->compra->total_puntos,
                    "gran_total"        => $totalPoints,
                    "rango"             => $rangeCurrent == null ? "" : $rangeCurrent->title,
                    "count_rango"       => "0",
                ]);
            }

            $attachment = Excel::raw(new UsersPointExport($_userList), BaseExcel::XLSX);

            $mailData = [
                'customer_name' => "Edwin",
                'month'         => "Febrero",
                'attach'        => $attachment
            ];

            Mail::to("bossun258@gmail.com")->send(new UsersPointExcel($mailData));

            return $this->sendResponse($_userList, 'Exportar');
        } catch (\Throwable $th) {
            return $this->sendError($th->getMessage());
        }
    }

    public function deleteAllPaymentByUser(Request $request)
    {
        try {
            $userId = Auth::id();
            $user   = User::where("id", $userId)->first();

            PaymentLog::where("state", PaymentLog::PAGADO)->where("user_id", $user->id)->delete();
            PaymentOrderPoint::where("state", true)->where("user_code", $user->uuid)->delete();

            return $this->sendResponse("Eliminado Usuario", 'Confirm');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function changeSponsor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userCode'    => 'required',
            'sponsorCode' => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $dataBody = (object) $request->all();

            $userSponsor = User::where("uuid", 'like', $dataBody->sponsorCode)->first();
            $userCurrent = User::where("uuid", 'like', $dataBody->userCode)->first();

            $paymentOrderPoint = PaymentOrderPoint::where("sponsor_code", $userCurrent->uuid)
                ->where("type", PaymentOrderPoint::COMPRA)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($paymentOrderPoint != null) return $this->sendError("Este usuario tiene invitados debajo de él.");

            DB::beginTransaction();

            $paymentLog = PaymentLog::with(['paymentOrder'])->where("user_id",  $userCurrent->id)
                ->where(function ($query) {
                    $query->where('state', PaymentLog::PAGADO)
                        ->orWhere('state', PaymentLog::TERMINADO);
                })
                ->orderBy('created_at', 'desc')
                ->first();

            PaymentOrder::where("id", $paymentLog->paymentOrder->id)->update([
                "sponsor_code" => $dataBody->sponsorCode
            ]);

            PaymentOrderPoint::where("user_id", $userCurrent->id)
                ->update(["sponsor_code" => $dataBody->sponsorCode]);

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function resetPoint(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userCode' => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();

            $dataBody    = (object) $request->all();
            $userCurrent = User::where("uuid", $dataBody->userCode)->first();

            PaymentLog::where("user_id", $userCurrent->id)->update(["state"               => PaymentLog::RESET]);
            PaymentOrderPoint::where("user_id", $userCurrent->id)
                ->whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
                ->update(["state" => false]);
            PaymentProductOrder::where("user_id", $userCurrent->id)->update(["state"      => PaymentProductOrder::TERMINADO]);
            PaymentProductOrderPoint::where("user_id", $userCurrent->id)->update(["state" => false]);
            RangeUser::where("user_id", $userCurrent->id)->update(["status"               => false]);

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function createUser(Request $request)
    {
        $location = $request->validate(['country' => 'nullable|string|max:255']);

        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();

            if (!isset($dataBody->name, $dataBody->email, $dataBody->dni, $dataBody->sponsor, $dataBody->plan, $dataBody->password)) {
                DB::rollBack();
                return $this->sendError("Faltan datos requeridos: name, email, dni, sponsor, plan, password");
            }

            if (User::where("email", $dataBody->email)->exists()) {
                DB::rollBack();
                return $this->sendError("Ese correo electrónico ya existe");
            }

            if (User::where("uuid", trim($dataBody->dni))->exists()) {
                DB::rollBack();
                return $this->sendError("Este DNI ya existe");
            }

            $sponsor = app(\App\Services\Core\AffiliationService::class)->sponsor((string) $dataBody->sponsor);
            if (User::where('email', $dataBody->email)->orWhere('uuid', trim($dataBody->dni))->exists()) {
                throw new \RuntimeException('El correo o DNI ya esta registrado.');
            }

            $packCurrent     = Pack::find($dataBody->plan);
            if ($packCurrent == null) {
                DB::rollBack();
                return $this->sendError("No existe el plan seleccionado");
            }

            if ($packCurrent->isStarter() && (!Auth::user()?->is_admin || !$packCurrent->state)) {
                DB::rollBack();
                return $this->sendError('El Kit de Inicio requiere una asignacion administrativa habilitada.', [], 403);
            }

            $userCreated = User::create([
                'name'     => $dataBody->name,
                'email'    => $dataBody->email,
                'uuid'     => trim($dataBody->dni),
                'password' => bcrypt($dataBody->password),
                'country' => $location['country'] ?? null,
            ]);

            app(\App\Services\Core\AffiliationService::class)->attach($userCreated, $sponsor->uuid, 'admin_registration');

            $codeGenerator = new CodeGenerator();
            VerificationCodeUser::create([
                'user_id' => $userCreated->id,
                'type'    => 1,
                'code'    => $codeGenerator->generate(),
                "state"   => true
            ]);

            if ($packCurrent->isStarter()) {
                $userCreated->assignPack($packCurrent);
            } else {
                $_paymentOrder = PaymentOrder::create([
                    'currency'     => "PEN",
                    'amount'       => $packCurrent->price,
                    'sponsor_code' => $sponsor->uuid,
                    'pack_id'      => $dataBody->plan,
                    "token"        => uniqid($packCurrent->title)
                ]);

                PaymentLog::create([
                    'payment_order_id' => $_paymentOrder->id,
                    "confirm"          => true,
                    'user_id'          => $userCreated->id,
                    "state"            => PaymentLog::PAGADO,
                    "message"          => "Activación de nuevo socio: " . $packCurrent->title
                ]);

                // Llamada al servicio
                $this->commissionService->confirmPoint($_paymentOrder, $userCreated, $packCurrent);
            }

            DB::commit();
            \Illuminate\Support\Facades\Cache::forget('existing_user_uuids');

            return $this->sendResponse([
                'user_id' => $userCreated->id,
                'uuid'    => $userCreated->uuid,
                'message' => $packCurrent->isStarter() ? 'Usuario creado. Kit de Inicio asignado.' : 'Usuario y red de puntos activada correctamente.'
            ], 'Usuario creado exitosamente');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError("Fallo en registro: " . $e->getMessage(), [], 500);
        }
    }

    public function treeList(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userCode' => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $user_id   = Auth::id();
            $userModel = User::with(['file'])->find($user_id);

            if (!$userModel->is_admin) return $this->sendError("No tiene permisos ese usuario");

            $dataBody = (object) $request->all();
            
            // Usamos el servicio
            $list = $this->networkTreeService->loopTree([], $dataBody->userCode);

            return $this->sendResponse($list, '');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function invitedLink(Request $request)
    {
        try {
            $userId = Auth::id();
            DB::beginTransaction();
            $dateNow = Carbon::now();

            $userModel = User::with(['paymentActive'])->find($userId);
            $token     = (string) Str::uuid();

            InviteUser::create([
                'sponsor_user_id'   => $userId,
                'sponsor_user_code' => $userModel->uuid,
                'token'             => $token,
                'state'             => true,
                'type'              => InviteUser::LINK,
                'expired_time'      => $dateNow->addHours(2),
            ]);
            DB::commit();
            return $this->sendResponse(['code' => $token], '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function invitedLinkEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'users'        => 'required|array|min:1',
            'users.*.code' => 'required|distinct|exists:users,uuid',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $userId = Auth::id();
            $userModel = User::with(['paymentActive'])->find($userId);
            $dataBody  = (object) $request->all();
            $token     = (string) Str::uuid();

            InviteUser::create([
                'sponsor_user_id'   => $userId,
                'sponsor_user_code' => $userModel->uuid,
                'token'             => $token,
                'state'             => true,
                'type'              => InviteUser::EMAIL,
                'expired_time'      => now()->addHours(2),
            ]);

            $origin = $request->headers->get('Origin');
            $frontend = in_array($origin, config('cors.allowed_origins', []), true)
                ? $origin
                : config('app.frontend_url');
            $url = rtrim($frontend, '/') . '/guest/' . $token;

            foreach ($dataBody->users as $key => $user) {
                $user        = (object) $user;
                $userInvited = User::where("uuid", $user->code)->first();
                $mailData    = [
                    'invited_name' => $userInvited->name,
                    "sponsor_name" => $userModel->name,
                    'url'          => $url
                ];
                Mail::to($userInvited->email)->send(new InivitedSponsorUser($mailData));
            }

            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            report($e);
            return $this->sendError('No se pudieron enviar todas las invitaciones.', [], 502);
        }
    }

    public function invitedVerify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $dataBody = (object) $request->all();

            $dateNow    = Carbon::now();
            $inviteUser = InviteUser::where('token', '=', $dataBody->token)->first();

            if ($inviteUser        == null) return $this->sendResponse("", "No existe el codigo de invitación.", false);
            if ($inviteUser->state == false) return $this->sendResponse("", "El codigo de invitación esta desabilitado.", false);
            if (Carbon::parse($inviteUser->expired_time)->lte($dateNow)) return $this->sendResponse("", "El codigo de invitación ha expirado.", false);

            $sponsor = User::where('uuid', $inviteUser->sponsor_user_code)->first();
            if (!$sponsor || !$this->networkTreeService->belongsToNetwork(
                $this->networkTreeService->rootCode(), $sponsor->uuid
            )) {
                return $this->sendResponse("", "El patrocinador de la invitacion no existe.", false);
            }
            return $this->sendResponse([
                'token' => $dataBody->token,
                'sponsor_code' => $sponsor->uuid,
                'sponsor_name' => $sponsor->name,
            ], '');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function invitedConfirm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token'  => 'required',
            "accept" => 'required|boolean',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $userId    = Auth::id();
            $userModel = User::with(['paymentActive'])->find($userId);

            DB::beginTransaction();
            $this->networkTreeService->lockRootForMutation();
            $dateNow    = Carbon::now();
            $dataBody   = (object) $request->all();
            $inviteUser = InviteUser::where('token', '=', $dataBody->token)->lockForUpdate()->first();

            $paymentLog       = PaymentLog::where("user_id", $userId)->whereIn("state", [PaymentLog::PAGADO, PaymentLog::TERMINADO])->first();
            if ($paymentLog != null) { DB::rollBack(); return $this->sendResponse("", "Este usuario ya tiene un patrocinador.", false); }

            if ($inviteUser        == null) { DB::rollBack(); return $this->sendResponse("", "No existe el codigo de invitación.", false); }
            if ($inviteUser->state == false) { DB::rollBack(); return $this->sendResponse("", "El codigo de invitación esta desabilitado.", false); }
            if (Carbon::parse($inviteUser->expired_time)->lte($dateNow)) { DB::rollBack(); return $this->sendResponse("", "El codigo de invitación ha expirado.", false); }

            GuestsTokenUser::create([
                'sponsor_user_code' => $inviteUser->sponsor_user_code,
                'guest_user_code'   => $userModel->uuid,
                'invite_user_id'    => $inviteUser->id,
                'state'             => $dataBody->accept
            ]);

            if ($request->boolean('accept')) {
                app(\App\Services\Core\AffiliationService::class)->attach($userModel, $inviteUser->sponsor_user_code, 'invitation');
            }

            InviteUser::where('token', '=', $dataBody->token)->update(["state" => false]);

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (\Throwable $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function invitedUserCode(Request $request)
    {
        try {
            $userId    = Auth::id();
            $userModel = User::with(['paymentActive'])->find($userId);

            if (!$userModel) {
                return $this->sendResponse("", "Usuario no encontrado", false);
            }

            // La genealogia vigente se consulta exclusivamente en sponsor_relations.
            $sponsorCode = $this->networkTreeService->sponsorCode($userModel->uuid);
            if (!$sponsorCode) {
                return $this->sendResponse("", "No tiene ningun sponsor invitado", false);
            }

            return $this->sendResponse($sponsorCode, '');
        } catch (\Throwable $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function invitedUserCodeRemove(Request $request)
    {
        try {
            $userId    = Auth::id();
            $userModel = User::with(['paymentActive'])->find($userId);

            DB::beginTransaction();
            $this->networkTreeService->lockRootForMutation();
            GuestsTokenUser::where("guest_user_code", $userModel->uuid)
                ->where("state", true)
                ->update(["state" => false]);
            // Established genealogy survives invitation cancellation.

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    private function latestOwnedPackagePayment(int $userId)
    {
        $service = PaymentLog::with('paymentOrder.pack')
            ->where('user_id', $userId)
            ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO, PaymentLog::RESET])
            ->latest('created_at')
            ->first();
        $product = PaymentProductOrder::with('pack')
            ->where('user_id', $userId)
            ->whereIn('state', [
                PaymentProductOrder::PAGADO,
                PaymentProductOrder::ENVIADO,
                PaymentProductOrder::TERMINADO,
            ])
            ->latest('created_at')
            ->first();

        return collect([$service, $product])
            ->filter()
            ->sortByDesc('created_at')
            ->first();
    }

    private function displayPaymentPayload($payment, bool $isActive)
    {
        if (!$payment) return null;

        $displayState = $isActive ? PaymentLog::PAGADO : PaymentLog::TERMINADO;
        $expiresAt = $isActive ? now()->endOfMonth()->toIso8601String() : null;
        if (!$payment instanceof PaymentProductOrder) {
            $payload = $payment->toArray();
            $payload['state'] = $displayState;
            $payload['expires_at'] = $expiresAt;
            return $payload;
        }

        // Contrato uniforme para consumidores que historicamente leen
        // payment.payment_order.pack, aunque la compra venga de productos.
        return [
            'id' => $payment->id,
            'state' => $displayState,
            'expires_at' => $expiresAt,
            'created_at' => $payment->created_at,
            'pack_id' => $payment->pack_id,
            'pack' => $payment->pack,
            'payment_order' => [
                'id' => $payment->id,
                'pack_id' => $payment->pack_id,
                'pack' => $payment->pack,
            ],
        ];
    }

    private function ownedPacksByCategory(
        User $user,
        $activation,
        \Illuminate\Support\Collection $logs,
        \Illuminate\Support\Collection $orders
    ): array {
        if ($user->assignedPack) {
            return $this->assignedPackCategories($user, $activation);
        }

        $logs = $logs->sortByDesc('created_at');
        $orders = $orders->sortByDesc('created_at');

        return collect([
            'product' => 'Producto',
            'service' => 'Servicio',
        ])->mapWithKeys(function (string $storedCategory, string $category) use ($logs, $orders, $activation, $user) {
            $candidates = $logs->filter(fn (PaymentLog $log) =>
                strcasecmp(trim((string) $log->paymentOrder?->pack?->category), $storedCategory) === 0
            )->map(fn (PaymentLog $log) => [
                'pack' => $log->paymentOrder?->pack,
                'created_at' => $log->created_at,
            ])->merge($orders->filter(fn (PaymentProductOrder $order) =>
                strcasecmp(trim((string) $order->pack?->category), $storedCategory) === 0
            )->map(fn (PaymentProductOrder $order) => [
                'pack' => $order->pack,
                'created_at' => $order->created_at,
            ]))->filter(fn ($item) => $item['pack'])->sortByDesc('created_at');

            $latest = $candidates->first();
            return [$category => [
                'owned' => $latest !== null,
                'active' => $activation->isActiveForCategory($user, $category),
                'pack' => $latest['pack'] ?? null,
            ]];
        })->all();
    }

    private function assignedPackCategories(User $user, \App\Services\Core\ActivationService $activation): array
    {
        $pack = $user->assignedPack;
        $category = strcasecmp($pack->category, 'Servicio') === 0 ? 'service' : 'product';

        return collect(['product', 'service'])->mapWithKeys(fn (string $key) => [$key => [
            'owned' => $key === $category,
            'active' => $key === $category && $activation->isActiveForCategory($user, $key),
            'pack' => $key === $category ? $pack : null,
            'assigned' => $key === $category,
        ]])->all();
    }

    private function sponsorshipTotal($points): float
    {
        return $this->commissionTotal($points, ['P', 'PS', 'S']);
    }

    private function commissionTotal($points, array $types): float
    {
        return (float) $points->whereIn('type', $types)->groupBy(function ($point) {
            $type = $point->type === 'S' ? 'PS' : $point->type;
            return implode('|', [$point->payment_order_id ?: 'ROW-'.$point->id,
                strtoupper((string) $point->user_code), (int) ($point->level ?? 0), $type]);
        })->sum(fn ($rows) => (float) $rows->max('point'));
    }
}
