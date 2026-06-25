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
use App\Models\File;
use Illuminate\Support\Facades\Storage;
use App\Models\PaymentLog;
use App\Services\Core\FileUpload;
use App\Http\Resources\PaginationCollection;
use App\Models\Pack;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
use App\Models\Range;
use App\Services\Core\Calculator;
use App\Models\PaymentProductOrderPoint;
use App\Models\PaymentProductOrder;
use App\Models\SponsorshipPoint;
use App\Models\ResidualPoint;
use App\Models\RangeUser;
use App\Models\Option;
use App\Models\Product;
use App\Models\ProductPointPack;
use Maatwebsite\Excel\Excel as BaseExcel;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\UsersPointExport;
use App\Mail\UsersPointExcel;
use App\Mail\UserPointActive;
use App\Models\PaymentProductOrderDetail;

use App\Models\InviteUser;
use App\Models\GuestsTokenUser;
use App\Models\AfiliadosPoint;

use Illuminate\Support\Facades\Mail;
use App\Models\UserEmailTemp;

use App\Exports\ReportExcelUsers;
use Barryvdh\DomPDF\Facade\Pdf;

use Carbon\Carbon;
use Illuminate\Support\Str;


use App\Mail\InivitedSponsorUser;
use App\Services\Core\CodeGenerator;
use App\Models\VerificationCodeUser;


class UserController extends BaseController
{
    //

    private $fileUpload;
    private $fileUploadPath;
    private $calculator;
    private $commissionService;

    public function __construct()
    {
        $this->fileUpload = new FileUpload();
        $this->fileUploadPath = 'avatar';
        $this->calculator = new Calculator();
        $this->commissionService = new \App\Services\Core\Services\CommissionService();
    }


    public function show($id)
    {
        try {
            $user = User::with(['file', 'range.range.file'])->find($id);

            if (!$user) {
                return $this->sendError("Usuario no encontrado.");
            }

            $now = Carbon::now();
            $currentMonth = $now->month;
            $currentYear = $now->year;
            $mesAnterior = $now->copy()->subMonth();
            $isGracePeriod = $now->day <= 2;

            $servicePayment = PaymentLog::with(['paymentOrder.pack'])
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
            $user->payment = $ultimoPago;

            // Filtro de puntos del ciclo actual o de gracia
            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder'])->where('state', true)
                ->whereMonth('created_at', $mesFiltro)->whereYear('created_at', $anioFiltro)->get();

            $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $user->id)->where("state", true)
                ->whereMonth('created_at', $mesFiltro)->whereYear('created_at', $anioFiltro)->get();

            $user->points = $this->calculator->points($user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
            $user->totalPoints = $this->calculator->pointsTotal($user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);

            $user->bonos_totales_historico = PaymentOrderPoint::where('sponsor_code', $user->uuid)
                ->where('state', true)
                ->whereIn('type', [PaymentOrderPoint::PATROCINIO, PaymentOrderPoint::PATROCINIO_SERVICIO, PaymentOrderPoint::RESIDUAL, PaymentOrderPoint::RESIDUAL_SERVICIO])
                ->sum('point');

            return $this->sendResponse($user, 'Usuario encontrado');
        } catch (\Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function auth()
{
    try {
        $user_id = Auth::id();
        $userModel = User::with(['file', 'range.range.file'])->select("*", "created_at as creatxlssed")->find($user_id);

        if (!$userModel) return $this->sendError("Usuario no encontrado");

        // Lógica de Tiempo y Periodo de Gracia
        $now = Carbon::now();
        $currentMonth = $now->month;
        $currentYear = $now->year;
        $mesAnterior = $now->copy()->subMonth();
        $isGracePeriod = $now->day <= 2;

        $servicePayment = PaymentLog::with(['paymentOrder.pack'])
            ->where("user_id", $user_id)->whereIn('state', [2, 6])->orderBy('created_at', 'desc')->first();
        $productPayment = PaymentProductOrder::with(['pack', 'details.product'])
            ->where("user_id", $user_id)->whereIn('state', [2, 3, 6])->orderBy('created_at', 'desc')->first();

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

        // Administradores siempre activos
        if ($userModel->is_admin) {
            $isActive = true;
            if ($servicePayment) {
                $servicePayment->state = PaymentLog::PAGADO;
            }
            if (!$ultimoPago) {
                $defaultPack = Pack::where('title', 'Pack Empresario')->first();
                if ($defaultPack) {
                    $paymentOrder = PaymentOrder::create([
                        'currency' => 'PEN',
                        'amount' => 0,
                        'sponsor_code' => $userModel->uuid,
                        'pack_id' => $defaultPack->id,
                        'token' => 'ADMIN-' . uniqid()
                    ]);
                    $ultimoPago = PaymentLog::create([
                        'payment_order_id' => $paymentOrder->id,
                        'user_id' => $user_id,
                        'state' => PaymentLog::PAGADO,
                        'confirm' => true,
                        'message' => 'Admin activo por defecto'
                    ]);
                    $servicePayment = $ultimoPago;
                }
            }
        }

        if (!$isActive && $ultimoPago) {
            $ultimoPago->state = 6;
            PaymentLog::where('id', $ultimoPago->id)->update(['state' => 6]);
        }

        $userModel->payment = $ultimoPago;
        $userModel->package_name = $userModel->package_name;
        $userModel->active = $isActive;

        // =========================================================
        // 1. OBTENER TODOS LOS PUNTOS DEL MES FILTRADO
        // =========================================================
        $paymentOrderPoints = PaymentOrderPoint::where('state', true)
            ->whereMonth('created_at', $mesFiltro)
            ->whereYear('created_at', $anioFiltro)
            ->get();

        // =========================================================
        // 2. CALCULAR PUNTOS DEL USUARIO ACTUAL (FILTRADOS)
        // =========================================================
        $paymentOrderPointsUser = $paymentOrderPoints->filter(function ($point) use ($userModel) {
            return strtoupper($point->user_code) === strtoupper($userModel->uuid);
        })->values();

        // =========================================================
        // 3. CALCULAR PUNTOS POR TIPO
        // =========================================================
        // Puntos personales (COMPRA)
        $puntosPersonales = $paymentOrderPointsUser
            ->where('type', PaymentOrderPoint::COMPRA)
            ->sum('point');

        // Puntos de red (GRUPAL)
        $puntosRed = $paymentOrderPointsUser
            ->where('type', PaymentOrderPoint::GRUPAL)
            ->sum('point');

        // Puntos residuales
        $puntosResiduales = $paymentOrderPointsUser
            ->where('type', PaymentOrderPoint::RESIDUAL)
            ->sum('point');

        // Ganancia por patrocinio (P y S) - NO se suma a totalPoints
        $gananciaPatrocinio = $paymentOrderPointsUser
            ->whereIn('type', ['P', 'S'])
            ->sum('point');

        // Puntos infinito
        $puntosInfinito = $paymentOrderPointsUser
            ->where('type', PaymentOrderPoint::INFINITO)
            ->sum('point');

        // =========================================================
        // 4. 🔥 TOTAL DE PUNTOS = PERSONALES + RED + RESIDUALES (SIN PATROCINIO)
        // =========================================================
        $totalPoints = $puntosPersonales + $puntosRed + $puntosResiduales;

        // =========================================================
        // 5. OBTENER DATOS LEGACY
        // =========================================================
        $legacyTokens = GuestsTokenUser::where('state', true)->get();

        // =========================================================
        // 6. CONTADORES DE DASHBOARD
        // =========================================================
        if (strtoupper($userModel->uuid) === 'DOSB') {
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
            $userModel->activos = $activos;
            $userModel->red_total = $this->countTotalNetworkRecursive('DOSB');
            
            $networkUsers = $this->getAllNetworkUsers('DOSB');
            $totalPointsRed = PaymentOrderPoint::whereIn('user_code', $networkUsers)
                ->where('state', true)
                ->sum('point');
            
            if ($totalPointsRed > 0) {
                $totalPoints = (int) $totalPointsRed;
            } else {
                $totalPoints = count($directosLegacy) * 100;
            }

            $userModel->points = (object) [
                'patrocinio' => 0,
                'residual' => 0,
                'compra' => (object) ['total_puntos' => $totalPoints],
                'pointGroup' => 0,
                'personal' => $totalPoints,
                'infinito' => 0,
                'pointAfiliado' => 0,
                'personalGlobal' => 0,
                'patrocinioRequest' => 0,
                'patrocinioServicio' => 0,
                'residualServicio' => 0,
                'legacy_bonus' => count($directosLegacy) * 100
            ];
        } else {
            // =========================================================
            // 🔥 LÓGICA PARA USUARIOS NORMALES
            // =========================================================

            // 1. DIRECTOS
            $directosPuntos = PaymentOrderPoint::where('sponsor_code', $userModel->uuid)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->where('state', true)
                ->where('payment', 1)
                ->pluck('user_code')
                ->toArray();

            $directosLegacy = GuestsTokenUser::where('sponsor_user_code', $userModel->uuid)
                ->where('state', true)
                ->pluck('guest_user_code')
                ->toArray();

            $todosDirectos = array_unique(array_merge($directosPuntos, $directosLegacy));
            $userModel->directos = count($todosDirectos);

            // 2. ACTIVOS
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

            // 3. RED TOTAL
            $userModel->red_total = $this->countTotalNetworkRecursive($userModel->uuid);
            
            // 4. 🔥 OBJETO DE PUNTOS CORRECTO
            $userModel->points = (object) [
                'patrocinio' => $gananciaPatrocinio,        // Ganancia por patrocinio (BONO)
                'residual' => $puntosResiduales,             // Puntos residuales
                'compra' => (object) ['total_puntos' => $puntosPersonales], // Puntos personales
                'pointGroup' => $puntosRed,                  // Puntos de red (grupales)
                'personal' => $puntosPersonales,             // Puntos personales
                'infinito' => $puntosInfinito,               // Puntos infinito
                'pointAfiliado' => 0,
                'personalGlobal' => 0,
                'patrocinioRequest' => 0,
                'patrocinioServicio' => 0,
                'residualServicio' => 0,
                // 🔥 NUEVOS CAMPOS PARA EL FRONTEND
                'puntos_personales' => $puntosPersonales,
                'puntos_red' => $puntosRed,
                'ganancia_patrocinio' => $gananciaPatrocinio,
                'total_general' => $totalPoints
            ];
        }

        // 🔥 CORRECCIÓN: totalPoints NO incluye patrocinio
        $userModel->totalPoints = $totalPoints;

        // =========================================================
        // 7. RANGOS
        // =========================================================
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
                    'user_id' => $userModel->id,
                    'range_id' => $rangeCurrent->id,
                    'status' => true,
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
                $userModel->range = (object) ['range' => $rangeCurrent];
            }
        } else {
            RangeUser::where('user_id', $userModel->id)->where('status', true)->update(['status' => false]);
            $userModel->range = null;
        }

        // =========================================================
        // 8. RESPUESTA CON user_detail
        // =========================================================
        $userPoints = $paymentOrderPointsUser->values()->toArray();
        
        $responsePayload = $userModel->toArray();
        $responsePayload['points'] = $userPoints;
        $responsePayload['legacy_count'] = $legacyTokens->count();
        $responsePayload['network_summary'] = [
            'total_directs' => $userModel->directos ?? 0,
            'total_active' => $userModel->activos ?? 0,
            'total_network' => $userModel->red_total ?? 0,
            'has_legacy_network' => $legacyTokens->count() > 0
        ];
        
        // 🔥 user_detail con datos separados para el frontend
        $responsePayload['user_detail'] = [
            'puntos_personales' => $puntosPersonales,
            'puntos_red' => $puntosRed,
            'ganancia_patrocinio' => $gananciaPatrocinio,
            'puntos_residuales' => $puntosResiduales,
            'total_puntos' => $totalPoints,
            'paquete_actual' => $userModel->package_name ?? 'Sin paquete',
            'rango_actual' => $rangeCurrent ? $rangeCurrent->title : 'Sin rango'
        ];

        return $this->sendResponse((object)$responsePayload, 'Perfil sincronizado');
    } catch (Exception $e) {
        return $this->sendError("Fallo de integridad: " . $e->getMessage());
    }
}


/**
* ============================================================
* NUEVO MÉTODO: getAllNetworkUsers()
* ============================================================
* Obtiene recursivamente TODOS los códigos de usuario de la red
*/
private function getAllNetworkUsers($userCode, &$visited = [])
{
   if (in_array($userCode, $visited)) {
       return [];
   }
   $visited[] = $userCode;

   $users = [$userCode];

   // 1. Hijos transaccionales (compras directas) - 🔥 SIN FILTRO DE PAYMENT
   $transactionalChildren = PaymentOrderPoint::where('sponsor_code', $userCode)
    ->where('type', PaymentOrderPoint::COMPRA)
    ->where('state', true)
    // ← ELIMINAR where('payment', 1)
    ->pluck('user_code')
    ->unique()
    ->toArray();

   // 2. Hijos históricos (invitados)
   $historicalChildren = GuestsTokenUser::where('sponsor_user_code', $userCode)
       ->where('state', true)
       ->pluck('guest_user_code')
       ->toArray();

   // Unificar y evitar duplicados
   $allChildren = array_unique(array_merge($transactionalChildren, $historicalChildren));

   foreach ($allChildren as $child) {
       // Recursivamente obtener todos los usuarios de la red
       $childUsers = $this->getAllNetworkUsers($child, $visited);
       $users = array_merge($users, $childUsers);
   }

   return array_unique($users);
}
    public function authUpdate(Request $request)
    {
        $validator = Validator::make($request->all(), []);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();

            $dataBody = (object) $request->all();
            $user_id = Auth::id();
            User::where("id", $user_id)->update(array(
                "address"   => $dataBody->address,
                "phone"     => $dataBody->phone,
                'city'      => $dataBody->city,
                'country'   => $dataBody->country,
                'genger'    => $dataBody->gender,
            ));

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

            $fileId = 0;

            $user_id = Auth::id();


            if ($request->hasfile('file')) $fileId = $this->fileUpload->upload($request->file('file'), $this->fileUploadPath);

            User::where("id", $user_id)->update(array(
                "photo" => $fileId,
            ));

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

            $user_id = Auth::id();
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

            $userList = User::with(['file', 'range.range.file'])->where('is_admin', false);

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

            // Cargar TODOS los puntos del mes (para filtrado posterior)
            $allPaymentOrderPoints = PaymentOrderPoint::with(['paymentOrder'])->where('state', true)
                ->whereMonth('created_at', $currentMonth)->whereYear('created_at', $currentYear)
                ->get();


            $allProductOrderPoints = PaymentProductOrderPoint::where("state", true)
                ->whereMonth('created_at', $currentMonth)->whereYear('created_at', $currentYear)
                ->get();

            // Puntos del mes anterior para periodo de gracia
            $allPaymentOrderPointsLastMonth = PaymentOrderPoint::with(['paymentOrder'])->where('state', true)
                ->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year)
                ->get();

