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

            $now           = Carbon::now();
            $currentMonth  = $now->month;
            $currentYear   = $now->year;
            $mesAnterior   = $now->copy()->subMonth();
            $isGracePeriod = $now->day <= 2;

            $servicePayment = PaymentLog::with(['paymentOrder.pack'])
                ->where("user_id", $user->id)->whereIn('state', [2, 6])->orderBy('created_at', 'desc')->first();
            $productPayment = PaymentProductOrder::with(['pack'])
                ->where("user_id", $user->id)->whereIn('state', [2, 3, 6])->orderBy('created_at', 'desc')->first();

            $ultimoPago = collect([$servicePayment, $productPayment])->filter()->sortByDesc('created_at')->first();

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
            $user->payment = $ultimoPago;

            // =========================================================
            // 🔥 CÁLCULO DE PUNTOS (EXACTO AL DE auth())
            // =========================================================
            $paymentOrderPoints = PaymentOrderPoint::where('state', 1)
                ->whereMonth('created_at', $mesFiltro)
                ->whereYear('created_at', $anioFiltro)
                ->whereIn('type', ['B', 'G', 'R', 'P', 'S', 'I'])
                ->get();

            $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $user->id)
                ->where("state", true)
                ->whereMonth('created_at', $mesFiltro)
                ->whereYear('created_at', $anioFiltro)
                ->get();

            $paymentOrderPointsUser = $paymentOrderPoints->filter(function ($point) use ($user) {
                return strtoupper($point->user_code) == strtoupper($user->uuid);
            })->values();

            $puntosPersonales = $paymentOrderPointsUser->where('type', 'B')->sum('point');
            $puntosRed        = $paymentOrderPointsUser->where('type', 'G')->sum('point');
            $puntosResiduales = $paymentOrderPointsUser->where('type', 'R')->sum('point');
            $gananciaPatrocinio = $paymentOrderPointsUser->whereIn('type', ['P', 'S'])->sum('point');
            $puntosInfinito   = $paymentOrderPointsUser->where('type', 'I')->sum('point');

            $totalPoints = $puntosPersonales + $puntosRed + $puntosResiduales;

            // 🔥 CONSTRUIR OBJETO DE PUNTOS
            $user->points = (object) [
                'patrocinio'          => $gananciaPatrocinio,
                'residual'            => $puntosResiduales,
                'compra'              => (object) ['total_puntos' => $puntosPersonales],
                'pointGroup'          => $puntosRed,
                'personal'            => $puntosPersonales,
                'infinito'            => $puntosInfinito,
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

        $now           = Carbon::now();
        $currentMonth  = $now->month;
        $currentYear   = $now->year;
        $mesAnterior   = $now->copy()->subMonth();
        $isGracePeriod = $now->day <= 2;

        $servicePayment = PaymentLog::with(['paymentOrder.pack'])
            ->where("user_id", $user_id)->whereIn('state', [2, 6])->orderBy('created_at', 'desc')->first();
        $productPayment = PaymentProductOrder::with(['pack', 'details.product'])
            ->where("user_id", $user_id)->whereIn('state', [2, 3, 6])->orderBy('created_at', 'desc')->first();

        $ultimoPago = collect([$servicePayment, $productPayment])->filter()->sortByDesc('created_at')->first();

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

        if ($userModel->is_admin) {
            $isActive = true;
            if ($servicePayment) {
                $servicePayment->state = PaymentLog::PAGADO;
            }
            if (!$ultimoPago) {
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

        $userModel->payment      = $ultimoPago;
        $userModel->package_name = $userModel->package_name;
        $userModel->active       = $isActive;

        // 🔥 CORRECCIÓN 1: OBTENER TODOS LOS PUNTOS ACTIVOS (state = 1) DEL MES FILTRADO
        // Incluimos todos los tipos: B, G, R, P, S, I
        $paymentOrderPoints = PaymentOrderPoint::where('state', 1) // 👈 state = 1 (activo)
            ->whereMonth('created_at', $mesFiltro)
            ->whereYear('created_at', $anioFiltro)
            ->whereIn('type', ['B', 'G', 'R', 'P', 'S', 'I']) // 👈 TODOS LOS TIPOS
            ->get();

        // 🔥 CORRECCIÓN 2: FILTRAR SOLO LOS PUNTOS DEL USUARIO ACTUAL
        $paymentOrderPointsUser = $paymentOrderPoints->filter(function ($point) use ($userModel) {
            return strtoupper($point->user_code) == strtoupper($userModel->uuid);
        })->values();

        // 🔥 CORRECCIÓN 3: CALCULAR PUNTOS POR TIPO (Todos los tipos)
        $puntosPersonales = $paymentOrderPointsUser->where('type', 'B')->sum('point'); // COMPRA
        $puntosRed        = $paymentOrderPointsUser->where('type', 'G')->sum('point'); // GRUPAL
        $puntosResiduales = $paymentOrderPointsUser->where('type', 'R')->sum('point'); // RESIDUAL
        $gananciaPatrocinio = $paymentOrderPointsUser->whereIn('type', ['P', 'S'])->sum('point'); // PATROCINIO (P y S)
        $puntosInfinito   = $paymentOrderPointsUser->where('type', 'I')->sum('point'); // INFINITO

        // 🔥 CORRECCIÓN 4: TOTAL DE PUNTOS PARA RANGO = COMPRA + GRUPAL + RESIDUAL
        $totalPoints = $puntosPersonales + $puntosRed + $puntosResiduales;

        $legacyTokens = GuestsTokenUser::where('state', true)->get();

        // 🔥 CORRECCIÓN 5: LÓGICA PARA DOSB (CORPORATIVO)
        if (strtoupper($userModel->uuid) == 'DOSB') {
            $directosLegacy = GuestsTokenUser::where('sponsor_user_code', $userModel->uuid)
                ->where('state', true)
                ->pluck('guest_user_code')
                ->toArray();

            $userModel->directos = count($directosLegacy);
            $activos = 0;
            foreach ($directosLegacy as $guestCode) {
                $user = User::where('uuid', $guestCode)->first();
                if ($user) {
                    $hasPayment = PaymentLog::where('user_id', $user->id)
                        ->whereIn('state', [2, 6])
                        ->whereMonth('created_at', $now->month)
                        ->whereYear('created_at', $now->year)
                        ->exists();
                    if ($hasPayment) $activos++;
                }
            }
            $userModel->activos   = $activos;
            $userModel->red_total = $this->networkTreeService->countTotalNetworkRecursive('DOSB');

            $networkUsers   = $this->networkTreeService->getAllNetworkUsers('DOSB');
            $totalPointsRed = PaymentOrderPoint::whereIn('user_code', $networkUsers)
                ->where('state', 1)
                ->sum('point');

            if ($totalPointsRed > 0) {
                $totalPoints = (int) $totalPointsRed;
            } else {
                $totalPoints = count($directosLegacy) * 100;
            }

            $userModel->points = (object) [
                'patrocinio'         => 0,
                'residual'           => 0,
                'compra'             => (object) ['total_puntos' => $totalPoints],
                'pointGroup'         => 0,
                'personal'           => $totalPoints,
                'infinito'           => 0,
                'pointAfiliado'      => 0,
                'personalGlobal'     => 0,
                'patrocinioRequest'  => 0,
                'patrocinioServicio' => 0,
                'residualServicio'   => 0,
                'legacy_bonus'       => count($directosLegacy) * 100
            ];
        } else {
            // 🔥 LÓGICA PARA USUARIOS NORMALES
            $directosPuntos = PaymentOrderPoint::where('sponsor_code', $userModel->uuid)
                ->where('type', 'B')
                ->where('state', 1)
                ->where('payment', 1)
                ->pluck('user_code')
                ->toArray();

            $directosLegacy = GuestsTokenUser::where('sponsor_user_code', $userModel->uuid)
                ->where('state', true)
                ->pluck('guest_user_code')
                ->toArray();

            $todosDirectos       = array_unique(array_merge($directosPuntos, $directosLegacy));
            $userModel->directos = count($todosDirectos);

            $activos = 0;
            foreach ($todosDirectos as $directCode) {
                $user = User::where('uuid', $directCode)->first();
                if ($user) {
                    $hasActivePayment = PaymentLog::where('user_id', $user->id)
                        ->whereIn('state', [2, 6])
                        ->whereMonth('created_at', $mesFiltro)
                        ->whereYear('created_at', $anioFiltro)
                        ->exists();
                    $hasActiveProduct = PaymentProductOrder::where('user_id', $user->id)
                        ->whereIn('state', [2, 3, 6])
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

            // 🔥 CORRECCIÓN: OBJETO DE PUNTOS CON TODOS LOS TIPOS
            $userModel->points = (object) [
                'patrocinio'          => $gananciaPatrocinio, // Bono por reclutar (P + S)
                'residual'            => $puntosResiduales,   // Bono residual (R)
                'compra'              => (object) ['total_puntos' => $puntosPersonales], // Puntos personales (B)
                'pointGroup'          => $puntosRed,          // Puntos grupales (G)
                'personal'            => $puntosPersonales,   // Puntos personales (B)
                'infinito'            => $puntosInfinito,     // Bono infinito (I)
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
        }

        $userModel->totalPoints = $totalPoints;

        // RANGOS
        $ranges = Range::where("state", true)->orderBy('points', 'asc')->get();
        $rangeCurrent = null;
        foreach ($ranges as $range) {
            if ($range->points <= $totalPoints && $range->childs <= (int) $userModel->directos) {
                $rangeCurrent = $range;
            }
        }

        if (!$rangeCurrent) {
            $bronce = Range::where('points', 1000)->where('childs', 1)->where('state', true)->first();
            if ($bronce && $totalPoints >= 1000 && $userModel->directos >= 1) {
                $rangeCurrent = $bronce;
            }
        }

        if ($rangeCurrent) {
            $existingRange = RangeUser::where('user_id', $userModel->id)->where('status', true)->first();
            if ($existingRange) {
                if ($existingRange->range_id != $rangeCurrent->id) {
                    $existingRange->update(['range_id' => $rangeCurrent->id, 'updated_at' => now()]);
                }
                $userModel->range = (object) ['range' => $rangeCurrent];
            } else {
                RangeUser::create([
                    'user_id'    => $userModel->id,
                    'range_id'   => $rangeCurrent->id,
                    'status'     => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $userModel->range = (object) ['range' => $rangeCurrent];
            }
        } else {
            RangeUser::where('user_id', $userModel->id)->where('status', true)->update(['status' => false]);
            $userModel->range = null;
        }

        $userPoints = $paymentOrderPointsUser->values()->toArray();

        $responsePayload                    = $userModel->toArray();
        $responsePayload['points']          = $userPoints;
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
            'total_puntos'        => $totalPoints,
            'paquete_actual'      => $userModel->package_name ?? 'Sin paquete',
            'rango_actual'        => $rangeCurrent ? $rangeCurrent->title : 'Sin rango'
        ];

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
                $isBelongingToNetwork = PaymentOrderPoint::where('user_code', $targetCode)->where('sponsor_code', $userModel->uuid)->exists();
                if (!$isBelongingToNetwork && strtoupper($targetCode) !== strtoupper($userModel->uuid)) {
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
                $user_payments = PaymentLog::whereIn('state', [2, 6])->pluck('user_id')->toArray();
                $userList = $userList->whereNotIn("id", $user_payments);
            } else {
                $user_payments_pack = PaymentLog::whereIn('state', [2, 6])->whereHas("paymentOrder.pack", function ($q) use ($plan) {
                    $q->where('id', $plan);
                })->pluck('user_id')->toArray();
                $userList = $userList->whereIn("id", $user_payments_pack);
            }
        }

        $userList = $userList->orderBy('created_at', 'desc')->paginate($limit);

        $now = Carbon::now();
        $currentMonth = $now->month;
        $currentYear = $now->year;
        $mesAnterior = $now->copy()->subMonth();
        $isGracePeriod = $now->day <= 2;

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
            ->where('state', 1)
            ->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year)
            ->get();

        $allProductOrderPointsLastMonth = PaymentProductOrderPoint::where("state", true)
            ->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year)
            ->get();

        $userIds = collect($userList->items())->pluck('uuid')->toArray();

        // 🔥 Sumar los bonos históricos (P, S, R, RS) - esto es para el frontend como dato adicional
        $historicalBonuses = PaymentOrderPoint::select('sponsor_code', DB::raw('SUM(point) as total_bono'))
            ->whereIn('sponsor_code', $userIds)->where('state', 1)
            ->whereIn('type', [PaymentOrderPoint::PATROCINIO, PaymentOrderPoint::PATROCINIO_SERVICIO, PaymentOrderPoint::RESIDUAL, PaymentOrderPoint::RESIDUAL_SERVICIO])
            ->groupBy('sponsor_code')->pluck('total_bono', 'sponsor_code');

        foreach ($userList as $key => $user) {
            $servicePayment = PaymentLog::with(['paymentOrder.pack', 'paymentOrder.sponsor.file'])
                ->where("user_id", $user->id)->whereIn('state', [2, 6])->orderBy('created_at', 'desc')->first();
            $productPayment = PaymentProductOrder::with(['pack'])
                ->where("user_id", $user->id)->whereIn('state', [2, 3, 6])->orderBy('created_at', 'desc')->first();

            $ultimoPago = collect([$servicePayment, $productPayment])->filter()->sortByDesc('created_at')->first();

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

            if (!$isActive && $ultimoPago) $ultimoPago->state = 6;
            $userList[$key]->payment = $ultimoPago;
            $userList[$key]->package_name = $user->package_name;

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
        $validator = Validator::make($request->all(), [
            'userCode'     => 'required',
            'userFullName' => 'required'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();

            if (!Auth::user()->is_admin) return $this->sendError("No tiene permisos.");

            $userUpdated = User::where("uuid", $request->userCode)->first();
            if (!$userUpdated) return $this->sendError("Usuario no encontrado");

            $userUpdated->update(["name" => $request->userFullName]);

            $currentSponsor = PaymentOrderPoint::where('user_code', $userUpdated->uuid)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->where('payment', 1)
                ->latest()
                ->value('sponsor_code');

            $packId    = ($request->has('packId') && $request->packId > 0) ? $request->packId : null;
            $serviceId = ($request->has('serviceId') && $request->serviceId > 0) ? $request->serviceId : null;

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
                    'sponsor_code' => !empty($request->sponsorNew) ? $request->sponsorNew : $currentSponsor,
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
                $userList[$key]->payment = PaymentLog::with(['paymentOrder.pack'])->where("user_id",  $user->id)
                    ->where(function ($query) {
                        $query->where('state', PaymentLog::PAGADO)
                            ->orWhere('state', PaymentLog::TERMINADO);
                    })
                    ->orderBy('created_at', 'desc')
                    ->first();
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
                $payment = PaymentLog::with(['paymentOrder.pack'])->where("user_id",  $user->id)
                    ->where(function ($query) {
                        $query->where('state', PaymentLog::PAGADO)
                            ->orWhere('state', PaymentLog::TERMINADO);
                    })
                    ->orderBy('created_at', 'desc')
                    ->first();
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

                $totalPoints  = $calculatorPoint->patrocinio + $calculatorPoint->residual + $calculatorPoint->compra->total_puntos + $calculatorPoint->pointGroup + $calculatorPoint->personal;
                $rangeCurrent = null;
                foreach ($ranges as $key => $range) {
                    if ($range->point <= $totalPoints && $range->childs == count($_paymentOrderPoints)) {
                        $rangeCurrent   = $range;
                        break;
                    }
                }

                array_push($_userList, (object) [
                    "estado"            => $payment == null ? "" : ($payment->state == PaymentLog::PAGADO ? "Activo" : "Desactivo"),
                    "nombres"           => $user->name,
                    "codigo"            => $user->uuid,
                    "plan"              => $payment == null ? "Sin plan" : ($payment->paymentOrder->pack->title),
                    "bono_personal"     => $calculatorPoint->personal,
                    "bono_pratocinio"   => $calculatorPoint->patrocinio,
                    "bono_residual"     => $calculatorPoint->residual,
                    "bono_totales"      => $calculatorPoint->patrocinio + $calculatorPoint->residual,
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
            PaymentOrderPoint::where("user_id", $userCurrent->id)->update(["state"        => false, "type" => PaymentOrderPoint::RESET]);
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

            DB::commit();
            return $this->sendResponse($dataBody->token, '');
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

            DB::beginTransaction();
            $guestsTokenUser     = GuestsTokenUser::where("guest_user_code", $userModel->uuid)->where("state", true)->first();
            if ($guestsTokenUser == null) {
                return $this->sendResponse("", "No tiene ningun sponsor invitado", false);
            }

            DB::commit();
            return $this->sendResponse($guestsTokenUser->sponsor_user_code, '');
        } catch (Exception $e) {
            DB::rollBack();
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

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }
}