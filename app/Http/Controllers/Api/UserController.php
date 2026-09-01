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
use App\Models\SponsorRelation;
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
        $userModel = User::with(['file', 'range.range.file'])->select("*", "created_at as creatxlssed")->find($user_id);

        if (!$userModel) return $this->sendError("Usuario no encontrado");

        $now           = Carbon::now('America/Lima');
        $currentMonth  = $now->month;
        $currentYear   = $now->year;
        $mesAnterior   = $now->copy()->subMonth();
        $isGracePeriod = app(\App\Services\Core\ActivationService::class)->isMonthlyGracePeriod($now);

        $servicePayment = PaymentLog::with(['paymentOrder.pack'])
            ->where("user_id", $user_id)->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])->orderBy('created_at', 'desc')->first();
        $productPayment = PaymentProductOrder::with(['pack', 'details.product'])
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

        if ($userModel->is_admin || strcasecmp((string) $userModel->uuid, 'DOSB') === 0) {
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
        $userModel->package_name = $userModel->package_name;
        $userModel->active       = $isActive;

        // 🔥 CORRECCIÓN 1: OBTENER TODOS LOS PUNTOS ACTIVOS (state = 1) DEL MES FILTRADO
        // Incluimos todos los tipos: B, G, R, P, S, I
        $paymentOrderPoints = PaymentOrderPoint::query()
            ->when(!$isGracePeriod, fn ($query) => $query->where('state', 1))
            ->whereMonth('created_at', $mesFiltro)
            ->whereYear('created_at', $anioFiltro)
            ->whereIn('type', ['B', 'G', 'R', 'RS', 'P', 'PS', 'S', 'I']) // TODOS LOS TIPOS
            ->get();
        // El Home muestra solo las ganancias del periodo seleccionado.
        // El historial permanece disponible en los reportes financieros.

        // 🔥 CORRECCIÓN 2: FILTRAR SOLO LOS PUNTOS DEL USUARIO ACTUAL
        $paymentOrderPointsUser = $paymentOrderPoints->filter(function ($point) use ($userModel) {
            return strtoupper($point->user_code) == strtoupper($userModel->uuid);
        })->values();

        // 🔥 CORRECCIÓN 3: CALCULAR PUNTOS POR TIPO (Todos los tipos)
        $puntosPersonales = $paymentOrderPointsUser->where('type', 'B')->sum('point'); // COMPRA
        $puntosRed        = $paymentOrderPointsUser->where('type', 'G')->sum('point'); // GRUPAL
        $residualProducto = $this->commissionTotal($paymentOrderPointsUser, ['R']);
        $residualServicio = $this->commissionTotal($paymentOrderPointsUser, ['RS']);
        $puntosResiduales = $residualProducto + $residualServicio;
        $gananciaPatrocinio = $this->sponsorshipTotal($paymentOrderPointsUser);
        $puntosInfinito   = $paymentOrderPointsUser->where('type', 'I')->sum('point'); // INFINITO

        // DOSB es la cuenta corporativa y no depende de plan, actividad ni
        // rango. Su Home usa el mismo libro que Finanzas/Excel para evitar que
        // una segunda lógica deje en cero comisiones que sí existen.
        if ($userModel->is_admin || strcasecmp((string) $userModel->uuid, 'DOSB') === 0) {
            $companyCommissions = app(FinancialLedgerService::class)->summary(
                Carbon::create($anioFiltro, $mesFiltro, 1)->startOfMonth(),
                Carbon::create($anioFiltro, $mesFiltro, 1)->endOfMonth(),
                $userModel->uuid
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

        // 🔥 CORRECCIÓN 5: LÓGICA PARA DOSB (CORPORATIVO)
        if (strtoupper($userModel->uuid) == 'DOSB') {
            $directosLegacy = $this->networkTreeService->directUserCodes($userModel->uuid);

            $userModel->directos = count($directosLegacy);
            $activos = 0;
            foreach ($directosLegacy as $guestCode) {
                $user = User::where('uuid', $guestCode)->first();
                if ($user) {
                    $hasPayment = PaymentLog::where('user_id', $user->id)
                        ->whereIn('state', [PaymentLog::PAGADO])
                        ->whereMonth('created_at', $now->month)
                        ->whereYear('created_at', $now->year)
                        ->exists();
                    $hasProduct = PaymentProductOrder::where('user_id', $user->id)
                        ->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO])
                        ->whereMonth('created_at', $now->month)
                        ->whereYear('created_at', $now->year)
                        ->exists();
                    if ($hasPayment || $hasProduct) $activos++;
                }
            }
            $networkUsers = $this->networkTreeService->getAllNetworkUsers('DOSB');
            $descendantCodes = array_values(array_filter(
                $networkUsers,
                fn ($code) => strcasecmp($code, 'DOSB') !== 0
            ));

            $monthlyGroupVolume = DB::query()->fromSub(
                PaymentOrderPoint::select('payment_order_id', DB::raw('MAX(point) as point'))
                    ->whereIn('user_code', $descendantCodes)
                    ->where('state', true)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->whereMonth('created_at', $mesFiltro)
                    ->whereYear('created_at', $anioFiltro)
                    ->groupBy('payment_order_id'),
                'monthly_network_purchases'
            )->sum('point');

            $userModel->activos = $activos;
            $userModel->red_total = count($descendantCodes);
            $userModel->personas_red = count($descendantCodes);
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
            $todosDirectos       = $this->networkTreeService->directUserCodes($userModel->uuid);
            $userModel->directos = count($todosDirectos);

            $activos = 0;
            foreach ($todosDirectos as $directCode) {
                $user = User::where('uuid', $directCode)->first();
                if ($user) {
                    $hasActivePayment = PaymentLog::where('user_id', $user->id)
                        ->whereIn('state', [PaymentLog::PAGADO])
                        ->whereMonth('created_at', $mesFiltro)
                        ->whereYear('created_at', $anioFiltro)
                        ->exists();
                    $hasActiveProduct = PaymentProductOrder::where('user_id', $user->id)
                        ->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO])
                        ->whereMonth('created_at', $mesFiltro)
                        ->whereYear('created_at', $anioFiltro)
                        ->exists();
                    if ($hasActivePayment || $hasActiveProduct) {
                        $activos++;
                    }
                }
            }
            $userModel->activos = $activos;
            $userModel->red_total = $this->networkTreeService->countTotalNetworkRecursive($userModel->uuid);
            $userModel->personas_red = $userModel->red_total;

            // 🔥 CORRECCIÓN: OBJETO DE PUNTOS CON TODOS LOS TIPOS
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

        $userModel->totalPoints = $totalPoints;

        // Sincronizar el rango con el mismo volumen B + G que muestra el perfil.
        app(RangeQualificationService::class)->recalculateUser($userModel);
        $userModel->load('range.range.rule');
        $rangeCurrent = $userModel->range?->range;
        $nextRange = Range::where('state', true)
            ->when($rangeCurrent, fn ($query) => $query->where('order', '>', $rangeCurrent->order))
            ->orderBy('order')->first();
        $progressTarget = $nextRange ?: $rangeCurrent;
        $rangeProgress = $progressTarget && (float) $progressTarget->points > 0
            ? min(100, round(((float) $totalPoints / (float) $progressTarget->points) * 100, 2))
            : 0;

        $responsePayload                    = $userModel->toArray();
        // `points` conserva el resumen tipado que consume el home. Exponer los
        // movimientos aparte evita sumar comisiones R/P/I como volumen B/G.
        $responsePayload['point_records'] = $paymentOrderPointsUser->values()->toArray();
        $responsePayload['volume_records'] = $paymentOrderPointsUser
            ->whereIn('type', ['B', 'G'])->values()->toArray();
        $responsePayload['commission_records'] = $paymentOrderPointsUser
            ->whereIn('type', ['P', 'PS', 'S', 'R', 'RS', 'I'])->values()->toArray();
        $responsePayload['legacy_count']    = $legacyTokens->count();
        $responsePayload['network_summary'] = [
            'total_directs'      => $userModel->directos ?? 0,
            'total_active'       => $userModel->activos ?? 0,
            'total_network'      => $userModel->red_total ?? 0,
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
            'paquete_actual'      => $userModel->package_name ?? 'Sin paquete',
            'rango_actual'        => $rangeCurrent ? $rangeCurrent->title : 'Sin rango'
        ];
        $responsePayload['current_range'] = $rangeCurrent;
        $responsePayload['next_range'] = $nextRange;
        $responsePayload['range_progress'] = $rangeProgress;
        $responsePayload['points_to_next_range'] = $nextRange
            ? max(0, (float) $nextRange->points - (float) $totalPoints)
            : 0;

        return $this->sendResponse((object)$responsePayload, 'Perfil sincronizado');
    } catch (Exception $e) {
        return $this->sendError("Fallo de integridad: " . $e->getMessage());
    }
}

    public function authUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), []);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();
            $user_id  = Auth::id();
            User::where("id", $user_id)->update([
                "address" => $dataBody->address,
                "phone"   => $dataBody->phone,
                'city'    => $dataBody->city,
                'country' => $dataBody->country,
                'gender'  => $dataBody->gender,
            ]);

            DB::commit();
            return $this->sendResponse(true, 'User');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
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

        $userList = User::with([
            'file', 
            'range.range.file'
        ])->where('is_admin', false);

        if ($request->has('code') && !empty($request->query('code'))) $userList = $userList->where("uuid", 'like', $request->query('code'));
        if ($request->has('email') && !empty($request->query('email'))) $userList = $userList->where("email", 'like', $request->query('email'));
        if ($request->has('name') && !empty($request->query('name'))) $userList = $userList->where("name", 'like', '%' . ($request->query('name')) . '%');

        if ($request->has('plan') && !empty($request->query('plan'))) {
            $plan = $request->query('plan');
            if ($plan == -1) {
                // Un pack adquirido es permanente. Que el periodo haya terminado o
                // sido reseteado no significa que el usuario nunca tuvo un plan.
                $serviceOwners = PaymentLog::whereIn('state', [
                    PaymentLog::PAGADO,
                    PaymentLog::TERMINADO,
                    PaymentLog::RESET,
                ])->pluck('user_id');
                $productOwners = PaymentProductOrder::whereIn('state', [
                    PaymentProductOrder::PAGADO,
                    PaymentProductOrder::ENVIADO,
                    PaymentProductOrder::TERMINADO,
                ])->pluck('user_id');
                $ownerIds = $serviceOwners->merge($productOwners)->filter()->unique()->values();
                $userList = $userList->whereNotIn("id", $ownerIds);
            } else {
                $serviceOwners = PaymentLog::whereIn('state', [
                    PaymentLog::PAGADO,
                    PaymentLog::TERMINADO,
                    PaymentLog::RESET,
                ])->whereHas("paymentOrder.pack", function ($q) use ($plan) {
                    $q->where('id', $plan);
                })->pluck('user_id');
                $productOwners = PaymentProductOrder::whereIn('state', [
                    PaymentProductOrder::PAGADO,
                    PaymentProductOrder::ENVIADO,
                    PaymentProductOrder::TERMINADO,
                ])->where('pack_id', $plan)->pluck('user_id');
                $ownerIds = $serviceOwners->merge($productOwners)->filter()->unique()->values();
                $userList = $userList->whereIn("id", $ownerIds);
            }
        }

        $userList = $userList->orderBy('created_at', 'desc')->paginate($limit);

        $now = Carbon::now('America/Lima');
        $currentMonth = $now->month;
        $currentYear = $now->year;
        $mesAnterior = $now->copy()->subMonth();
        $isGracePeriod = app(\App\Services\Core\ActivationService::class)->isMonthlyGracePeriod($now);

        // 🔥 Obtener TODOS los puntos activos del mes
        $allPaymentOrderPoints = PaymentOrderPoint::with(['paymentOrder.pack'])
            ->where('state', 1)
            ->whereMonth('created_at', $currentMonth)->whereYear('created_at', $currentYear)
            ->get();

        $allProductOrderPoints = PaymentProductOrderPoint::where("state", true)
            ->whereMonth('created_at', $currentMonth)->whereYear('created_at', $currentYear)
            ->get();

        // 🔥 Puntos del mes anterior (período de gracia)
        $allPaymentOrderPointsLastMonth = PaymentOrderPoint::with(['paymentOrder.pack'])
            ->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year)
            ->get();

        $allProductOrderPointsLastMonth = PaymentProductOrderPoint::query()
            ->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year)
            ->get();

        $userIds = collect($userList->items())->pluck('uuid')->toArray();
        $paginatedUserIds = collect($userList->items())->pluck('id')->toArray();
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

        foreach ($userList as $key => $user) {
            $servicePayment = PaymentLog::with(['paymentOrder.pack', 'paymentOrder.sponsor.file'])
                ->where("user_id", $user->id)->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])->orderBy('created_at', 'desc')->first();
            $productPayment = PaymentProductOrder::with(['pack'])
                ->where("user_id", $user->id)->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO])->orderBy('created_at', 'desc')->first();

            $ultimoPago = collect([$servicePayment, $productPayment])->filter()->sortByDesc('created_at')->first();
            $displayPayment = $this->latestOwnedPackagePayment($user->id);

            $isActive = false;
            $mesFiltro = $currentMonth;
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

            if ($user->is_admin || strcasecmp((string) $user->uuid, 'DOSB') === 0) {
                $isActive = true;
            }

            if (!$isActive && $ultimoPago) $ultimoPago->state = 6;
            $isActive = app(\App\Services\Core\ActivationService::class)->isActive($user);
            $sponsorCode = $this->networkTreeService->sponsorCode($user->uuid);
            $sponsor = $sponsorCode
                ? User::whereRaw('UPPER(uuid) = ?', [strtoupper($sponsorCode)])->first()
                : null;
            $userList[$key]->payment = $this->displayPaymentPayload($displayPayment, $isActive);
            $userList[$key]->active = $isActive;
            $userList[$key]->package_name = $user->package_name;
            $userList[$key]->sponsor_code = $sponsor?->uuid;
            $userList[$key]->sponsor_uuid = $sponsor?->uuid;
            $userList[$key]->sponsor_name = $sponsor?->name;
            $userList[$key]->sponsor = $sponsor ? [
                'uuid' => $sponsor->uuid,
                'name' => $sponsor->name,
            ] : null;
            $activation = app(\App\Services\Core\ActivationService::class);
            $userList[$key]->active_product = $activation->isActiveForCategory($user, 'product');
            $userList[$key]->active_service = $activation->isActiveForCategory($user, 'service');
            $userList[$key]->packs_by_category = $this->ownedPacksByCategory($user, $activation);
            // Esta marca solo corresponde a reactivaciones mensuales registradas.
            // Una compra normal de paquete o productos no habilita la desactivación.
            $userList[$key]->manual_reactivation_active = in_array(
                (int) $user->id,
                $activeManualReactivationUserIds,
                true
            );

            // 🔧 Seleccionar los puntos según el mes filtrado
            $puntosDisponibles = ($mesFiltro == $currentMonth) ? $allPaymentOrderPoints : $allPaymentOrderPointsLastMonth;
            $productosDisponibles = ($mesFiltro == $currentMonth) ? $allProductOrderPoints : $allProductOrderPointsLastMonth;

            // 🔧 Filtrar SOLO los puntos del usuario actual
            $popUsuario = $puntosDisponibles->filter(function ($point) use ($user) {
                return strtoupper($point->user_code) == strtoupper($user->uuid);
            })->values();

            $ppopUsuario = $productosDisponibles->filter(function ($point) use ($user) {
                return $point->user_id == $user->id;
            })->values();

            // 🔥 Esto genera el objeto points CON TODOS LOS TIPOS correctos
            $userPoints = $this->calculator->points($user->uuid, $popUsuario, $ppopUsuario);
            
            // 🔥 El total de puntos es el que ya calcula el objeto points (personal + pointGroup + residual)
            $userList[$key]->totalPoints = $userPoints->total_general;

            // 🔥 ASIGNAR EL OBJETO COMPLETO AL USUARIO
            $userList[$key]->points = $userPoints;

            // Keep the modal payload aligned with the `points` object used by the table.
            $userList[$key]->user_detail = [
                'paquete_actual'      => $user->package_name,
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
            app(RangeQualificationService::class)->recalculateUser($user);
            $user->unsetRelation('range');
            $user->load('range.range.file');
            $currentRange = $user->range?->range;

            $nextRange = $activeRanges->first(function ($candidate) use ($currentRange) {
                return !$currentRange || (int) $candidate->order > (int) $currentRange->order;
            });
            $progressTarget = $nextRange ?: $currentRange;
            $progress = $progressTarget && (float) $progressTarget->points > 0
                ? min(100, round(((float) $userPoints->total_general / (float) $progressTarget->points) * 100, 2))
                : 0;

            $userList[$key]->setRelation('range', $user->range);
            $userList[$key]->next_range = $nextRange;
            $userList[$key]->range_progress = $progress;
            $userList[$key]->points_to_next_range = $nextRange
                ? max(0, (float) $nextRange->points - (float) $userPoints->total_general)
                : 0;

            // 🔥 Agregar los bonos históricos
            $userList[$key]->bonos_totales_historico = $historicalBonuses->get($user->uuid, 0);
        }

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

            $packId    = ($request->has('packId') && $request->packId > 0) ? $request->packId : null;
            $serviceId = ($request->has('serviceId') && $request->serviceId > 0) ? $request->serviceId : null;

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
                    $existingPackRecord->update(['state' => false]);
                    if ($existingPackRecord->paymentOrder) {
                        PaymentLog::where('payment_order_id', $existingPackRecord->payment_order_id)
                            ->update(['state' => PaymentLog::TERMINADO]);
                    }
                }

                $newOrder = PaymentOrder::create([
                    'currency'     => "PEN",
                    'amount'       => 0,
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

            if (!$userModel->is_admin) return $this->sendError("No tiene permisos ese usuario");

            $userList = User::with(['file']);

            if ($request->has('code')) if (!empty($request->query('code'))) $userList   = $userList->where("uuid", 'like', $request->query('code'));
            if ($request->has('email')) if (!empty($request->query('email'))) $userList = $userList->where("email", 'like', $request->query('email'));
            if ($request->has('name')) if (!empty($request->query('name'))) $userList   = $userList->where("name", 'like', '%' . ($request->query('name')) . '%');

            $userList = $userList->orderBy('created_at', 'desc')->get();

            foreach ($userList as $key => $user) {
                $ownedPayment = $this->latestOwnedPackagePayment($user->id);
                $userList[$key]->payment = $this->displayPaymentPayload(
                    $ownedPayment,
                    app(ActivationService::class)->isActive($user)
                );
                $userList[$key]->package_name = $user->package_name;
            }

            return $this->sendResponse($userList, 'Lista');
        } catch (\Throwable $th) {
            return $this->sendError($th->getMessage());
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

            $sponsor     = User::where("uuid", $dataBody->sponsor)->first();
            if ($sponsor == null) {
                DB::rollBack();
                return $this->sendError('Código de Patrocinador no existe.');
            }

            $packCurrent     = Pack::find($dataBody->plan);
            if ($packCurrent == null) {
                DB::rollBack();
                return $this->sendError("No existe el plan seleccionado");
            }

            $userCreated = User::create([
                'name'     => $dataBody->name,
                'email'    => $dataBody->email,
                'uuid'     => trim($dataBody->dni),
                'password' => bcrypt($dataBody->password)
            ]);

            $codeGenerator = new CodeGenerator();
            VerificationCodeUser::create([
                'user_id' => $userCreated->id,
                'type'    => 1,
                'code'    => $codeGenerator->generate(),
                "state"   => true
            ]);

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

            DB::commit();
            \Illuminate\Support\Facades\Cache::forget('existing_user_uuids');

            return $this->sendResponse([
                'user_id' => $userCreated->id,
                'uuid'    => $userCreated->uuid,
                'message' => 'Usuario y red de puntos activada correctamente.'
            ], 'Usuario creado exitosamente');
        } catch (Exception $e) {
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
            'users'        => 'required|array',
            'users.*.code' => 'required|exists:users,uuid',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $userId = Auth::id();
            DB::beginTransaction();
            $dateNow   = Carbon::now();
            $userModel = User::with(['paymentActive'])->find($userId);
            $dataBody  = (object) $request->all();
            $token     = (string) Str::uuid();

            InviteUser::create([
                'sponsor_user_id'   => $userId,
                'sponsor_user_code' => $userModel->uuid,
                'token'             => $token,
                'state'             => true,
                'type'              => InviteUser::EMAIL,
                'expired_time'      => $dateNow->addHours(2),
            ]);

            InviteUser::where('expired_time', '<', $dateNow)->update(["state" => false]);
            $url = env('APP_URL_FRONT') . '/guest/' . $token;

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

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
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
            DB::beginTransaction();

            $dateNow    = Carbon::now();
            $inviteUser = InviteUser::where('token', '=', $dataBody->token)->first();

            if ($inviteUser        == null) return $this->sendResponse("", "No existe el codigo de invitación.", false);
            if ($inviteUser->state == false) return $this->sendResponse("", "El codigo de invitación esta desabilitado.", false);
            if ($inviteUser->expired_time < $dateNow) return $this->sendResponse("", "El codigo de invitación ha expirado.", false);

            $sponsor = User::where('uuid', $inviteUser->sponsor_user_code)->first();
            if (!$sponsor) {
                DB::rollBack();
                return $this->sendResponse("", "El patrocinador de la invitacion no existe.", false);
            }

            DB::commit();
            return $this->sendResponse([
                'token' => $dataBody->token,
                'sponsor_code' => $sponsor->uuid,
                'sponsor_name' => $sponsor->name,
            ], '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function invitedConfirm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token'  => 'required',
            "accept" => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $userId    = Auth::id();
            $userModel = User::with(['paymentActive'])->find($userId);

            DB::beginTransaction();
            $dateNow    = Carbon::now();
            $dataBody   = (object) $request->all();
            $inviteUser = InviteUser::where('token', '=', $dataBody->token)->first();

            $paymentLog       = PaymentLog::where("user_id", $userId)->whereIn("state", [PaymentLog::PAGADO, PaymentLog::TERMINADO])->first();
            if ($paymentLog != null) return $this->sendResponse("", "Este usuario ya tiene un patrocinador.", false);

            if ($inviteUser        == null) return $this->sendResponse("", "No existe el codigo de invitación.", false);
            if ($inviteUser->state == false) return $this->sendResponse("", "El codigo de invitación esta desabilitado.", false);
            if ($inviteUser->expired_time < $dateNow) return $this->sendResponse("", "El codigo de invitación ha expirado.", false);

            GuestsTokenUser::create([
                'sponsor_user_code' => $inviteUser->sponsor_user_code,
                'guest_user_code'   => $userModel->uuid,
                'invite_user_id'    => $inviteUser->id,
                'state'             => $dataBody->accept
            ]);

            if ($dataBody->accept && strcasecmp($inviteUser->sponsor_user_code, $userModel->uuid) !== 0) {
                SponsorRelation::updateOrCreate(
                    ['user_code' => $userModel->uuid],
                    ['sponsor_code' => $inviteUser->sponsor_user_code, 'source' => 'invitation', 'state' => true]
                );
            }

            InviteUser::where('token', '=', $dataBody->token)->update(["state" => false]);

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
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

            // sponsor_relations es la fuente actual del arbol. El servicio ya
            // conserva compatibilidad con puntos de compra antiguos.
            $sponsorCode = $this->networkTreeService->sponsorCode($userModel->uuid);

            // Respaldo para invitaciones legacy que aun no fueron migradas.
            if (!$sponsorCode) {
                $sponsorCode = GuestsTokenUser::where("guest_user_code", $userModel->uuid)
                    ->where("state", true)
                    ->value('sponsor_user_code');
            }

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
            GuestsTokenUser::where("guest_user_code", $userModel->uuid)
                ->where("state", true)
                ->update(["state" => false]);
            SponsorRelation::where('user_code', $userModel->uuid)
                ->where('source', 'invitation')
                ->update(['state' => false]);

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

    private function ownedPacksByCategory(User $user, $activation): array
    {
        $logs = PaymentLog::with('paymentOrder.pack')
            ->where('user_id', $user->id)
            ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO, PaymentLog::RESET])
            ->latest('created_at')->get();
        $orders = PaymentProductOrder::with('pack')
            ->where('user_id', $user->id)
            ->whereIn('state', [
                PaymentProductOrder::PAGADO,
                PaymentProductOrder::ENVIADO,
                PaymentProductOrder::TERMINADO,
            ])
            ->latest('created_at')->get();

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