            $allProductOrderPointsLastMonth = PaymentProductOrderPoint::where("state", true)
                ->whereMonth('created_at', $mesAnterior->month)->whereYear('created_at', $mesAnterior->year)
                ->get();

            $userIds = collect($userList->items())->pluck('uuid')->toArray();
            $historicalBonuses = PaymentOrderPoint::select('sponsor_code', DB::raw('SUM(point) as total_bono'))
                ->whereIn('sponsor_code', $userIds)->where('state', true)
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

                // 🔧 CORRECCIÓN: Seleccionar los puntos según el mes filtrado
                $puntosDisponibles = ($mesFiltro == $currentMonth) ? $allPaymentOrderPoints : $allPaymentOrderPointsLastMonth;
                $productosDisponibles = ($mesFiltro == $currentMonth) ? $allProductOrderPoints : $allProductOrderPointsLastMonth;

                // 🔧 CORRECCIÓN: Filtrar SOLO los puntos del usuario actual
                $popUsuario = $puntosDisponibles->filter(function ($point) use ($user) {
                    return strtoupper($point->user_code) === strtoupper($user->uuid);
                })->values();

                $ppopUsuario = $productosDisponibles->filter(function ($point) use ($user) {
                    return $point->user_id == $user->id;
                })->values();

                $userList[$key]->points = $this->calculator->points($user->uuid, $popUsuario, $ppopUsuario);
                $userList[$key]->totalPoints = $this->calculator->pointsTotal($user->uuid, $popUsuario, $ppopUsuario);
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
            'userCode' => 'required',
            'userFullName' => 'required'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();

            if (!Auth::user()->is_admin) return $this->sendError("No tiene permisos.");

            $userUpdated = User::where("uuid", $request->userCode)->first();
            if (!$userUpdated) return $this->sendError("Usuario no encontrado");

            // 1. Actualizamos el nombre
            $userUpdated->update(["name" => $request->userFullName]);

            // 2. BUSCAR EL PATROCINADOR ACTUAL
            $currentSponsor = PaymentOrderPoint::where('user_code', $userUpdated->uuid)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->where('payment', 1)
                ->latest()
                ->value('sponsor_code');

            // --- LÓGICA PARA AGREGAR PACKS ---
            $processPackAdd = function ($packId) use ($userUpdated, $request, $currentSponsor) {
                if (!$packId || $packId == 1 || $packId == "0") return;

                $pack = Pack::find($packId);
                if (!$pack) return;

                // Verificar si el usuario ya tiene un pack de la MISMA CATEGORÍA
                $existingPack = PaymentOrderPoint::where('user_code', $userUpdated->uuid)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->where('state', true)
                    ->whereHas('paymentOrder.pack', function ($q) use ($pack) {
                        $q->where('category', $pack->category);
                    })
                    ->with('paymentOrder.pack')
                    ->first();

                if ($existingPack) {
                    $existingPackPoints = $existingPack->paymentOrder->pack->points ?? 0;
                    $newPackPoints = $pack->points;

                    if ($newPackPoints <= $existingPackPoints) {
                        return;
                    }
                }

                // Crear nueva orden
                $newOrder = PaymentOrder::create([
                    'currency' => "PEN",
                    'amount' => 0,
                    'sponsor_code' => !empty($request->sponsorNew) ? $request->sponsorNew : $currentSponsor,
                    'pack_id' => $pack->id,
                    "token" => 'AUTO-' . uniqid()
                ]);

                // Activar el log de pago
                $paymentLog = PaymentLog::create([
                    'payment_order_id' => $newOrder->id,
                    'user_id' => $userUpdated->id,
                    'state' => PaymentLog::PAGADO,
                    'confirm' => true,
                    'message' => "Actualización de paquete: " . $pack->title
                ]);

                // ✅ IMPORTANTE: Distribuir puntos y bonos usando confirmPoint
                $this->confirmPoint($newOrder, $userUpdated, $pack);
            };

            // Ejecutar para producto y servicio
            if ($request->has('packId')) $processPackAdd($request->packId);
            if ($request->has('serviceId')) $processPackAdd($request->serviceId);

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

            $user_id = Auth::id();
            $userModel = User::with(['file'])->find($user_id);

            if (!$userModel->is_admin) return $this->sendError("No tiene permisos ese usuario");
            // $userDetail = UserDetail::where("user_id" , $user_id)->first();

            $userList = User::with(['file']);

            // if( $request->has('code') ) $muscleGroupList = $request->query('status') != NULL ? $muscleGroupList->where("status" , $request->query('status') ) : $muscleGroupList;
            if ($request->has('code')) if (!empty($request->query('code'))) $userList = $userList->where("uuid", 'like', $request->query('code'));
            if ($request->has('email')) if (!empty($request->query('email'))) $userList = $userList->where("email", 'like', $request->query('email'));
            if ($request->has('name')) if (!empty($request->query('name'))) $userList = $userList->where("name", 'like', '%' . ($request->query('name')) . '%');

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

            $user_id = Auth::id();
            $userModel = User::with(['file'])->find($user_id);



            $userList = User::with(['file'])->where('is_admin', false)->get();

            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder.paymentLog'])->where('state', true)->get();
            $paymentProductOrderPoints = PaymentProductOrderPoint::where("state", true)->get();

            $_userList = array();

            $ranges = Range::where("state", true)
                ->orderBy('points', 'asc')
                ->get();

