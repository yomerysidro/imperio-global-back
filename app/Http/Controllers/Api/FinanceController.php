<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use App\Models\User;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
use App\Models\PaymentProductOrder;
use App\Models\PaymentProductOrderPoint;
use App\Models\PaymentProductOrderDetail;
use App\Models\Pack;
use App\Models\Product;
use App\Models\ProductPointPack;
use App\Models\UserEmailTemp;
use App\Models\Range;
use App\Models\RangeUser;
use App\Models\Option;
use App\Services\Core\PointCalculator;
use App\Services\Core\NetworkTreeService;
use App\Services\Core\CommissionService;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ReportExcelUsers;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Mail;
use App\Mail\UsersPointExcel;
use App\Mail\UserPointActive;
use App\Http\Resources\PaginationCollection;
use App\Models\GuestsTokenUser;
use App\Services\Core\CommissionService as CoreCommissionService;

class FinanceController extends BaseController
{
    private $pointCalculator;
    private $networkTreeService;
    private $commissionService;

    public function __construct()
    {
        $this->pointCalculator    = new PointCalculator();
        $this->networkTreeService = new NetworkTreeService();
        $this->commissionService  = new CoreCommissionService();
    }

    public function exportPdfFinance(Request $request)
    {
        try {
            $fechaActual = Carbon::now();
            $oneMonthAgo = $fechaActual->subMonth();
            $mes         = $oneMonthAgo->translatedFormat('F');
            $year        = $oneMonthAgo->format('Y');
            $month       = $oneMonthAgo->format('m');

            $paymentOrderPoints               = PaymentOrderPoint::with(['paymentOrder.paymentLog', 'userPoint.paymentActive'])
                ->whereRaw('MONTH(created_at) = ?', [$month])->whereRaw('YEAR(created_at) = ?', [$year])
                ->get();

            $patrocinioUserActive   = 0;
            $patrocinioUserInactive = 0;
            $residualUserActive     = 0;
            $residualUserInactive   = 0;
            $infinityUser           = 0;
            $totalPoint             = 0;

            foreach ($paymentOrderPoints as $key => $paymentOrderPoint) {
                if ($paymentOrderPoint->paymentOrder->paymentLog->state        == PaymentLog::PAGADO) {
                    if ($paymentOrderPoint->type                               == PaymentOrderPoint::PATROCINIO) $patrocinioUserActive += $paymentOrderPoint->point;
                    else if ($paymentOrderPoint->type                          == PaymentOrderPoint::RESIDUAL) $residualUserActive += $paymentOrderPoint->point;
                } else if ($paymentOrderPoint->paymentOrder->paymentLog->state == PaymentLog::TERMINADO) {
                    if ($paymentOrderPoint->type                               == PaymentOrderPoint::PATROCINIO) $patrocinioUserInactive += $paymentOrderPoint->point;
                    else if ($paymentOrderPoint->type                          == PaymentOrderPoint::RESIDUAL) $residualUserInactive += $paymentOrderPoint->point;
                }

                if ($paymentOrderPoint->type == PaymentOrderPoint::INFINITO) $infinityUser += $paymentOrderPoint->point;
                $totalPoint += $paymentOrderPoint->point;
            }

            $data = [
                "mes"                    => $mes,
                "year"                   => $year,
                "patrocinioUserActive"   => $patrocinioUserActive,
                "patrocinioUserInactive" => $patrocinioUserInactive,
                "residualUserActive"     => $residualUserActive,
                "residualUserInactive"   => $residualUserInactive,
                "infinityUser"           => $infinityUser,
                "totalPoint"             => $totalPoint
            ];

            $pdf    = Pdf::loadView('pdf.finance', $data)->setPaper('a4', 'portrait');
            $output = $pdf->output();
            $base64 = base64_encode($output);

            $fecha    = Carbon::now()->format('YmdHis');
            $nameFile = "finanzas_{$fecha}.pdf";

            return $this->sendResponse([
                'filename' => $nameFile,
                'mime'     => 'application/pdf',
                'base64'   => $base64
            ], '');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function exportExcelFinance(Request $request)
    {
        try {
            $fechaActual = Carbon::now();
            $oneMonthAgo = $fechaActual->copy()->subMonth();

            $userAdmin = User::where("is_admin", true)->first();
            if (!$userAdmin) {
                return $this->sendError("No se encontró un usuario administrador", [], 404);
            }

            $tempUser = UserEmailTemp::where("userId", $userAdmin->id)
                ->where("month", $oneMonthAgo->format('m'))
                ->where("year", $oneMonthAgo->format('Y'))
                ->first();

            if (!$tempUser) {
                return $this->generateExcelReportRealTime($oneMonthAgo);
            }

            if (empty($tempUser->fileAttachment)) {
                return $this->sendError("El registro no tiene un archivo adjunto asociado", [], 404);
            }

            if (!Storage::exists($tempUser->fileAttachment)) {
                return $this->sendError("El archivo no existe en el servidor", [], 404);
            }

            $contentFile = Storage::get($tempUser->fileAttachment);
            $fecha       = Carbon::now()->format('YmdHis');
            $nameFile    = "reporte_usuarios_{$fecha}.xlsx";
            $base64      = base64_encode($contentFile);

            return $this->sendResponse([
                'filename' => $nameFile,
                'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'base64'   => $base64
            ], 'Reporte generado correctamente');

        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    private function generateExcelReportRealTime($date)
    {
        $month = $date->format('m');
        $year  = $date->format('Y');

        $userList                  = User::where("is_admin", false)->get();
        $paymentOrderPoints        = PaymentOrderPoint::where('state', true)->get();
        $paymentProductOrderPoints = PaymentProductOrderPoint::where("state", true)->get();
        $ranges                    = Range::where("state", true)->orderBy('points', 'asc')->get();

        $excelBody = [];

        foreach ($userList as $user) {
            $payment = PaymentLog::with(['paymentOrder.pack'])
                ->where("user_id", $user->id)
                ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])
                ->orderBy('created_at', 'desc')
                ->first();

            $calculator = $this->pointCalculator->points(
                $user->uuid,
                $paymentOrderPoints,
                $paymentProductOrderPoints->where('user_id', $user->id)
            );

            $totalPoints = $calculator->patrocinio + $calculator->residual + $calculator->compra->total_puntos + $calculator->pointGroup + $calculator->personal;

            $rangeCurrent = null;
            $directs      = PaymentOrderPoint::where('sponsor_code', $user->uuid)
                ->where('type', PaymentOrderPoint::COMPRA)
                ->where('state', true)
                ->where('payment', 1)
                ->count();

            foreach ($ranges as $range) {
                if ($range->points <= $totalPoints && $range->childs <= $directs) {
                    $rangeCurrent    = $range;
                    break;
                }
            }

            $excelBody[] = [
                $user->name,
                $user->uuid,
                $payment ? ($payment->state == PaymentLog::PAGADO ? "Activo" : "Inactivo") : "Sin plan",
                $payment?->paymentOrder?->pack?->title ?? "Sin plan",
                $calculator->pointAfiliado ?? 0,
                $calculator->patrocinio ?? 0,
                $calculator->residual ?? 0,
                ($calculator->pointAfiliado ?? 0) + ($calculator->patrocinio ?? 0) + ($calculator->residual ?? 0) + (($calculator->personal ?? 0) * 0.02),
                $calculator->compra->total_puntos ?? 0,
                $calculator->personal ?? 0,
                $calculator->infinito ?? 0,
                $totalPoints,
                $rangeCurrent?->title ?? "Sin rango"
            ];
        }

        $fecha        = Carbon::now()->format('YmdHis');
        $nameFile     = "reporte_usuarios_{$fecha}.xlsx";
        $nameFilePath = "exports/" . $nameFile;

        Excel::store(new ReportExcelUsers($excelBody), $nameFilePath, null, \Maatwebsite\Excel\Excel::XLSX);

        $fileContents = Storage::get($nameFilePath);
        $base64       = base64_encode($fileContents);

        Storage::delete($nameFilePath);

        return $this->sendResponse([
            'filename' => $nameFile,
            'mime'     => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'base64'   => $base64
        ], 'Reporte generado en tiempo real');
    }

    public function exportPdfProfile(Request $request)
    {
        try {
            $user_id     = Auth::id();
            $fechaActual = Carbon::now();

            $oneMonthAgo = $fechaActual->subMonth();

            $tempUser = UserEmailTemp::where("userId", $user_id)
                ->where("month", $oneMonthAgo->format('m'))
                ->where("year", $oneMonthAgo->format('Y'))->first();

            if ($tempUser == null) {
                return $this->sendError("No se encontro ningun dato pasado");
            }

            $userModel = User::with(['file', 'range.range.file', 'paymentActive'])->find($user_id);

            $_pointTemps = unserialize($tempUser->jsonBody);
            $_pointTemp  = [];

            if ($userModel->is_admin) {
                foreach ($_pointTemps as $key => $temp) {
                    if ($temp->email              == $userModel->email) {
                        $_pointTemp['points']     = $temp->points;
                        $_pointTemp['totalPoint'] = $temp->totalPoint;
                        $_pointTemp['range']      = $temp->range;
                        $_pointTemp['pack']       = $temp->pack;
                        break;
                    }
                }
            } else {
                $_pointTemp = $_pointTemps;
            }

            $data = [
                "mes"            => $oneMonthAgo->translatedFormat('F'),
                "year"           => $oneMonthAgo->format('Y'),
                "code"           => $userModel->uuid,
                "fullname"       => $userModel->name,
                "address"        => $userModel->address,
                "patrocinio"     => $_pointTemp['points']->patrocinio,
                "residual"       => $_pointTemp['points']->residual,
                "compra"         => $_pointTemp['points']->compra,
                "pointGroup"     => $_pointTemp['points']->pointGroup,
                "personal"       => $_pointTemp['points']->personal,
                "infinito"       => $_pointTemp['points']->infinito,
                "pointAfiliado"  => $_pointTemp['points']->pointAfiliado,
                "personalGlobal" => $_pointTemp['points']->personalGlobal,
                "totalPoint"     => $_pointTemp['totalPoint'],
                "range"          => $_pointTemp['range'],
                "plan"           => $_pointTemp['pack']
            ];

            $pdf    = Pdf::loadView('pdf.userpoint', $data)->setPaper('a4', 'portrait');
            $output = $pdf->output();
            $base64 = base64_encode($output);

            $fecha    = Carbon::now()->format('YmdHis');
            $nameFile = "perfil_{$fecha}.pdf";

            return $this->sendResponse([
                'filename' => $nameFile,
                'mime'     => 'application/pdf',
                'base64'   => $base64
            ], '');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function cashFlowFilter(Request $request)
    {
        try {
            $fechaActual = Carbon::now();

            $year  = $fechaActual->format('Y');
            $month = $fechaActual->format('m');

            if ($request->has('month') && !empty($request->query('month'))) $month = $request->query('month');
            if ($request->has('year') && !empty($request->query('year'))) $year    = $request->query('year');

            $paymentOrders        = PaymentLog::with(['paymentOrder'])->whereRaw('MONTH(created_at) = ?', [$month])->whereRaw('YEAR(created_at) = ?', [$year])->where("state", PaymentLog::PAGADO)->get();
            $paymentProductOrders = PaymentProductOrder::whereRaw('MONTH(created_at) = ?', [$month])->whereRaw('YEAR(created_at) = ?', [$year])->where("state", PaymentProductOrder::PAGADO)->get();

            return $this->sendResponse([
                "orders"   => $paymentOrders,
                "products" => $paymentProductOrders
            ], "");
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function paymentsAll(Request $request)
    {
        try {
            $limit                             = $this->limit;
            if ($request->has('limit')) $limit = intval($request->query('limit'));

            $userCodeCurrent = null;
            if ($request->has('codeuser') && !empty($request->query('codeuser'))) {
                $userCodeCurrent = User::where("uuid", $request->query('codeuser'))->first();
            }

            $paymentProductOrderList = PaymentProductOrder::with(['fileImage' => function ($query) {
                $query->select('id', 'path');
            }])->select('id', 'file', 'user_id', 'state', 'created_at', DB::raw('0 as plan'), 'pack_id', 'phone', 'points', 'discount', DB::raw("'' as payment_order_id"))->whereIn("state", [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::PREORDER]);
            
            $userNameCurrentIds          = [];
            if ($userCodeCurrent != null) {
                $paymentProductOrderList = $paymentProductOrderList->where("user_id", $userCodeCurrent->id);
            }

            if ($request->has('name') && !empty($request->query('name'))) {
                $userNameCurrentIds      = User::where("name", "like", "%" . $request->query('name') . "%")->pluck('id')->toArray();
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
                $paymentOrders     = $paymentOrders->where("user_id", $userCodeCurrent->id);
            }

            if ($request->has('name') && !empty($request->query('name'))) {
                $userNameCurrentIds = User::where("name", "like", "%" . $request->query('name') . "%")->pluck('id')->toArray();
                $paymentOrders      = $paymentOrders->whereIn("user_id", $userNameCurrentIds);
            }

            $paymentUnion = $paymentProductOrderList->union($paymentOrders);
            $paymentUnion = $paymentUnion->orderBy('created_at', 'desc')->paginate($limit);

            foreach ($paymentUnion as $key => $payment) {
                $paymentUnion[$key]->user    = User::with(['file'])->find($payment->user_id);
                $paymentUnion[$key]->details = PaymentProductOrderDetail::where('payment_product_order_id', $payment->id)->get();
                if (empty($payment->pack_id)) {
                    $payment_order            = PaymentOrder::with(['pack'])->find($payment->payment_order_id);
                    $paymentUnion[$key]->pack = $payment_order?->pack;
                } else {
                    $paymentUnion[$key]->pack = Pack::find($payment->pack_id);
                }
            }

            return $this->sendResponse(new PaginationCollection($paymentUnion), $userNameCurrentIds);
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function resetAllPoint(Request $request)
    {
        try {
            setlocale(LC_TIME, 'es_ES.UTF-8');
            Carbon::setLocale('es');
            DB::beginTransaction();

            $userList           = User::with(['range.range'])->get();
            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder'])->where('state', true)->get();
            $fechaActual        = Carbon::now();

            $mes   = $fechaActual->translatedFormat('F');
            $año   = $fechaActual->format('Y');
            $month = $fechaActual->format('m');

            $subject = "Resumen General de puntos y bonos del último mes - Imperio Global";

            foreach ($userList as $key => $user) {
                if ($user->is_admin) {
                    $jsonBody = [];
                    foreach ($userList as $keyTemp => $_user) {
                        if ($_user->is_admin) continue;
                        $_user          = (object) $_user;
                        $_user->payment = PaymentLog::with(['paymentOrder.pack'])->where("user_id",  $_user->id)
                            ->where(function ($query) {
                                $query->where('state', PaymentLog::PAGADO)
                                    ->orWhere('state', PaymentLog::TERMINADO);
                            })
                            ->orderBy('created_at', 'desc')
                            ->first();

                        $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $_user->id)->where("state", true)->get();

                        $calculator      = $this->pointCalculator->points($_user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
                        $calculatorPoint = $this->pointCalculator->pointsTotal($_user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);

                        array_push($jsonBody, (object) [
                            "fullname"           => $_user->name,
                            "email"              => $_user->email,
                            "uuid"               => $_user->uuid,
                            "pack"               => $_user->payment?->paymentOrder?->pack?->title ?? "Sin Plan",
                            "status"             => $_user->payment == null ? "--" : ($_user->payment->state == PaymentLog::PAGADO ? "Activo" : "Inactivo"),
                            "totalPoint"         => $calculatorPoint,
                            "range"              => $_user->range == null ? "Sin Rango" : $_user->range->range->title,
                            "points"             => (object) [
                                "patrocinio"     => $calculator->patrocinio,
                                "residual"       => $calculator->residual,
                                "compra"         => $calculator->compra,
                                "pointGroup"     => $calculator->pointGroup,
                                "personal"       => $calculator->personal,
                                "infinito"       => $calculator->infinito,
                                "pointAfiliado"  => $calculator->pointAfiliado,
                                "personalGlobal" => $calculator->personalGlobal
                            ],
                        ]);
                    }

                    $excelBody = [];
                    foreach ($jsonBody as $key => $json) {
                        array_push($excelBody, [
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
                        ]);
                    }

                    $fecha    = Carbon::now()->format('YmdHis');
                    $nameFile = "exports/reporte_usuarios_{$fecha}.xlsx";

                    Excel::store(new ReportExcelUsers($excelBody), $nameFile);

                    UserEmailTemp::create([
                        'userId'         => $user->id,
                        'isAdmin'        => $user->is_admin,
                        'status'         => UserEmailTemp::PENDIENTE,
                        'email'          => $user->email,
                        'subject'        => $subject . " " . strtoupper($mes) . "-" . $año,
                        'month'          => $month,
                        'year'           => $año,
                        'jsonBody'       => serialize($jsonBody),
                        'fileAttachment' => $nameFile
                    ]);
                } else {
                    $user          = (object) $user;
                    $user->payment = PaymentLog::with(['paymentOrder.pack'])->where("user_id",  $user->id)
                        ->where(function ($query) {
                            $query->where('state', PaymentLog::PAGADO)
                                ->orWhere('state', PaymentLog::TERMINADO);
                        })
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if ($user->payment == null) continue;

                    $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $user->id)->where("state", true)->get();

                    $calculator           = $this->pointCalculator->points($user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
                    $calculatorTotalPoint = $this->pointCalculator->pointsTotal($user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);

                    $jsonBody = [
                        "email"              => $user->email,
                        "range"              => $user->range == null ? "Sin Rango" : $user->range->range->title,
                        "pack"               => $user->payment?->paymentOrder?->pack?->title ?? "Sin Plan",
                        "status"             => $user->payment == null ? "--" : ($user->payment->state == PaymentLog::PAGADO ? "Activo" : "Inactivo"),
                        "points"             => (object) [
                            "patrocinio"     => $calculator->patrocinio,
                            "residual"       => $calculator->residual,
                            "compra"         => $calculator->compra,
                            "pointGroup"     => $calculator->pointGroup,
                            "personal"       => $calculator->personal,
                            "infinito"       => $calculator->infinito,
                            "pointAfiliado"  => $calculator->pointAfiliado,
                            "personalGlobal" => $calculator->personalGlobal
                        ],
                        "totalPoint" => $calculatorTotalPoint
                    ];

                    UserEmailTemp::create([
                        'userId'   => $user->id,
                        'isAdmin'  => $user->is_admin,
                        'status'   => UserEmailTemp::PENDIENTE,
                        'email'    => $user->email,
                        'subject'  => $subject . " " . strtoupper($mes) . "-" . $año,
                        'month'    => $month,
                        'year'     => $año,
                        'jsonBody' => serialize($jsonBody),
                    ]);
                }
            }

            PaymentLog::with(['paymentOrder'])->where('state', PaymentLog::PAGADO)
                ->update(["state" => PaymentLog::TERMINADO]);

            PaymentOrderPoint::where('state', true)->update(["state"        => false]);
            PaymentProductOrderPoint::where("state", true)->update(["state" => false]);
            RangeUser::where("status", true)->update(["status"              => false]);

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
            $temps     = UserEmailTemp::where("status", UserEmailTemp::PENDIENTE)->get();
            $countSend = 0;

            foreach ($temps as $key => $temp) {
                $user = User::where("id", $temp->userId)->first();
                if ($countSend > 5) break;

                if ($temp->isAdmin) {
                    $fileAttachment = storage_path("app/{$temp->fileAttachment}");
                    $mailData       = [
                        'customer_name' => $user->name,
                        "subject"       => $temp->subject,
                        'attach'        => $fileAttachment
                    ];
                    Mail::to("bossundeveloper258@gmail.com")->send(new UsersPointExcel($mailData));
                    UserEmailTemp::where("id", $temp->id)->update(["status" => UserEmailTemp::ENVIADO]);
                } else {
                    $body     = unserialize($temp->jsonBody);
                    $mailData = [
                        'customer_name' => $user->name,
                        "subject"       => $temp->subject,
                        "month"         => Carbon::createFromDate(null, $temp->month, null)->locale('es')->monthName,
                        "patrocinio"    => $body['points']->patrocinio,
                        "compra"        => $body['points']->compra,
                        "total"         => $body['totalPoint'],
                        "residual"      => $body['points']->residual,
                        "personal"      => $body['points']->personal,
                        "afiliado"      => $body['points']->personalGlobal,
                        "infinito"      => $body['points']->infinito,
                        "range"         => $body['range'],
                        "plan"          => $body['pack'],
                        "status"        => $body['status'],
                    ];

                    Mail::to("bossundeveloper258@gmail.com")->send(new UserPointActive($mailData));
                    UserEmailTemp::where("id", $temp->id)->update(["status" => UserEmailTemp::ENVIADO]);
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

    public function activeResidual(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'userCode'            => 'required',
            'products'            => 'required|array',
            'products.*.product'  => 'required|exists:products,id',
            'products.*.quantity' => 'required|numeric'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $user_id = Auth::id();
            DB::beginTransaction();
            $userModel = User::with(['file'])->find($user_id);

            if (!$userModel->is_admin) return $this->sendError("No tiene permisos ese usuario");

            $dataBody    = (object) $request->all();
            $userUpdated = User::where("uuid", $dataBody->userCode)->first();

            if ($userUpdated               == null) return $this->sendError("No se existe el usuario seleccionado");
            if (count($dataBody->products) == 0) return $this->sendError("No se encuentra productos");

            $paymentLog = PaymentLog::with(['paymentOrder.pack'])
                ->where("user_id",  $userUpdated->id)
                ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])
                ->orderBy('created_at', 'desc')
                ->first();

            $productIds = [];
            foreach ($dataBody->products as $key => $product) {
                $product = (object) $product;
                array_push($productIds, $product->product);
            }

            $productList       = Product::whereIn('id', $productIds)->get();
            $productListCreate = [];
            $totalAmount       = 0;
            $totalPoints       = 0;
            $discount          = 0;

            if ($paymentLog != null && $paymentLog->paymentOrder && $paymentLog->paymentOrder->pack) {
                $discount     = floatval($paymentLog->paymentOrder->pack->discount ?? 0);
            }

            foreach ($productList as $key => $product) {
                $keyDetail     = array_search($product->id, array_column($dataBody->products, 'product'));
                $productDetail = (object) $dataBody->products[$keyDetail];
                $subtotal      = $product->price * $productDetail->quantity;

                if ($discount > 0) {
                    $subtotal = $subtotal * (100 - $discount) / 100;
                }
                $totalAmount += $subtotal;

                if ($paymentLog?->paymentOrder?->pack_id != null) {
                    $productPointPack                      = ProductPointPack::where("product_id", $product->id)
                        ->where("pack_id", $paymentLog->paymentOrder->pack_id)
                        ->first();
                    if ($productPointPack != null) {
                        $totalPoints += $productPointPack->point * $productDetail->quantity;
                    }
                }
            }

            $paymentProductOrder = PaymentProductOrder::create([
                'currency' => 'PEN',
                'amount'   => $totalAmount,
                'discount' => $discount,
                'points'   => $totalPoints,
                'user_id'  => $userUpdated->id,
                'pack_id'  => $paymentLog->paymentOrder->pack_id ?? null,
                'phone'    => "",
                'address'  => "",
                'state'    => PaymentProductOrder::PAGADO,
                'type'     => self::PAYMENT_ADMIN,
                'token'    => 'NOT_FOUND',
            ]);

            foreach ($productList as $key => $product) {
                $keyDetail     = array_search($product->id, array_column($dataBody->products, 'product'));
                $productDetail = (object) $dataBody->products[$keyDetail];

                $price    = $product->price;
                $subtotal = $product->price * $productDetail->quantity;
                $_points  = 0;

                $productPointPack = ProductPointPack::where("product_id", $product->id)
                    ->where("pack_id", $paymentLog?->paymentOrder?->pack_id)
                    ->first();
                if ($productPointPack != null) {
                    $_points            = $productPointPack->point * $productDetail->quantity;
                }

                if ($discount > 0) {
                    $price    = $price * (100 - $discount) / 100;
                    $subtotal = $subtotal * (100 - $discount) / 100;
                }

                array_push($productListCreate, [
                    'payment_product_order_id' => $paymentProductOrder->id,
                    'product_id'               => $product->id,
                    'product_title'            => $product->title,
                    'quantity'                 => $productDetail->quantity,
                    'price'                    => $price,
                    'subtotal'                 => $subtotal,
                    'points'                   => $_points,
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]);
            }

            PaymentProductOrderDetail::insert($productListCreate);

            PaymentProductOrderPoint::create([
                'payment_product_order_id' => $paymentProductOrder->id,
                'user_id'                  => $userUpdated->id,
                'points'                   => $totalPoints,
                'state'                    => true
            ]);

            $orderId     = $paymentLog ? $paymentLog->payment_order_id : null;
            $sponsorCode = $paymentLog && $paymentLog->paymentOrder ? $paymentLog->paymentOrder->sponsor_code : 'COMPANY';

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

            $currentSponsorCode = $sponsorCode;
            $level              = 1;
            
            while (!empty($currentSponsorCode) && $level <= 15) {
                $sponsorUser                               = User::where('uuid', $currentSponsorCode)->first();
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

            $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $userUpdated->id)
                ->where("state", true)
                ->get();

            $personalPoint = 0;
            foreach ($paymentProductOrderPoints as $key => $paymentProductOrderPoint) {
                $personalPoint += $paymentProductOrderPoint->points;
            }

            $maxPointsProduct = Option::where("option_key", "max_points_product")->first();

            if ($personalPoint >= floatval($maxPointsProduct->option_value)) {
                $__paymentLog    = PaymentLog::with(['paymentOrder.pack'])
                    ->where("user_id",  $userUpdated->id)
                    ->whereIn("state", [PaymentLog::TERMINADO])
                    ->orderBy('created_at', 'desc')
                    ->first();

                if ($__paymentLog != null) {
                    $orderId2       = uniqid($paymentLog->paymentOrder->pack->title);

                    $_paymentOrder = PaymentOrder::create([
                        'currency'     => "PEN",
                        'amount'       => $paymentLog->paymentOrder->pack->price,
                        'sponsor_code' => $paymentLog->paymentOrder->sponsor_code,
                        'pack_id'      => $paymentLog->paymentOrder->pack_id,
                        "token"        => $orderId2
                    ]);

                    $this->commissionService->confirmPoint($_paymentOrder, $userUpdated, $paymentLog->paymentOrder->pack, true);

                    $_paymentLog = PaymentLog::create([
                        'payment_order_id' => $_paymentOrder->id,
                        "confirm"          => true,
                        'user_id'          => $userUpdated->id,
                        "state"            => PaymentLog::PAGADO,
                    ]);
                }
            }

            $this->commissionService->confirmPointAfiliado($userUpdated, $totalPoints);

            DB::commit();
            return $this->sendResponse(1, 'Usuario reactivado en la red exitosamente.');
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

    public function resetAll(Request $request)
    {
        try {
            PaymentLog::with(['paymentOrder'])->where('state', PaymentLog::PAGADO)
                ->update(["state" => PaymentLog::TERMINADO]);

            PaymentOrderPoint::where('state', true)->update(["state"                          => false, "type" => PaymentOrderPoint::RESET]);
            PaymentProductOrderPoint::where("state", true)->update(["state"                   => false]);
            PaymentProductOrder::where("state", PaymentProductOrder::PAGADO)->update(["state" => PaymentProductOrder::TERMINADO]);
            RangeUser::where("status", true)->update(["status"                                => false]);

            return $this->sendResponse(1, '');
        } catch (Exception $e) {
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

            $dataBody    = (object) $request->all();
            $userCurrent = User::where("uuid", $dataBody->userCode)->first();

            PaymentLog::where("user_id", $userCurrent->id)
                ->where('state', PaymentLog::PAGADO)
                ->update(["state" => PaymentLog::TERMINADO]);

            PaymentOrderPoint::where("user_id", $userCurrent->id)
                ->where("state", true)
                ->update(["state" => false, "type" => PaymentOrderPoint::RESET]);

            PaymentProductOrder::where("user_id", $userCurrent->id)->update(["state"      => PaymentProductOrder::TERMINADO]);
            PaymentProductOrderPoint::where("user_id", $userCurrent->id)->update(["state" => false]);

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
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
            $dataBody    = (object) $request->all();
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

            PaymentOrderPoint::where("user_id", $userCurrent->id)->update(["sponsor_code" => $dataBody->sponsorCode]);

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }
    
    public function getCorporativeDashboard()
    {
        try {
            $user_id   = Auth::id();
            $userModel = User::find($user_id);

            if (!$userModel) {
                return $this->sendError("Usuario no encontrado");
            }

            $directosLegacy = GuestsTokenUser::where('sponsor_user_code', 'DOSB')
                ->where('state', true)
                ->get();

            $totalDirectos = $directosLegacy->count();

            $now     = Carbon::now();
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

            // Usando el servicio inyectado
            $totalRed = $this->networkTreeService->countTotalNetworkRecursive('DOSB');
            $tree     = $this->networkTreeService->buildDescendantTree('DOSB', 0, 15);

            $puntosPorInvitado = 100;
            $totalPuntos       = $totalDirectos * $puntosPorInvitado;

            $userModel->directos    = $totalDirectos;
            $userModel->activos     = $activos;
            $userModel->red_total   = $totalRed;
            $userModel->totalPoints = $totalPuntos;
            $userModel->points      = (object) [
                'patrocinio'         => 0,
                'residual'           => 0,
                'compra'             => (object) ['total_puntos' => $totalPuntos],
                'pointGroup'         => 0,
                'personal'           => $totalPuntos,
                'infinito'           => 0,
                'pointAfiliado'      => 0,
                'personalGlobal'     => 0,
                'patrocinioRequest'  => 0,
                'patrocinioServicio' => 0,
                'residualServicio'   => 0,
                'legacy_bonus'       => $totalPuntos
            ];

            return $this->sendResponse([
                'user' => [
                    'id'         => $userModel->id,
                    'name'       => $userModel->name,
                    'email'      => $userModel->email,
                    'uuid'       => $userModel->uuid,
                    'is_admin'   => $userModel->is_admin,
                    'photo'      => $userModel->photo,
                    'file'       => $userModel->file,
                    'address'    => $userModel->address,
                    'phone'      => $userModel->phone,
                    'created_at' => $userModel->created_at,
                ],
                'dashboard' => [
                    'directos'            => $totalDirectos,
                    'activos'             => $activos,
                    'red_total'           => $totalRed,
                    'puntos_totales'      => $totalPuntos,
                    'puntos_por_invitado' => $puntosPorInvitado,
                    'total_invitados'     => $totalDirectos,
                ],
                'tree'                   => $tree,
                'network_summary' => [
                    'total_directs'      => $totalDirectos,
                    'total_active'       => $activos,
                    'total_network'      => $totalRed,
                    'has_legacy_network' => $totalDirectos > 0
                ]
            ], 'Dashboard corporativo obtenido correctamente');

        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
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

            $list = $this->networkTreeService->loopTree([], $dataBody->userCode);

            return $this->sendResponse($list, '');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }
}