            foreach ($userList as $key => $user) {
                $payment = PaymentLog::with(['paymentOrder.pack'])->where("user_id",  $user->id)
                    ->where(function ($query) {
                        $query->where('state', PaymentLog::PAGADO)
                            ->orWhere('state', PaymentLog::TERMINADO);
                    })
                    ->orderBy('created_at', 'desc')
                    ->first();
                $_userId = $user->id;
                $_paymentProductOrderPoints = array_filter(
                    $paymentProductOrderPoints->toArray(),
                    function ($p) use ($_userId) {
                        return $p['user_id'] == $_userId;
                    }
                );

                $calculatorPoint = $this->calculator->points($user->uuid, $paymentOrderPoints, $_paymentProductOrderPoints);

                $uuid = $user->uuid;
                $_paymentOrderPoints = array_filter(
                    $paymentOrderPoints->toArray(),
                    function ($p) use ($uuid) {
                        return strtoupper($p['sponsor_code']) == strtoupper($uuid) && $p['state'] == true && $p['payment'] == true && $p['type'] != PaymentOrderPoint::GRUPAL;
                    }
                );

                $totalPoints = $calculatorPoint->patrocinio + $calculatorPoint->residual + $calculatorPoint->compra->total_puntos + $calculatorPoint->pointGroup + $calculatorPoint->personal;
                $rangeCurrent = null;
                foreach ($ranges as $key => $range) {
                    if ($range->point <= $totalPoints && $range->childs == count($_paymentOrderPoints)) {
                        $rangeCurrent = $range;
                        break;
                    }
                }

                array_push($_userList, (object) array(
                    "estado"                => $payment == null ? "" : ($payment->state == PaymentLog::PAGADO ? "Activo" : "Desactivo"),
                    "nombres"               => $user->name,
                    "codigo"                => $user->uuid,
                    "plan"                  => $payment == null ? "Sin plan" : ($payment->paymentOrder->pack->title),
                    "bono_personal"         => $calculatorPoint->personal,
                    "bono_pratocinio"       => $calculatorPoint->patrocinio,
                    "bono_residual"         => $calculatorPoint->residual,
                    "bono_totales"          => $calculatorPoint->patrocinio + $calculatorPoint->residual,
                    "punto_grupales"        => $calculatorPoint->pointGroup,
                    "punto_plan_actual"     => $calculatorPoint->compra->total_puntos,
                    "punto_plan_actual"     => $calculatorPoint->compra->total_puntos,
                    "gran_total"            => $totalPoints,
                    "rango"                 => $rangeCurrent == null ? "" : $rangeCurrent->title,
                    "count_rango"           => "0",
                ));
            }

            $attachment = Excel::raw(
                new UsersPointExport($_userList),
                BaseExcel::XLSX
            );
            // $subject = "Purchase Order";

            $mailData = [
                'customer_name' => "Edwin",
                'month' => "Febrero",
                'attach'    => $attachment
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

            $user = User::where("id", $userId)->first();

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
            'userCode' => 'required',
            'sponsorCode' => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {

            $userId = Auth::id();

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

            PaymentOrder::where("id", $paymentLog->paymentOrder->id)->update(array(
                "sponsor_code" => $dataBody->sponsorCode
            ));

            PaymentOrderPoint::where("user_id", $userCurrent->id)
                // ->where("payment_order_id" , $paymentLog->paymentOrder->id)
                // ->where("type" , $paymentLog->paymentOrder->id)
                ->update(array("sponsor_code" => $dataBody->sponsorCode));

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

            $dataBody = (object) $request->all();
            $userId = Auth::id();

            $userCurrent = User::where("uuid", $dataBody->userCode)->first();

            $paymentLog = PaymentLog::with(['paymentOrder'])->where("user_id",  $userCurrent->id)
                ->where(function ($query) {
                    $query->where('state', PaymentLog::PAGADO)
                        ->orWhere('state', PaymentLog::TERMINADO);
                })
                ->orderBy('created_at', 'desc')
                ->first();

            PaymentLog::where("user_id", $userCurrent->id)->update(array("state" => PaymentLog::RESET));

            PaymentOrderPoint::where("user_id", $userCurrent->id)
                ->update(array("state" => false, "type" => PaymentOrderPoint::RESET));

            PaymentProductOrder::where("user_id", $userCurrent->id)
                ->update(array("state" => PaymentProductOrder::TERMINADO));

            PaymentProductOrderPoint::where("user_id", $userCurrent->id)
                ->update(array("state" => false));

            RangeUser::where("user_id", $userCurrent->id)
                ->update(array("status" => false));

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function resetAll(Request $request)
    {
        try {
            PaymentLog::with(['paymentOrder'])->where('state', PaymentLog::PAGADO)
                ->update(array("state" => PaymentLog::TERMINADO));

            PaymentOrderPoint::where('state', true)->update(array("state" => false, "type" => PaymentOrderPoint::RESET));
            PaymentProductOrderPoint::where("state", true)->update(array("state" => false));

            PaymentProductOrder::where("state", PaymentProductOrder::PAGADO)->update(array("state" => PaymentProductOrder::TERMINADO));

            RangeUser::where("status", true)->update(array("status" => false));
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function resetAllPoint(Request $request)
    {
        try {

            setlocale(LC_TIME, 'es_ES.UTF-8'); // Para funciones de fecha nativas (no estrictamente necesario para Carbon)
            Carbon::setLocale('es');           // Esto es lo importante para Carbon

            DB::beginTransaction();

            $userList = User::with(['range.range'])->get();

            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder'])->where('state', true)->get();

            $fechaActual = Carbon::now();

            // Obtener mes y año
            $mes = $fechaActual->translatedFormat('F'); // o 'F' para nombre del mes
            $año = $fechaActual->format('Y');
            $month = $fechaActual->format('m');

            $subject = "Resumen General de puntos y bonos del último mes - Imperio Global";

            foreach ($userList as $key => $user) {
                if ($user->is_admin) {
                    // ==== SOLO PARA EL ADMIN
                    $jsonBody = array();
                    foreach ($userList as $keyTemp => $_user) {
                        if ($_user->is_admin) continue;
                        $_user = (object) $_user;
                        $_user->payment = PaymentLog::with(['paymentOrder.pack'])->where("user_id",  $_user->id)
                            ->where(function ($query) {
                                $query->where('state', PaymentLog::PAGADO)
                                    ->orWhere('state', PaymentLog::TERMINADO);
                            })
                            ->orderBy('created_at', 'desc')
                            ->first();

                        $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $_user->id)->where("state", true)->get();

                        $calculator = $this->calculator->points($_user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
                        $calculatorPoint = $this->calculator->pointsTotal($_user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);

                        array_push($jsonBody, (object) array(
                            "fullname" => $_user->name,
                            "email" => $_user->email,
                            "uuid" => $_user->uuid,
                            "pack" => $_user->payment?->paymentOrder?->pack?->title ?? "Sin Plan",
                            "status" => $_user->payment == null ? "--" : ($_user->payment->state == PaymentLog::PAGADO ? "Activo" : "Inactivo"),
                            "totalPoint" => $calculatorPoint,
                            "range" => $_user->range == null ? "Sin Rango" : $_user->range->range->title,
                            "points" => (object) array(
                                "patrocinio"    => $calculator->patrocinio,
                                "residual"      => $calculator->residual,
                                "compra"        => $calculator->compra,
                                "pointGroup"    => $calculator->pointGroup,
                                "personal"      => $calculator->personal,
                                "infinito"      => $calculator->infinito,
                                "pointAfiliado" => $calculator->pointAfiliado,
                                "personalGlobal" => $calculator->personalGlobal
                            ),
                        ));
                    }



                    // crear archivo excel
                    $excelBody = array();

                    foreach ($jsonBody as $key => $json) {
                        array_push(
                            $excelBody,
                            array(
                                $json->fullname,
                                $json->uuid,
                                $json->status,
                                $json->pack,
                                $json->points?->pointAfiliado ?? 0,
                                $json->points?->patrocinio ?? 0,
                                $json->points?->residual ?? 0,
                                (($json->points?->pointAfiliado ?? 0)
                                    + ($json->points?->patrocinio ?? 0)
                                    + ($json->points?->residual ?? 0)
                                    + (($json->points?->personal ?? 0) * 0.02)
                                ),
                                $json->points?->compra ?? 0,
                                $json->points->personal ?? 0,
                                $json->points->infinito ?? 0,
                                $json->totalPoint,
                                $json->range
                            )
                        );
                    }

                    // 1. Guardar Excel
                    $fecha = Carbon::now()->format('YmdHis');
                    $nameFile = "exports/reporte_usuarios_{$fecha}.xlsx";

                    Excel::store(new ReportExcelUsers($excelBody), $nameFile);

                    $userTemp = UserEmailTemp::create(array(
                        'userId' => $user->id,
                        'isAdmin' => $user->is_admin,
                        'status' => UserEmailTemp::PENDIENTE,
                        'email' => $user->email,
                        'subject' => $subject . " " . strtoupper($mes) . "-" . $año,
                        'month' => $month,
                        'year' => $año,
                        'jsonBody' => serialize($jsonBody),
                        'fileAttachment' => $nameFile
                    ));
                } else {
                    // ==== SOLO USUARIOS
                    $user = (object) $user;

                    $user->payment = PaymentLog::with(['paymentOrder.pack'])->where("user_id",  $user->id)
                        ->where(function ($query) {
                            $query->where('state', PaymentLog::PAGADO)
                                ->orWhere('state', PaymentLog::TERMINADO);
                        })
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if ($user->payment == null) continue;

                    $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $user->id)->where("state", true)->get();

                    $calculator = $this->calculator->points($user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
                    $calculatorTotalPoint = $this->calculator->pointsTotal($user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);

                    $jsonBody = array(
                        "email" => $user->email,
                        "range" => $user->range == null ? "Sin Rango" : $user->range->range->title,
                        "pack" => $user->payment?->paymentOrder?->pack?->title ?? "Sin Plan",
                        "status" => $user->payment == null ? "--" : ($user->payment->state == PaymentLog::PAGADO ? "Activo" : "Inactivo"),
                        "points" => (object) array(
                            "patrocinio"    => $calculator->patrocinio,
                            "residual"      => $calculator->residual,
                            "compra"        => $calculator->compra,
                            "pointGroup"    => $calculator->pointGroup,
                            "personal"      => $calculator->personal,
                            "infinito"      => $calculator->infinito,
                            "pointAfiliado" => $calculator->pointAfiliado,
                            "personalGlobal" => $calculator->personalGlobal
                        ),
                        "totalPoint" => $calculatorTotalPoint
                    );

                    $userTemp = UserEmailTemp::create(array(
                        'userId' => $user->id,
                        'isAdmin' => $user->is_admin,
                        'status' => UserEmailTemp::PENDIENTE,
                        'email' => $user->email,
                        'subject' => $subject . " " . strtoupper($mes) . "-" . $año,
                        'month' => $month,
                        'year' => $año,
                        'jsonBody' => serialize($jsonBody),
                    ));
                }
            }

            PaymentLog::with(['paymentOrder'])->where('state', PaymentLog::PAGADO)
                ->update(array("state" => PaymentLog::TERMINADO));

            PaymentOrderPoint::where('state', true)->update(array("state" => false));
            PaymentProductOrderPoint::where("state", true)->update(array("state" => false));

            RangeUser::where("status", true)->update(array("status" => false));

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function resetUserToTemp(Request $request)
    {
        try {
            DB::beginTransaction();

            $temps = UserEmailTemp::where("status", UserEmailTemp::PENDIENTE)->get();
            $countSend = 0;
            foreach ($temps as $key => $temp) {
                $user = User::where("id", $temp->userId)->first();
                if ($countSend > 5) break;
                if ($temp->isAdmin) {
                    $fileAttachment = storage_path("app/{$temp->fileAttachment}");
                    $mailData = [
                        'customer_name' => $user->name,
                        "subject" => $temp->subject,
                        'attach'    => $fileAttachment
                    ];
                    Mail::to("bossundeveloper258@gmail.com")->send(new UsersPointExcel($mailData));
                    UserEmailTemp::where("id", $temp->id)->update(array(
                        "status" => UserEmailTemp::ENVIADO
                    ));
                } else {

                    $body = unserialize($temp->jsonBody);

                    $mailData = [
                        'customer_name' => $user->name,
                        "subject" => $temp->subject,
                        "month" => Carbon::createFromDate(null, $temp->month, null)->locale('es')->monthName,
                        "patrocinio" => $body['points']->patrocinio,
                        "compra" => $body['points']->compra,
                        "total" => $body['totalPoint'],
                        "residual" => $body['points']->residual,
                        "personal" => $body['points']->personal,
                        "afiliado" => $body['points']->personalGlobal,
                        "infinito" => $body['points']->infinito,
                        "range" => $body['range'],
                        "plan" => $body['pack'],
                        "status" => $body['status'],
                    ];

                    Mail::to("bossundeveloper258@gmail.com")->send(new UserPointActive($mailData));

                    UserEmailTemp::where("id", $temp->id)->update(array(
                        "status" => UserEmailTemp::ENVIADO
                    ));
                    break;
                }
                $countSend++;
            }

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function desactive(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userCode' => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();

            $dataBody = (object) $request->all();
            $userId = Auth::id();

            $userCurrent = User::where("uuid", $dataBody->userCode)->first();

            PaymentLog::where("user_id", $userCurrent->id)
                ->where('state', PaymentLog::PAGADO)
                ->update(array("state" => PaymentLog::TERMINADO));

            PaymentOrderPoint::where("user_id", $userCurrent->id)
                ->where("state", true)
                ->update(array("state" => false, "type" => PaymentOrderPoint::RESET));

            PaymentProductOrder::where("user_id", $userCurrent->id)
                ->update(array("state" => PaymentProductOrder::TERMINADO));

            PaymentProductOrderPoint::where("user_id", $userCurrent->id)
                ->update(array("state" => false));

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function activeResidual(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userCode' => 'required',
            'products'               => 'required|array',
            'products.*.product'     => 'required|exists:products,id',
            'products.*.quantity'    => 'required|numeric'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $user_id = Auth::id();
            DB::beginTransaction();
            $userModel = User::with(['file'])->find($user_id);

            if (!$userModel->is_admin) return $this->sendError("No tiene permisos ese usuario");

            $dataBody = (object) $request->all();
            $userUpdated = User::where("uuid", $dataBody->userCode)->first();

            if ($userUpdated == null) return $this->sendError("No se existe el usuario seleccionado");
            if (count($dataBody->products) == 0) return $this->sendError("No se encuentra productos");

            $paymentLog = PaymentLog::with(['paymentOrder.pack'])
                ->where("user_id",  $userUpdated->id)
                ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])
                ->orderBy('created_at', 'desc')
                ->first();

            $productIds = array();
            foreach ($dataBody->products as $key => $product) {
                $product = (object) $product;
                array_push($productIds, $product->product);
            }

            $productList = Product::whereIn('id', $productIds)->get();
            $productListCreate = array();
            $totalAmount = 0;
            $totalPoints = 0;
            $discount = 0;

            // ✅ Obtener el descuento del paquete del usuario
            if ($paymentLog != null && $paymentLog->paymentOrder && $paymentLog->paymentOrder->pack) {
                $discount = floatval($paymentLog->paymentOrder->pack->discount ?? 0);
            }

            // ✅ Calcular total con descuento para cada producto
            foreach ($productList as $key => $product) {
                $keyDetail = array_search($product->id, array_column($dataBody->products, 'product'));
                $productDetail = (object) $dataBody->products[$keyDetail];

                $subtotal = $product->price * $productDetail->quantity;

                // ✅ Aplicar descuento al subtotal
                if ($discount > 0) {
                    $subtotal = $subtotal * (100 - $discount) / 100;
                }

                $totalAmount += $subtotal;

                // ✅ Calcular puntos del producto según el pack del usuario
                if ($paymentLog?->paymentOrder?->pack_id != null) {
                    $productPointPack = ProductPointPack::where("product_id", $product->id)
                        ->where("pack_id", $paymentLog->paymentOrder->pack_id)
                        ->first();
                    if ($productPointPack != null) {
                        $totalPoints += $productPointPack->point * $productDetail->quantity;
                    }
                }
            }

            // ✅ Crear la orden con el total ya descontado
            $paymentProductOrder = PaymentProductOrder::create(
                array(
                    'currency'  => 'PEN',
                    'amount'    => $totalAmount,
                    'discount'  => $discount,
                    'points'    => $totalPoints,
                    'user_id'   => $userUpdated->id,
                    'pack_id'   => $paymentLog->paymentOrder->pack_id ?? null,
                    'phone'     => "",
                    'address'   => "",
                    'state'     => PaymentProductOrder::PAGADO,
                    'type'      => self::PAYMENT_ADMIN,
                    'token'     => 'NOT_FOUND',
                )
            );

            // ✅ Crear los detalles con los precios ya descontados
            foreach ($productList as $key => $product) {
                $keyDetail = array_search($product->id, array_column($dataBody->products, 'product'));
                $productDetail = (object) $dataBody->products[$keyDetail];

                $price = $product->price;
                $subtotal = $product->price * $productDetail->quantity;
                $_points = 0;

                $productPointPack = ProductPointPack::where("product_id", $product->id)
                    ->where("pack_id", $paymentLog?->paymentOrder?->pack_id)
                    ->first();
                if ($productPointPack != null) {
                    $_points = $productPointPack->point * $productDetail->quantity;
                }

                // ✅ Aplicar descuento al precio y subtotal
                if ($discount > 0) {
                    $price = $price * (100 - $discount) / 100;
                    $subtotal = $subtotal * (100 - $discount) / 100;
                }

                array_push(
                    $productListCreate,
                    array(
                        'payment_product_order_id'  => $paymentProductOrder->id,
                        'product_id'                => $product->id,
                        'product_title'             => $product->title,
                        'quantity'                  => $productDetail->quantity,
                        'price'                     => $price,
                        'subtotal'                  => $subtotal,
                        'points'                    => $_points,
                        'created_at'                => now(),
                        'updated_at'                => now(),
                    )
                );
            }

            PaymentProductOrderDetail::insert($productListCreate);

            PaymentProductOrderPoint::create(
                array(
                    'payment_product_order_id'  => $paymentProductOrder->id,
                    'user_id'                   => $userUpdated->id,
                    'points'                    => $totalPoints,
                    'state'                     => true
                )
            );

            // ====== INYECCIÓN EN EL ÁRBOL PARA QUE EL PATROCINADOR LO VEA ACTIVO ======
            $orderId = $paymentLog ? $paymentLog->payment_order_id : null;
            $sponsorCode = $paymentLog && $paymentLog->paymentOrder ? $paymentLog->paymentOrder->sponsor_code : 'COMPANY';

            // 1. Marca al usuario como activo en la red este mes
            PaymentOrderPoint::create([
                'payment_order_id' => $orderId,
                'user_code'        => $userUpdated->uuid,
                'sponsor_code'     => $sponsorCode,
                'point'            => $totalPoints,
                'payment'          => 1,
                'type'             => PaymentOrderPoint::COMPRA,
                'user_id'          => $userUpdated->id,
                'state'            => true
            ]);

            // 2. Sube el volumen grupal a la línea ascendente
            $currentSponsorCode = $sponsorCode;
            $level = 1;
            while (!empty($currentSponsorCode) && $level <= 15) {
                $sponsorUser = User::where('uuid', $currentSponsorCode)->first();
                if (!$sponsorUser) break;

                $relation = PaymentOrderPoint::where('user_code', $currentSponsorCode)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->first();
                $superiorSponsorCode = $relation ? $relation->sponsor_code : '';

                PaymentOrderPoint::create([
                    'payment_order_id' => $orderId,
                    'user_code'        => $currentSponsorCode,
                    'sponsor_code'     => $superiorSponsorCode,
                    'point'            => $totalPoints,
                    'payment'          => 0,
                    'type'             => PaymentOrderPoint::GRUPAL,
                    'user_id'          => $userUpdated->id,
                    'state'            => true
                ]);
                $currentSponsorCode = $superiorSponsorCode;
                $level++;
            }
            // =========================================================================

            $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $userUpdated->id)
                ->where("state", true)
                ->get();

            $personalPoint = 0;
            foreach ($paymentProductOrderPoints as $key => $paymentProductOrderPoint) {
                $personalPoint = $personalPoint + $paymentProductOrderPoint->points;
            }

            $maxPointsProduct = Option::where("option_key", "max_points_product")->first();

            if ($personalPoint >= floatval($maxPointsProduct->option_value)) {
                $__paymentLog = PaymentLog::with(['paymentOrder.pack'])
                    ->where("user_id",  $userUpdated->id)
                    ->whereIn("state", [PaymentLog::TERMINADO])
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($__paymentLog != null) {
                    $orderId2 = uniqid($paymentLog->paymentOrder->pack->title);

                    $_paymentOrder = PaymentOrder::create(
                        array(
                            'currency' => "PEN",
                            'amount' => $paymentLog->paymentOrder->pack->price,
                            'sponsor_code' => $paymentLog->paymentOrder->sponsor_code,
                            'pack_id' => $paymentLog->paymentOrder->pack_id,
                            "token" => $orderId2
                        )
                    );

                    $this->confirmPoint($_paymentOrder, $userUpdated, $paymentLog->paymentOrder->pack, true);

                    $_paymentLog = PaymentLog::create(
                        array(
                            'payment_order_id' => $_paymentOrder->id,
                            "confirm" => true,
                            'user_id' => $userUpdated->id,
                            "state" => PaymentLog::PAGADO,
                        )
                    );
                }
            }

            $this->confirmPointAfiliado($userUpdated, $totalPoints);

            DB::commit();
            return $this->sendResponse(1, 'Usuario reactivado en la red exitosamente.');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function exportPdfFinance(Request $request)
    {
        try {
            // Obtener mes y año

            $fechaActual = Carbon::now();
            $oneMonthAgo = $fechaActual->subMonth();
            $mes = $oneMonthAgo->translatedFormat('F'); // o 'F' para nombre del mes
            $year = $oneMonthAgo->format('Y');
            $month = $oneMonthAgo->format('m');

            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder.paymentLog', 'userPoint.paymentActive'])
                ->whereRaw('MONTH(created_at) = ?', [$month])->whereRaw('YEAR(created_at) = ?', [$year])
                // ->whereMonth('created_at', $oneMonthAgo->format('m'))->whereYear('created_at', $oneMonthAgo->format('Y'))    
                ->get();

            $patrocinioUserActive = 0;
            $patrocinioUserInactive = 0;

            $residualUserActive = 0;
            $residualUserInactive = 0;

            $infinityUser = 0;

            $totalPoint = 0;

            foreach ($paymentOrderPoints as $key => $paymentOrderPoint) {
                if ($paymentOrderPoint->paymentOrder->paymentLog = PaymentLog::PAGADO) {
                    if ($paymentOrderPoint->type == PaymentOrderPoint::PATROCINIO) $patrocinioUserActive = $patrocinioUserActive + $paymentOrderPoint->point;
                    else if ($paymentOrderPoint->type == PaymentOrderPoint::RESIDUAL) $residualUserActive = $residualUserActive + $paymentOrderPoint->point;
                } else if ($paymentOrderPoint->paymentOrder->paymentLog = PaymentLog::TERMINADO) {
                    if ($paymentOrderPoint->type == PaymentOrderPoint::PATROCINIO) $patrocinioUserInactive = $patrocinioUserInactive + $paymentOrderPoint->point;
                    else if ($paymentOrderPoint->type == PaymentOrderPoint::RESIDUAL) $residualUserInactive = $residualUserInactive + $paymentOrderPoint->point;
                }

                if ($paymentOrderPoint->type == PaymentOrderPoint::INFINITO) $infinityUser = $infinityUser + $paymentOrderPoint->point;

                $totalPoint = $totalPoint + $paymentOrderPoint->point;
            }

            $data = array(
                "mes" => $mes,
                "year" => $year,
                "patrocinioUserActive" => $patrocinioUserActive,
                "patrocinioUserInactive" => $patrocinioUserInactive,
                "residualUserActive" => $residualUserActive,
                "residualUserInactive" => $residualUserInactive,
                "infinityUser" => $infinityUser,
                "totalPoint" => $totalPoint
            );

            // Renderizar vista PDF
            $pdf = Pdf::loadView('pdf.finance', $data)->setPaper('a4', 'portrait');;
            $output = $pdf->output();
            $base64 = base64_encode($output);

            $fecha = Carbon::now()->format('YmdHis');
            $nameFile = "finanzas_{$fecha}.pdf";

            return $this->sendResponse([
                'filename' => $nameFile,
                'mime' => 'application/pdf',
                'base64' => $base64
            ], '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function exportExcelFinance(Request $request)
    {
        try {

            $fechaActual = Carbon::now();

            // Obtener mes y año
            $mes = $fechaActual->translatedFormat('F'); // o 'F' para nombre del mes
            $año = $fechaActual->format('Y');
            $month = $fechaActual->format('m');

            $oneMonthAgo = $fechaActual->subMonth();

            $userAdmin = User::where("is_admin", true)->first();

            $tempUser = UserEmailTemp::where("userId", $userAdmin->id)
                ->where("month", $oneMonthAgo->format('m'))
                ->where("year", $oneMonthAgo->format('Y'))->first();

            $contentFile = Storage::get($tempUser->fileAttachment);

            if ($tempUser == null) {
                return $this->sendError("No se encontro ningun dato pasado");
            }

            $fecha = Carbon::now()->format('YmdHis');
            $nameFile = "reporte_usuarios_{$fecha}.xlsx";

            $base64 = base64_encode($contentFile);

            return $this->sendResponse([
                'filename' => $nameFile,
                'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'base64' => $base64
            ], '');

            // $userList = User::with(['range.range'])->where("is_admin", false)->get();

            // $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder'])->where('state' , true)->get();
            // $jsonBody = array();
            // foreach ($userList as $keyTemp => $_user){
            //     if( $_user->is_admin ) continue;
            //     $_user = (object) $_user;
            //     $_user->payment = PaymentLog::with(['paymentOrder.pack' ])->where( "user_id" ,  $_user->id )
            //     ->where( function ($query) {
            //         $query->where('state' , PaymentLog::PAGADO)
            //         ->orWhere('state' , PaymentLog::TERMINADO);
            //     })
            //     ->orderBy('created_at', 'desc')
            //     ->first();

            //     $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id" , $_user->id)->where("state" , true)->get();

            //     $calculator = $this->calculator->points( $_user->uuid , $paymentOrderPoints , $paymentProductOrderPoints );
            //     $calculatorPoint = $this->calculator->pointsTotal( $_user->uuid , $paymentOrderPoints , $paymentProductOrderPoints );

            //     array_push( $jsonBody , (object) array(
            //         "fullname" => $_user->name,
            //         "email" => $_user->email,
            //         "uuid" => $_user->uuid,
            //         "pack" => $_user->payment?->paymentOrder?->pack?->title ?? "Sin Plan",
            //         "status" => $_user->payment == null ? "--" : ( $_user->payment->state == PaymentLog::PAGADO ? "Activo" : "Inactivo" ),
            //         "totalPoint" => $calculatorPoint,
            //         "range" => $_user->range == null ? "Sin Rango" : $_user->range->range->title,
            //         "points" => (object) array(
            //             "patrocinio"    => $calculator->patrocinio,
            //             "residual"      => $calculator->residual,
            //             "compra"        => $calculator->compra,
            //             "pointGroup"    => $calculator->pointGroup,
            //             "personal"      => $calculator->personal,
            //             "infinito"      => $calculator->infinito,
            //             "pointAfiliado" => $calculator->pointAfiliado,
            //             "personalGlobal" => $calculator->personalGlobal
            //         ),
            //     ) );
            // }

            // // crear archivo excel
            // $excelBody = array();

            // foreach ($jsonBody as $key => $json) {
            //     array_push(
            //         $excelBody,
            //         array(
            //             $json->fullname,
            //             $json->uuid,
            //             $json->status,
            //             $json->pack,
            //             $json->points?->pointAfiliado ?? 0,
            //             $json->points?->patrocinio ?? 0,
            //             $json->points?->residual ?? 0,
            //             ( ($json->points?->pointAfiliado ?? 0) 
            //                 + ($json->points?->patrocinio ?? 0) 
            //                 + ($json->points?->residual ?? 0) 
            //                 + ( ($json->points?->personal ?? 0) * 0.02 ) 
            //             ),
            //             $json->points?->compra ?? 0,
            //             $json->points->personal ?? 0,
            //             $json->points->infinito ?? 0,
            //             $json->totalPoint,
            //             $json->range
            //         )
            //     );
            // }

            // // 1. Guardar Excel
            // $fecha = Carbon::now()->format('YmdHis');
            // $nameFile = "reporte_usuarios_{$fecha}.xlsx";
            // $nameFilePath = "exports/".$nameFile;

            // Excel::store(new ReportExcelUsers($excelBody), $nameFilePath , null, \Maatwebsite\Excel\Excel::XLSX);

            // // Leer archivo y codificar en base64
            // $fileContents = Storage::get($nameFilePath);
            // $base64 = base64_encode($fileContents);

            // // Eliminar el archivo después de codificar
            // Storage::delete($nameFilePath);

            // return $this->sendResponse( [
            //     'filename' => $nameFile,
            //     'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            //     'base64' => $base64
            // ] , '');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function exportPdfProfile(Request $request)
    {
        try {
            // Obtener mes y año
            $user_id = Auth::id();
            $fechaActual = Carbon::now();

            $mes = $fechaActual->translatedFormat('F'); // o 'F' para nombre del mes
            $año = $fechaActual->format('Y');
            $month = $fechaActual->format('m');

            $oneMonthAgo = $fechaActual->subMonth();

            // ---------------------------

            $tempUser = UserEmailTemp::where("userId", $user_id)
                ->where("month", $oneMonthAgo->format('m'))
                ->where("year", $oneMonthAgo->format('Y'))->first();

            if ($tempUser == null) {
                return $this->sendError("No se encontro ningun dato pasado");
            }

            $userModel = User::with(['file', 'range.range.file', 'paymentActive'])->find($user_id);
            // return $this->sendError( "temp" , $tempUser);

            // $payment = PaymentLog::with(['paymentOrder.pack'])
            //         ->where( "user_id" ,  $user_id )
            //         ->where( function ($query) {
            //             $query->where('state' , PaymentLog::PAGADO)
            //             ->orWhere('state' , PaymentLog::TERMINADO);
            //         })
            //         ->orderBy('created_at', 'desc')
            //         ->first();

            // $uuid = $userModel->uuid;

            // $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder.paymentLog'])->where('state' , true)->get();
            // $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id" , $user_id)->where("state" , true)->get();

            // $ranges = Range::where("state" , true)
            //     ->orderBy('points', 'asc')
            //     ->get();

            // $calculatorPoint = $this->calculator->points( $userModel->uuid , $paymentOrderPoints , $paymentProductOrderPoints);
            // $totalPoint = $this->calculator->pointsTotal( $userModel->uuid , $paymentOrderPoints , $paymentProductOrderPoints);        

            // $userModel->payment = $payment;
            // $userModel->podints = $calculatorPoint;

            // ---------------------------

            $_pointTemps = unserialize($tempUser->jsonBody);

            $_pointTemp = array();

            if ($userModel->is_admin) {
                foreach ($_pointTemps as $key => $temp) {
                    if ($temp->email == $userModel->email) {
                        $_pointTemp['points'] = $temp->points;
                        $_pointTemp['totalPoint'] = $temp->totalPoint;
                        $_pointTemp['range'] = $temp->range;
                        $_pointTemp['pack'] = $temp->pack;
                        break;
                    }
                }
            } else {
                $_pointTemp = $_pointTemps;
            }

            $data = array(
                "mes" => $oneMonthAgo->translatedFormat('F'),
                "year" => $oneMonthAgo->format('Y'),
                "code" => $userModel->uuid,
                "fullname" => $userModel->name,
                "address" => $userModel->address,
                "patrocinio" => $_pointTemp['points']->patrocinio,
                "residual" => $_pointTemp['points']->residual,
                "compra"        => $_pointTemp['points']->compra,
                "pointGroup"    => $_pointTemp['points']->pointGroup,
                "personal"      => $_pointTemp['points']->personal,
                "infinito"      =>  $_pointTemp['points']->infinito,
                "pointAfiliado" => $_pointTemp['points']->pointAfiliado,
                "personalGlobal" => $_pointTemp['points']->personalGlobal,

                "totalPoint" => $_pointTemp['totalPoint'],
                "range" => $_pointTemp['range'],
                "plan" => $_pointTemp['pack']
            );

            // Renderizar vista PDF
            $pdf = Pdf::loadView('pdf.userpoint', $data)->setPaper('a4', 'portrait');;
            $output = $pdf->output();
            $base64 = base64_encode($output);

            $fecha = Carbon::now()->format('YmdHis');
            $nameFile = "perfil_{$fecha}.pdf";

            return $this->sendResponse([
                'filename' => $nameFile,
                'mime' => 'application/pdf',
                'base64' => $base64
            ], '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function cashFlowFilter(Request $request)
    {
        try {
            // Obtener mes y año
            $user_id = Auth::id();
            $fechaActual = Carbon::now();

            $mes = $fechaActual->translatedFormat('F'); // o 'F' para nombre del mes
            $year = $fechaActual->format('Y');
            $month = $fechaActual->format('m');

            if ($request->has('month')) if (!empty($request->query('month'))) $month = $request->query('month');
            if ($request->has('year')) if (!empty($request->query('year'))) $year = $request->query('year');

            $paymentOrderPoints = PaymentOrderPoint::whereMonth('created_at', $month)->whereYear('created_at', $year)->where("type", PaymentOrderPoint::COMPRA)->get();

            $paymentProductOrderPoints = PaymentOrderPoint::whereMonth('created_at', $month)->whereYear('created_at', $year)->where("type", PaymentOrderPoint::COMPRA)->get();

            $paymentOrders = PaymentLog::with(['paymentOrder'])->whereRaw('MONTH(created_at) = ?', [$month])->whereRaw('YEAR(created_at) = ?', [$year])->where("state", PaymentLog::PAGADO)->get();
            $paymentProductOrders = PaymentProductOrder::whereRaw('MONTH(created_at) = ?', [$month])->whereRaw('YEAR(created_at) = ?', [$year])->where("state", PaymentProductOrder::PAGADO)->get();

            return $this->sendResponse(
                array(
                    "orders" => $paymentOrders,
                    "products" => $paymentProductOrders
                ),
                ""
            );
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function paymentsAll(Request $request)
    {
        try {

            $limit = $this->limit;

            if ($request->has('limit')) $limit = intval($request->query('limit'));

            $user_id = Auth::id();

            $userCode = "";
            $userCodeCurrent = null;
            if ($request->has('codeuser')) if (!empty($request->query('codeuser'))) {
                $userCodeCurrent = User::where("uuid", $request->query('codeuser'))->first();
                $userCode = $request->query('codeuser');
            }

            $paymentProductOrderList = PaymentProductOrder::with(['fileImage' => function ($query) {
                $query->select('id', 'path');
            }])->select('id', 'file', 'user_id', 'state', 'created_at', DB::raw('0 as plan'), 'pack_id', 'phone', 'points', 'discount', DB::raw("'' as payment_order_id"))->whereIn("state", [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::PREORDER]); // ->with(['user','pack','details']);
            $userNameCurrentIds = array();
            if ($userCodeCurrent != null) {

                $paymentProductOrderList = $paymentProductOrderList->where("user_id", $userCodeCurrent->id);
            }

            if ($request->has('name')) if (!empty($request->query('name'))) {
                $userNameCurrentIds = User::where("name", "like", "%" . $request->query('name') . "%")->pluck('id')->toArray();
                $paymentProductOrderList = $paymentProductOrderList->whereIn("user_id", $userNameCurrentIds);
            }

            $paymentOrders = PaymentLog::with(['fileImage' => function ($query) {
                $query->select('id', 'path');
            }])->select(
                'id',
                DB::raw("'0' as file_id"),
                'user_id',
                'state',
                'created_at',
                DB::raw('1 as plan'),
                DB::raw("'' as pack_id"),
                DB::raw("'' as phone"),
                DB::raw("'' as points"),
                DB::raw("'' as discount"),
                'payment_order_id'
            )->whereIn("state", [PaymentLog::PAGADO, PaymentLog::TERMINADO]);

            if ($userCodeCurrent != null) {
                $paymentOrders = $paymentOrders->where("user_id", $userCodeCurrent->id);
            }

            if ($request->has('name')) if (!empty($request->query('name'))) {
                $userNameCurrentIds = User::where("name", "like", "%" . $request->query('name') . "%")->pluck('id')->toArray();
                $paymentOrders = $paymentOrders->whereIn("user_id", $userNameCurrentIds);
            }

            $paymentUnion = $paymentProductOrderList->union($paymentOrders);

            $paymentUnion = $paymentUnion->orderBy('created_at', 'desc')->paginate($limit);

            foreach ($paymentUnion as $key => $payment) {
                $paymentUnion[$key]->user = User::with(['file'])->find($payment->user_id);
                $paymentUnion[$key]->details = PaymentProductOrderDetail::where('payment_product_order_id', $payment->id)->get();
                if (empty($payment->pack_id)) {
                    $payment_order = PaymentOrder::with(['pack'])->find($payment->payment_order_id);
                    $paymentUnion[$key]->pack = $payment_order?->pack;
                } else {
                    $paymentUnion[$key]->pack = Pack::find($payment->pack_id);
                }
            }

            // $userList = $userList

            return $this->sendResponse(new PaginationCollection($paymentUnion), $userNameCurrentIds);
        } catch (Exception $e) {
            DB::rollBack();
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

            $token = (string) Str::uuid();

            $inviteUser = InviteUser::create(array(
                'sponsor_user_id' => $userId,
                'sponsor_user_code' => $userModel->uuid,
                'token' => $token,
                'state' => true,
                'type' => InviteUser::LINK,
                'expired_time' => $dateNow->addHours(2),
            ));
            DB::commit();
            return $this->sendResponse([
                'code' => $token,
            ], '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function invitedLinkEmail(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'users'               => 'required|array',
            'users.*.code'          => 'required|exists:users,uuid',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $userId = Auth::id();

            DB::beginTransaction();
            $dateNow = Carbon::now();

            $userModel = User::with(['paymentActive'])->find($userId);

            $dataBody = (object) $request->all();

            $token = (string) Str::uuid();

            $inviteUser = InviteUser::create(array(
                'sponsor_user_id' => $userId,
                'sponsor_user_code' => $userModel->uuid,
                'token' => $token,
                'state' => true,
                'type' => InviteUser::EMAIL,
                'expired_time' => $dateNow->addHours(2),
            ));

            // $expiredList = InviteUser::where('expired_time', '<', $dateNow)->get();
            // foreach ($expiredList as $key => $expired) {
            //     GuestsTokenUser::where('invite_user_id', $expired->id)->update(array("state" => false));
            // }
            InviteUser::where('expired_time', '<', $dateNow)->update(array("state" => false));

            $url = env('APP_URL_FRONT') . '/guest/' . $token;

            foreach ($dataBody->users as $key => $user) {
                $user = (object) $user;
                // array_push($usersInvited , $user->code);
                $userInvited = User::where("uuid", $user->code)->first();
                $mailData = [
                    'invited_name' => $userInvited->name,
                    "sponsor_name" => $userModel->name,
                    'url'    => $url
                ];

                // GuestsTokenUser::create(array(
                //     'sponsor_user_code' => $userModel->uuid,
                //     'guest_user_code' => $user->code,
                //     'invite_user_id' => $inviteUser->id,
                //     'state' => true
                // ));

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
            'token'         => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $dataBody = (object) $request->all();
            DB::beginTransaction();

            $dateNow = Carbon::now();
            $inviteUser = InviteUser::where('token', '=', $dataBody->token)->first();

            if ($inviteUser == null) return $this->sendResponse("", "No existe el codigo de invitación.", false);
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
            'token'         => 'required',
            "accept"        => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $userId = Auth::id();
            $userModel = User::with(['paymentActive'])->find($userId);

            DB::beginTransaction();
            $dateNow = Carbon::now();
            $dataBody = (object) $request->all();
            $inviteUser = InviteUser::where('token', '=', $dataBody->token)->first();

            $paymentLog = PaymentLog::where("user_id", $userId)->whereIn("state", [PaymentLog::PAGADO, PaymentLog::TERMINADO])->first();
            if ($paymentLog != null) return $this->sendResponse("", "Este usuario ya tiene un patrocinador.", false);

            if ($inviteUser == null) return $this->sendResponse("", "No existe el codigo de invitación.", false);
            if ($inviteUser->state == false) return $this->sendResponse("", "El codigo de invitación esta desabilitado.", false);
            if ($inviteUser->expired_time < $dateNow) return $this->sendResponse("", "El codigo de invitación ha expirado.", false);

            GuestsTokenUser::create(array(
                'sponsor_user_code' => $inviteUser->sponsor_user_code,
                'guest_user_code' => $userModel->uuid,
                'invite_user_id' => $inviteUser->id,
                'state' => $dataBody->accept
            ));

            InviteUser::where('token', '=', $dataBody->token)->update(array("state" => false));

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
            $userId = Auth::id();
            $userModel = User::with(['paymentActive'])->find($userId);

            DB::beginTransaction();
            $dateNow = Carbon::now();
            $dataBody = (object) $request->all();
            // $inviteUser = InviteUser::where('token', '=', $dataBody->token)->first();

            // if( $inviteUser == null ) return $this->sendResponse( "" , "No existe el codigo de invitación.", false);
            // if( $inviteUser->state == false) return $this->sendResponse( "" , "El codigo de invitación esta desabilitado.", false);
            // if( $inviteUser->expired_time < $dateNow) return $this->sendResponse( "" , "El codigo de invitación ha expirado.", false);

            // GuestsTokenUser::create(array(
            //     'sponsor_user_code' => $inviteUser->sponsor_user_code,
            //     'guest_user_code' => $userModel->uuid,
            //     'invite_user_id' => $inviteUser->id,
            //     'state' => $dataBody->accept
            // ));

            $guestsTokenUser = GuestsTokenUser::where("guest_user_code", $userModel->uuid)->where("state", true)->first();
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
            $userId = Auth::id();
            $userModel = User::with(['paymentActive'])->find($userId);

            DB::beginTransaction();
            $dateNow = Carbon::now();
            $dataBody = (object) $request->all();

            GuestsTokenUser::where("guest_user_code", $userModel->uuid)
                ->where("state", true)
                ->update(array(
                    "state" => false
                ));

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

            // Validaciones básicas
            if (!isset($dataBody->name, $dataBody->email, $dataBody->dni, $dataBody->sponsor, $dataBody->plan, $dataBody->password)) {
                DB::rollBack();
                return $this->sendError("Faltan datos requeridos: name, email, dni, sponsor, plan, password");
            }

            $userExists = User::where("email", $dataBody->email)->first();
            if ($userExists != null) {
                DB::rollBack();
                return $this->sendError("Ese correo electrónico ya existe");
            }

            $userExistDni = User::where("uuid", trim($dataBody->dni))->first();
            if ($userExistDni != null) {
                DB::rollBack();
                return $this->sendError("Este DNI ya existe");
            }

            $sponsor = User::where("uuid", $dataBody->sponsor)->first();
            if ($sponsor == null) {
                DB::rollBack();
                return $this->sendError('Código de Patrocinador no existe.');
            }

            $packCurrent = Pack::find($dataBody->plan);
            if ($packCurrent == null) {
                DB::rollBack();
                return $this->sendError("No existe el plan seleccionado");
            }

            // 1. Crear Usuario
            $userCreated = User::create([
                'name'     => $dataBody->name,
                'email'    => $dataBody->email,
                'uuid'     => trim($dataBody->dni),
                'password' => bcrypt($dataBody->password)
            ]);

            // 2. Generar Código de Verificación
            $codeGenerator = new CodeGenerator();
            VerificationCodeUser::create([
                'user_id' => $userCreated->id,
                'type'  => 1,
                'code' => $codeGenerator->generate(),
                "state" => true
            ]);

            // 3. Crear Orden (Relación por sponsor_code) - 🔧 CORREGIDO: agregar user_id
            $_paymentOrder = PaymentOrder::create([
                'currency' => "PEN",
                'amount' => $packCurrent->price,
                'sponsor_code' => $sponsor->uuid,
                'pack_id' => $dataBody->plan,
                "token" => uniqid($packCurrent->title)
            ]);

            // 4. Crear Log de pago
            $_paymentLog = PaymentLog::create([
                'payment_order_id' => $_paymentOrder->id,
                "confirm" => true,
                'user_id' => $userCreated->id,
                "state" => PaymentLog::PAGADO,
                "message" => "Activación de nuevo socio: " . $packCurrent->title
            ]);

            // 5. ACTIVACIÓN DE PUNTOS Y REPARTO
            $this->confirmPoint($_paymentOrder, $userCreated, $packCurrent);

            DB::commit();

            // Limpiar caché
            \Illuminate\Support\Facades\Cache::forget('existing_user_uuids');

            return $this->sendResponse([
                'user_id' => $userCreated->id,
                'uuid' => $userCreated->uuid,
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
            $user_id = Auth::id();

            $userModel = User::with(['file'])->find($user_id);

            if (!$userModel->is_admin) return $this->sendError("No tiene permisos ese usuario");

            $dataBody = (object) $request->all();

            $userUpdated = User::where("uuid", $dataBody->userCode)->first();

            $list = $this->loopTree(array(), $dataBody->userCode);

            return $this->sendResponse($list, '');
        } catch (Exception $e) {

            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    private function confirmPoint($paymentOrder, $userCurrent, $packCurrent, $reactiveAdmin = false)
    {
        // 1. REGISTRO PERSONAL DEL NUEVO SOCIO (siempre se crea para cada paquete)
        if (!$reactiveAdmin) {
            $existingPersonal = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->first();

            if (!$existingPersonal) {
                PaymentOrderPoint::create([
                    'payment_order_id' => $paymentOrder->id,
                    'user_code'        => $userCurrent->uuid,
                    'sponsor_code'     => $paymentOrder->sponsor_code,
                    'point'            => $packCurrent->points,
                    'payment'          => 1,
                    'type'             => PaymentOrderPoint::COMPRA,
                    'user_id'          => $userCurrent->id,
                    'state'            => true,
                ]);
            }
        }

        // ✅ Verificar si el usuario NUEVO SOCIO ya tiene un pack de la MISMA CATEGORÍA
        $existingPackSameCategory = PaymentOrderPoint::where('user_code', $userCurrent->uuid)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->where('state', true)
            ->whereHas('paymentOrder.pack', function ($q) use ($packCurrent) {
                $q->where('category', $packCurrent->category);
            })
            ->where('payment_order_id', '!=', $paymentOrder->id)
            ->exists();

        // ✅ Determinar si debe generar bono de patrocinio
        $debeGenerarBono = !$existingPackSameCategory;

        $currentSponsorCode   = $paymentOrder->sponsor_code;
        $level                = 1;
        $puntosBaseNuevoSocio = $packCurrent->points;

        // ✅ El tipo de bono depende de la CATEGORÍA del PACK QUE SE ESTÁ AGREGANDO
        $tipoPatrocinioParaEstePack = (strtoupper($packCurrent->category ?? '') === 'PRODUCTO')
            ? PaymentOrderPoint::PATROCINIO          // 'P'
            : PaymentOrderPoint::PATROCINIO_SERVICIO; // 'PS'

        // 🛑 CORRECCIÓN CRÍTICA: Truncar a 1 solo carácter ('P' o 'S')
        $tipoFinal = substr($tipoPatrocinioParaEstePack, 0, 1);

        while (!empty($currentSponsorCode) && $level <= 15) {

            $sponsorUser = User::where('uuid', $currentSponsorCode)->first();
            if (!$sponsorUser) break;

            // ══════════════════════════════════════════════════════
            // BUSCAR EL PACK ACTIVO DEL PATROCINADOR
            // ══════════════════════════════════════════════════════
            $sponsorPack = null;

            // Buscar el pack activo del patrocinador
            $sponsorLog = PaymentLog::where('user_id', $sponsorUser->id)
                ->whereIn('state', [PaymentLog::PAGADO, 2])
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($sponsorLog && $sponsorLog->payment_order_id) {
                $sponsorOrder = PaymentOrder::with('pack')->find($sponsorLog->payment_order_id);
                if ($sponsorOrder && $sponsorOrder->pack) {
                    $sponsorPack = $sponsorOrder;
                }
            }

            // Fallback: buscar en payment_order_points
            if (!$sponsorPack) {
                $sponsorOwnPoint = PaymentOrderPoint::where('user_code', $currentSponsorCode)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->where('state', true)
                    ->whereMonth('created_at', now()->month)
                    ->whereYear('created_at', now()->year)
                    ->with('paymentOrder.pack')
                    ->first();

                if ($sponsorOwnPoint && $sponsorOwnPoint->paymentOrder && $sponsorOwnPoint->paymentOrder->pack) {
                    $sponsorPack = $sponsorOwnPoint->paymentOrder;
                }
            }

            // Fallback 2: cualquier orden
            if (!$sponsorPack) {
                $anyOrder = PaymentOrder::where('sponsor_code', $currentSponsorCode)
                    ->whereHas('payment_log', function ($q) {
                        $q->whereIn('state', [2, PaymentLog::PAGADO])
                            ->whereMonth('created_at', now()->month)
                            ->whereYear('created_at', now()->year);
                    })
                    ->with('pack')
                    ->first();

                if ($anyOrder && $anyOrder->pack) {
                    $sponsorPack = $anyOrder;
                }
            }

            // ══════════════════════════════════════════════════════
            // BUSCAR QUIÉN ESTÁ ARRIBA EN LA RED
            // ══════════════════════════════════════════════════════
            $relation = PaymentOrderPoint::where('user_code', $currentSponsorCode)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->where('state', true)
                ->orderBy('created_at', 'asc')
                ->first();
            $superiorSponsorCode = $relation ? $relation->sponsor_code : '';

            // ══════════════════════════════════════════════════════
            // A. PUNTOS GRUPALES (siempre se crean con 'G')
            // ══════════════════════════════════════════════════════
            $existingGrupal = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                ->where('user_code', $currentSponsorCode)
                ->where('type', PaymentOrderPoint::GRUPAL)
                ->first();

            if (!$existingGrupal) {
                PaymentOrderPoint::create([
                    'payment_order_id' => $paymentOrder->id,
                    'user_code'        => $currentSponsorCode,
                    'sponsor_code'     => $superiorSponsorCode,
                    'point'            => $puntosBaseNuevoSocio,
                    'payment'          => 0,
                    'type'             => PaymentOrderPoint::GRUPAL, // 'G'
                    'user_id'          => $userCurrent->id,
                    'state'            => true,
                ]);
            }

            // ══════════════════════════════════════════════════════
            // B. BONO DE PATROCINIO (SOLO si es primera vez que tiene esta categoría)
            // ══════════════════════════════════════════════════════
            if ($debeGenerarBono && $level <= 5 && $sponsorPack) {

                $sponsorshipConfig = SponsorshipPoint::where('pack_id', $sponsorPack->pack_id)->first();

                if ($sponsorshipConfig) {
                    $field = 'level' . $level;
                    $percent = floatval(str_replace(',', '.', $sponsorshipConfig->$field ?? 0));

                    if ($percent > 0) {
                        $montoDinero = ($puntosBaseNuevoSocio * $percent) / 100;

                        if ($montoDinero > 0) {
                            // Verificar si ya existe este bono para esta orden
                            $existingBonus = PaymentOrderPoint::where('payment_order_id', $paymentOrder->id)
                                ->where('user_code', $currentSponsorCode)
                                ->where('type', $tipoFinal) // ✅ Usamos el tipo truncado
                                ->first();

                            if (!$existingBonus) {
                                PaymentOrderPoint::create([
                                    'payment_order_id' => $paymentOrder->id,
                                    'user_code'        => $currentSponsorCode,
                                    'sponsor_code'     => $superiorSponsorCode,
                                    'point'            => $montoDinero,
                                    'payment'          => 0,
                                    'type'             => $tipoFinal, // ✅ Se guarda 'P' o 'S' en lugar de 'PS'
                                    'user_id'          => $userCurrent->id,
                                    'state'            => true,
                                ]);
                            }
                        }
                    }
                }
            }

            $currentSponsorCode = $superiorSponsorCode;
            $level++;
        }

        \Illuminate\Support\Facades\Cache::forget('existing_user_uuids');
    }

    private function confirmPointAfiliado($userCurrent, $points)
    {
        $paymentLog = PaymentLog::where("user_id", $userCurrent->id)
            ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])->orderBy('created_at', 'desc')->first();
        if ($paymentLog != null) {

            $paymentLogsCount = PaymentLog::where("user_id", $userCurrent->id)
                ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])->count();

            if ($paymentLogsCount > 1) {
                $_paymentOrderPoints = $this->loopTree(array(), $userCurrent->uuid);

                $_userCurrent = User::with(['paymentActive', 'range'])->where('uuid', $userCurrent->uuid)->first();

                $afiliadosPoint = ResidualPoint::first();
                $countLevel = 0;
                foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                    $_paymentOrderPoint = (object) $_paymentOrderPoint;
                    // desabilitado - 15-06
                    // PaymentOrderPoint::create(array(
                    //     'payment_order_id' => $paymentLog->payment_order_id,
                    //     'user_code' => $_paymentOrderPoint->user_code,
                    //     'sponsor_code' => $_paymentOrderPoint->sponsor_code,
                    //     'point' => $points,
                    //     'payment' => false,
                    //     'type' => PaymentOrderPoint::GRUPAL,
                    //     'user_id' => $userCurrent->id
                    // ));
                    $countLevel++;
                    if ($key > 15) continue;
                    // $level = $afiliadosPoint->{'level'.($key)};
                    // $point = $points * floatval($level) / 100;

                    $userSponsor = User::with(['paymentActive', 'range'])->where('uuid', $_paymentOrderPoint->sponsor_code)->first();
                    $point = 0;
                    if ($countLevel == 1) $point = $points * 14 / 100;
                    if ($countLevel == 2) $point = $points * 10 / 100;
                    if ($countLevel == 3) $point = $points * 18 / 100;
                    // ----
                    if ($countLevel == 4 && ($_userCurrent->range?->range_id ?? 0) >= 3) $point = $points * 10 / 100;
                    if ($countLevel == 5 && ($_userCurrent->range?->range_id ?? 0) >= 3) $point = $points * 10 / 100;
                    if ($countLevel == 6 && ($_userCurrent->range?->range_id ?? 0) >= 3) $point = $points * 10 / 100;
                    // ----

                    if ($countLevel == 7 && ($_userCurrent->range?->range_id ?? 0) >= 4) $point = $points * 10 / 100;
                    if ($countLevel == 8 && ($_userCurrent->range?->range_id ?? 0) >= 4) $point = $points * 10 / 100;
                    if ($countLevel == 9 && ($_userCurrent->range?->range_id ?? 0) >= 4) $point = $points * 10 / 100;

                    // ----

                    if ($countLevel == 10 && ($_userCurrent->range?->range_id ?? 0) > 4) $point = $points * 5 / 100;
                    if ($countLevel == 11 && ($_userCurrent->range?->range_id ?? 0) > 4) $point = $points * 5 / 100;
                    if ($countLevel == 12 && ($_userCurrent->range?->range_id ?? 0) > 4) $point = $points * 5 / 100;
                    // ----

                    if ($countLevel == 13 && ($_userCurrent->range?->range_id ?? 0) > 5) $point = $points * 3 / 100;
                    if ($countLevel == 14 && ($_userCurrent->range?->range_id ?? 0) > 5) $point = $points * 3 / 100;
                    if ($countLevel == 15 && ($_userCurrent->range?->range_id ?? 0) > 5) $point = $points * 3 / 100;

                    // antes PaymentOrderPoint::AFILIADOS
                    PaymentOrderPoint::create(array(
                        'payment_order_id' => $paymentLog->payment_order_id,
                        'user_code' => $_paymentOrderPoint->user_code,
                        'sponsor_code' => $_paymentOrderPoint->sponsor_code,
                        'point' => $point,
                        'payment' => false,
                        'type' => PaymentOrderPoint::RESIDUAL,
                        'user_id' => $userCurrent->id
                    ));
                }
            }
        }
    }


    // App/Http/Controllers/Api/UserController.php -> método loopTree()

    private function loopTree(array $a_paymentOrderPoint, string $userCode)
    {
        // Si llegamos al nodo raíz corporativo, detenemos la escala ascendente de forma segura
        if (strtoupper($userCode) === 'DOSB') {
            return $a_paymentOrderPoint;
        }

        // Buscar el sponsor en la tabla transaccional actual
        $paymentOrderPoint = PaymentOrderPoint::select('user_code', 'sponsor_code')
            ->where("user_code", $userCode)
            ->whereIn("type", [PaymentOrderPoint::COMPRA, PaymentOrderPoint::PATROCINIO])
            ->where("state", true)
            ->orderBy('created_at', 'asc')
            ->first();

        // Si no existe, buscar el nodo en la tabla estructural de tokens antiguos
        if ($paymentOrderPoint == null) {
            $guest = GuestsTokenUser::select('guest_user_code as user_code', 'sponsor_user_code as sponsor_code')
                ->where("guest_user_code", $userCode)
                ->where("state", true)
                ->first();

            if ($guest) {
                $paymentOrderPoint = $guest;
            }
        }

        if ($paymentOrderPoint != null && !empty($paymentOrderPoint->sponsor_code)) {
            if ($paymentOrderPoint->sponsor_code == $userCode) {
                return $a_paymentOrderPoint;
            }

            array_push($a_paymentOrderPoint, $paymentOrderPoint);
            return $this->loopTree($a_paymentOrderPoint, $paymentOrderPoint->sponsor_code);
        }

        return $a_paymentOrderPoint;
    }
    /**
     * ============================================================
     * NUEVO MÉTODO: countTotalNetworkRecursive()
     * ============================================================
     * Cuenta recursivamente TODA la red de un usuario
     */
    private function countTotalNetworkRecursive($userCode, &$visited = [])
{
    if (in_array($userCode, $visited)) {
        return 0;
    }
    $visited[] = $userCode;

    $count = 0;

    // 1. Hijos transaccionales (compras directas)
    $transactionalChildren = PaymentOrderPoint::where('sponsor_code', $userCode)
        ->where('type', PaymentOrderPoint::COMPRA)
        ->where('state', true)
        ->where('payment', 1)
        ->pluck('user_code')
        ->toArray();

    // 2. Hijos históricos (invitados)
    $historicalChildren = GuestsTokenUser::where('sponsor_user_code', $userCode)
        ->where('state', true)
        ->pluck('guest_user_code')
        ->toArray();

    // Unificar y evitar duplicados
    $allChildren = array_unique(array_merge($transactionalChildren, $historicalChildren));

    foreach ($allChildren as $child) {
        $count++; // Contar este hijo
        // 🔥 RECURSIVO: Contar TODOS los descendientes de este hijo
        $count += $this->countTotalNetworkRecursive($child, $visited);
    }

    return $count;
}

    /**
     * ============================================================
     * NUEVO MÉTODO: buildDescendantTree()
     * ============================================================
     * Construye el árbol DESCENDENTE (desde la raíz hacia los hijos)
     */
    private function buildDescendantTree($userCode, $currentLevel = 0, $maxLevel = 15)
    {
        if ($currentLevel >= $maxLevel) {
            return null;
        }

        $user = User::where('uuid', $userCode)->first();

        $node = [
            'user_code' => $userCode,
            'user_name' => $user ? $user->name : 'Usuario no encontrado',
            'email' => $user ? $user->email : null,
            'level' => $currentLevel,
            'children' => [],
            'total_children' => 0
        ];

        // 1. HIJOS TRANSACCIONALES
        $transactionalChildren = PaymentOrderPoint::where('sponsor_code', $userCode)
            ->where('type', PaymentOrderPoint::COMPRA)
            ->where('state', true)
            ->where('payment', 1)
            ->orderBy('created_at', 'asc')
            ->get();

        foreach ($transactionalChildren as $child) {
            $childNode = $this->buildDescendantTree($child->user_code, $currentLevel + 1, $maxLevel);
            if ($childNode) {
                $childNode['source'] = 'transactional';
                $node['children'][] = $childNode;
            }
        }

        // 2. HIJOS HISTÓRICOS
        $historicalChildren = GuestsTokenUser::where('sponsor_user_code', $userCode)
            ->where('state', true)
            ->get();

        foreach ($historicalChildren as $child) {
            $exists = false;
            foreach ($node['children'] as $existing) {
                if ($existing['user_code'] == $child->guest_user_code) {
                    $exists = true;
                    break;
                }
            }

            if (!$exists) {
                $childNode = $this->buildDescendantTree($child->guest_user_code, $currentLevel + 1, $maxLevel);
                if ($childNode) {
                    $childNode['source'] = 'historical';
                    $node['children'][] = $childNode;
                }
            }
        }

        $node['total_children'] = count($node['children']);
        return $node;
    }

    /**
     * ============================================================
     * NUEVO MÉTODO: countNodes()
     * ============================================================
     * Cuenta los nodos en un árbol
     */
    private function countNodes($tree)
    {
        if (!$tree) {
            return 0;
        }
        $count = 1;
        if (isset($tree['children']) && is_array($tree['children'])) {
            foreach ($tree['children'] as $child) {
                $count += $this->countNodes($child);
            }
        }
        return $count;
    }

        /**
     * ============================================================
     * NUEVO MÉTODO: getCorporativeDashboard()
     * ============================================================
     * Endpoint ESPECÍFICO para DOSB que devuelve todos los datos
     */
    public function getCorporativeDashboard()
    {
        try {
            $user_id = Auth::id();
            $userModel = User::find($user_id);

            if (!$userModel) {
                return $this->sendError("Usuario no encontrado");
            }

            // OBTENER TODOS LOS INVITADOS DE DOSB
            $directosLegacy = GuestsTokenUser::where('sponsor_user_code', 'DOSB')
                ->where('state', true)
                ->get();

            $totalDirectos = $directosLegacy->count();

            // CONTAR ACTIVOS (con pagos este mes)
            $now = Carbon::now();
            $activos = 0;
            foreach ($directosLegacy as $guest) {
                $user = User::where('uuid', $guest->guest_user_code)->first();
                if ($user) {
                    $hasPayment = PaymentLog::where('user_id', $user->id)
                        ->whereIn('state', [2, 6])
                        ->whereMonth('created_at', $now->month)
                        ->whereYear('created_at', $now->year)
                        ->exists();
                    if ($hasPayment) $activos++;
                }
            }

            // CONTAR RED TOTAL (recursivo)
            $totalRed = $this->countTotalNetworkRecursive('DOSB');

            // PUNTOS
            $puntosPorInvitado = 100;
            $totalPuntos = $totalDirectos * $puntosPorInvitado;

            // ÁRBOL COMPLETO
            $tree = $this->buildDescendantTree('DOSB', 0, 15);

            // USUARIO CON DATOS ACTUALIZADOS
            $userModel->directos = $totalDirectos;
            $userModel->activos = $activos;
            $userModel->red_total = $totalRed;
            $userModel->totalPoints = $totalPuntos;
            $userModel->points = (object) [
                'patrocinio' => 0,
                'residual' => 0,
                'compra' => (object) ['total_puntos' => $totalPuntos],
                'pointGroup' => 0,
                'personal' => $totalPuntos,
                'infinito' => 0,
                'pointAfiliado' => 0,
                'personalGlobal' => 0,
                'patrocinioRequest' => 0,
                'patrocinioServicio' => 0,
                'residualServicio' => 0,
                'legacy_bonus' => $totalPuntos
            ];

            return $this->sendResponse([
                'user' => [
                    'id' => $userModel->id,
                    'name' => $userModel->name,
                    'email' => $userModel->email,
                    'uuid' => $userModel->uuid,
                    'is_admin' => $userModel->is_admin,
                    'photo' => $userModel->photo,
                    'file' => $userModel->file,
                    'address' => $userModel->address,
                    'phone' => $userModel->phone,
                    'created_at' => $userModel->created_at,
                ],
                'dashboard' => [
                    'directos' => $totalDirectos,
                    'activos' => $activos,
                    'red_total' => $totalRed,
                    'puntos_totales' => $totalPuntos,
                    'puntos_por_invitado' => $puntosPorInvitado,
                    'total_invitados' => $totalDirectos,
                ],
                'tree' => $tree,
                'network_summary' => [
                    'total_directs' => $totalDirectos,
                    'total_active' => $activos,
                    'total_network' => $totalRed,
                    'has_legacy_network' => $totalDirectos > 0
                ]
            ], 'Dashboard corporativo obtenido correctamente');

        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }
}
