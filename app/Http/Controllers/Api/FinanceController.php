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
use App\Services\Core\ActivationService;
use App\Services\Core\InitialActivationDeactivationService;
use App\Services\Core\FinancialLedgerService;
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
use App\Models\SponsorRelation;
use App\Models\ManualReactivation;
use App\Models\ReactivationRule;
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

            $from = Carbon::create((int) $year, (int) $month, 1)->startOfMonth();
            $to = $from->copy()->endOfMonth();
            $ledger = app(FinancialLedgerService::class)->summary($from, $to);

            $data = [
                "mes"                    => $mes,
                "year"                   => $year,
                "patrocinioUserActive"   => $ledger['patrocinio'],
                "patrocinioUserInactive" => 0,
                "residualUserActive"     => $ledger['residual'],
                "residualProductActive"  => $ledger['residualProducto'],
                "residualServiceActive"  => $ledger['residualServicio'],
                "residualUserInactive"   => 0,
                "infinityUser"           => $ledger['infinito'],
                "totalPoint"             => $ledger['total_comisiones'],
                "ledger"                 => $ledger,
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
            // El Excel descargable corresponde al ultimo mes cerrado. El mes
            // actual sigue acumulando movimientos para el siguiente cierre.
            $reportDate = Carbon::now()->subMonth();
            if ($request->filled('month') || $request->filled('year')) {
                $reportMonth = (int) $request->input('month', $reportDate->month);
                $reportYear = (int) $request->input('year', $reportDate->year);
                if ($reportMonth < 1 || $reportMonth > 12 || $reportYear < 2000) {
                    return $this->sendError('Periodo invalido.', [], 422);
                }
                $reportDate = Carbon::create($reportYear, $reportMonth, 1);
            }

            // Siempre se regenera: un adjunto historico puede contener
            // comisiones que luego fueron anuladas.
            return $this->generateExcelReportRealTime($reportDate);

        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    private function generateExcelReportRealTime($date)
    {
        // La exportacion procesa a todos los socios y genera un XLSX en la
        // misma peticion. Se le da un margen propio sin alterar el limite
        // general de la API.
        if (function_exists('set_time_limit')) {
            set_time_limit(180);
        }

        $month = $date->format('m');
        $year  = $date->format('Y');
        $from  = $date->copy()->startOfMonth();
        $to    = $date->copy()->endOfMonth();
        $isCurrentPeriod = $from->format('Y-m') === Carbon::now('America/Lima')->format('Y-m');

        $userList = User::orderBy('is_admin')->orderBy('name')->get();
        $paymentOrderPoints = PaymentOrderPoint::whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            // El cierre desactiva B/G, pero el Excel del ciclo cerrado debe
            // conservar ese volumen. En el mes abierto sí se excluyen bajas.
            ->when($isCurrentPeriod, fn ($query) => $query->where('state', true))
            ->get();
        $paymentProductOrderPoints = PaymentProductOrderPoint::whereMonth('created_at', $month)
            ->whereYear('created_at', $year)
            ->when($isCurrentPeriod, fn ($query) => $query->where('state', true))
            ->get();
        $ranges                    = Range::where("state", true)->orderBy('points', 'asc')->get();
        $ledger                    = app(FinancialLedgerService::class);
        $payoutSummaries           = $ledger->payoutSummaries($from, $to, $userList);

        $excelBody = [];
        $global = ['personal' => 0.0, 'patrocinio' => 0.0, 'cobrado' => 0.0,
            'residual_producto' => 0.0, 'residual_servicio' => 0.0,
            'residual' => 0.0, 'infinito' => 0.0, 'total' => 0.0,
            'pending' => 0.0, 'paid' => 0.0, 'available' => 0.0];

        foreach ($userList as $user) {
            $payment = PaymentLog::with(['paymentOrder.pack'])
                ->where("user_id", $user->id)
                ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO, PaymentLog::RESET])
                ->orderBy('created_at', 'desc')
                ->first();
            $productPayment = PaymentProductOrder::with('pack')
                ->where('user_id', $user->id)
                ->whereIn('state', [
                    PaymentProductOrder::PAGADO,
                    PaymentProductOrder::ENVIADO,
                    PaymentProductOrder::TERMINADO,
                ])
                ->latest('created_at')
                ->first();
            $latestPackagePayment = collect([$payment, $productPayment])
                ->filter()->sortByDesc('created_at')->first();

            $calculator = $this->pointCalculator->points(
                $user->uuid,
                $paymentOrderPoints,
                $paymentProductOrderPoints->where('user_id', $user->id)
            );
            $payout = $payoutSummaries->get($user->id);
            $commissions = $payout['commissions'];

            $totalPoints = $calculator->personal + $calculator->pointGroup;

            $rangeCurrent = null;
            $directs = count($this->networkTreeService->directUserCodes($user->uuid));

            foreach ($ranges->sortByDesc('points') as $range) {
                if ($range->points <= $totalPoints && $range->childs <= $directs) {
                    $rangeCurrent    = $range;
                    break;
                }
            }

            $personalBonus = 0.0;
            $sponsorship = (float) $commissions['patrocinio'];
            // Columna heredada: se conserva sin mezclar pagos de residual e infinito.
            $sponsorshipCollected = 0.0;
            $residual = (float) $commissions['residual'];
            $residualProducto = (float) $commissions['residualProducto'];
            $residualServicio = (float) $commissions['residualServicio'];
            $infinity = (float) $commissions['infinito'];
            $payable = round($personalBonus + $sponsorship + $residual + $infinity, 2);
            $planPoints = (float) ($latestPackagePayment?->paymentOrder?->pack?->points
                ?? $latestPackagePayment?->pack?->points
                ?? 0);
            $personalPurchasePoints = (float) $paymentProductOrderPoints
                ->where('user_id', $user->id)->sum('points');
            $isCurrentPeriod = $from->format('Y-m') === now()->format('Y-m');
            $isActiveInPeriod = app(ActivationService::class)
                ->isActiveForPeriod($user, $from, $to, !$isCurrentPeriod);
            $status = !$latestPackagePayment ? 'Sin plan'
                : ($isActiveInPeriod ? 'Activo' : 'Inactivo');

            $global['personal'] += $personalBonus;
            $global['patrocinio'] += $sponsorship;
            $global['cobrado'] += $sponsorshipCollected;
            $global['residual'] += $residual;
            $global['residual_producto'] += $residualProducto;
            $global['residual_servicio'] += $residualServicio;
            $global['infinito'] += $infinity;
            $global['total'] += $payable;
            $global['pending'] += $payout['pending'];
            $global['paid'] += $payout['paid'];
            $global['available'] += $payout['available'];

            $excelBody[] = [
                $user->name,
                $user->uuid,
                $status,
                $user->package_name,
                $personalBonus,
                $sponsorship,
                $sponsorshipCollected,
                $residualProducto,
                $residualServicio,
                $residual,
                $payable,
                $planPoints,
                $personalPurchasePoints,
                $infinity,
                $payable,
                $rangeCurrent?->title ?? "Sin rango",
                $payout['generated'],
                $payout['pending'],
                $payout['paid'],
                $payout['available'],
                $payout['last_paid_at'],
            ];
        }

        $excelBody[] = [
            'TOTAL GENERAL EMPRESA', '', '', '',
            round($global['personal'], 2), round($global['patrocinio'], 2),
            round($global['cobrado'], 2), round($global['residual_producto'], 2),
            round($global['residual_servicio'], 2), round($global['residual'], 2),
            round($global['total'], 2), '', '', round($global['infinito'], 2),
            round($global['total'], 2), '', round($global['total'], 2),
            round($global['pending'], 2), round($global['paid'], 2),
            round($global['available'], 2), '',
        ];

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
                "residualProducto" => $_pointTemp['points']->residualProducto ?? 0,
                "residualServicio" => $_pointTemp['points']->residualServicio ?? 0,
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
            [$visibleFrom] = app(ActivationService::class)->visiblePeriod(Carbon::now('America/Lima'));

            $year  = $visibleFrom->format('Y');
            $month = $visibleFrom->format('m');

            if ($request->has('month') && !empty($request->query('month'))) $month = $request->query('month');
            if ($request->has('year') && !empty($request->query('year'))) $year    = $request->query('year');

            $paymentOrders = PaymentLog::with(['paymentOrder'])
                ->whereRaw('MONTH(created_at) = ?', [$month])
                ->whereRaw('YEAR(created_at) = ?', [$year])
                ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])
                ->get();
            $paymentProductOrders = PaymentProductOrder::whereRaw('MONTH(created_at) = ?', [$month])
                ->whereRaw('YEAR(created_at) = ?', [$year])
                ->whereIn('state', [
                    PaymentProductOrder::PAGADO,
                    PaymentProductOrder::ENVIADO,
                    PaymentProductOrder::TERMINADO,
                ])->get();

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
            }])->select('id', 'file', 'user_id', 'state', 'created_at', DB::raw('0 as plan'), 'pack_id', 'phone', 'points', 'discount', DB::raw("'' as payment_order_id"))->whereIn("state", [
                PaymentProductOrder::PAGADO,
                PaymentProductOrder::ENVIADO,
                PaymentProductOrder::PREORDER,
                PaymentProductOrder::TERMINADO,
            ]);
            
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
            $from  = $fechaActual->copy()->startOfMonth();
            $to    = $fechaActual->copy()->endOfMonth();
            $ledgerService = app(FinancialLedgerService::class);

            $subject = "Resumen General de puntos y bonos del último mes - Imperio Global";

            foreach ($userList as $key => $user) {
                if ($user->is_admin) {
                    $jsonBody = [];
                    foreach ($userList as $keyTemp => $_user) {
                        $_user          = (object) $_user;
                        $_user->payment = PaymentLog::with(['paymentOrder.pack'])->where("user_id",  $_user->id)
                            ->where(function ($query) {
                                $query->where('state', PaymentLog::PAGADO)
                                    ->orWhere('state', PaymentLog::TERMINADO);
                            })
                            ->orderBy('created_at', 'desc')
                            ->first();
                        $productPayment = PaymentProductOrder::with('pack')
                            ->where('user_id', $_user->id)
                            ->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO])
                            ->latest('created_at')->first();
                        $latestPackagePayment = collect([$_user->payment, $productPayment])
                            ->filter()->sortByDesc('created_at')->first();

                        $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $_user->id)->where("state", true)->get();

                        $calculator      = $this->pointCalculator->points($_user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
                        $calculatorPoint = $this->pointCalculator->pointsTotal($_user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
                        $commissions     = $ledgerService->summary($from, $to, $_user->uuid);

                        array_push($jsonBody, (object) [
                            "fullname"           => $_user->name,
                            "email"              => $_user->email,
                            "uuid"               => $_user->uuid,
                            "pack"               => $_user->package_name,
                            "status"             => $_user->active ? "Activo" : "Inactivo",
                            "totalPoint"         => $calculatorPoint,
                            "planPoints"         => (float) ($latestPackagePayment?->paymentOrder?->pack?->points
                                ?? $latestPackagePayment?->pack?->points ?? 0),
                            "personalPurchasePoints" => (float) $paymentProductOrderPoints->sum('points'),
                            "range"              => $_user->range == null ? "Sin Rango" : $_user->range->range->title,
                            "points"             => (object) [
                                "patrocinio"     => $commissions['patrocinio'],
                                "patrocinioCobrado" => 0,
                                "residual"       => $commissions['residual'],
                                "residualProducto" => $commissions['residualProducto'],
                                "residualServicio" => $commissions['residualServicio'],
                                "compra"         => $calculator->compra,
                                "pointGroup"     => $calculator->pointGroup,
                                "personal"       => $calculator->personal,
                                "infinito"       => $commissions['infinito'],
                                "pointAfiliado"  => $calculator->pointAfiliado,
                                "personalGlobal" => $calculator->personalGlobal
                            ],
                        ]);
                    }

                    $excelBody = [];
                    $companyTotals = [
                        'personal' => 0,
                        'patrocinio' => 0,
                        'patrocinio_cobrado' => 0,
                        'residual' => 0,
                        'residual_producto' => 0,
                        'residual_servicio' => 0,
                        'infinito' => 0,
                        'gran_total' => 0,
                    ];
                    foreach ($jsonBody as $key => $json) {
                        // Los puntos personales no son dinero pagable. El
                        // total usa exactamente la misma formula del ledger.
                        $payableTotal = (($json->points?->patrocinio ?? 0)
                            + ($json->points?->residual ?? 0)
                            + ($json->points?->infinito ?? 0));

                        array_push($excelBody, [
                            $json->fullname,
                            $json->uuid,
                            $json->status,
                            $json->pack,
                            0,
                            $json->points?->patrocinio ?? 0,
                            $json->points?->patrocinioCobrado ?? 0,
                            $json->points?->residualProducto ?? 0,
                            $json->points?->residualServicio ?? 0,
                            $json->points?->residual ?? 0,
                            $payableTotal,
                            $json->planPoints,
                            $json->personalPurchasePoints,
                            $json->points->infinito ?? 0,
                            $payableTotal,
                            $json->range
                        ]);

                        // Se conserva la columna por compatibilidad, pero no
                        // se mezclan puntos con comisiones monetarias.
                        $companyTotals['personal'] += 0;
                        $companyTotals['patrocinio'] += $json->points?->patrocinio ?? 0;
                        $companyTotals['patrocinio_cobrado'] += $json->points?->patrocinioCobrado ?? 0;
                        $companyTotals['residual'] += $json->points?->residual ?? 0;
                        $companyTotals['residual_producto'] += $json->points?->residualProducto ?? 0;
                        $companyTotals['residual_servicio'] += $json->points?->residualServicio ?? 0;
                        $companyTotals['infinito'] += $json->points?->infinito ?? 0;
                        $companyTotals['gran_total'] += $payableTotal;
                    }

                    $excelBody[] = [
                        'TOTAL GENERAL EMPRESA', '', '', '',
                        $companyTotals['personal'],
                        $companyTotals['patrocinio'],
                        $companyTotals['patrocinio_cobrado'],
                        $companyTotals['residual_producto'],
                        $companyTotals['residual_servicio'],
                        $companyTotals['residual'],
                        $companyTotals['gran_total'],
                        0, 0,
                        $companyTotals['infinito'],
                        $companyTotals['gran_total'],
                        '',
                    ];

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
                    $productPayment = PaymentProductOrder::with('pack')
                        ->where('user_id', $user->id)
                        ->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO])
                        ->latest('created_at')->first();
                    $latestPackagePayment = collect([$user->payment, $productPayment])
                        ->filter()->sortByDesc('created_at')->first();

                    if ($user->payment == null) continue;

                    $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $user->id)->where("state", true)->get();

                    $calculator           = $this->pointCalculator->points($user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
                    $calculatorTotalPoint = $this->pointCalculator->pointsTotal($user->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
                    $commissions          = $ledgerService->summary($from, $to, $user->uuid);

                    $jsonBody = [
                        "email"              => $user->email,
                        "range"              => $user->range == null ? "Sin Rango" : $user->range->range->title,
                        "pack"               => $user->package_name,
                        "status"             => $user->active ? "Activo" : "Inactivo",
                        "planPoints"         => (float) ($latestPackagePayment?->paymentOrder?->pack?->points
                            ?? $latestPackagePayment?->pack?->points ?? 0),
                        "personalPurchasePoints" => (float) $paymentProductOrderPoints->sum('points'),
                        "points"             => (object) [
                            "patrocinio"     => $commissions['patrocinio'],
                            "patrocinioCobrado" => 0,
                            "residual"       => $commissions['residual'],
                            "residualProducto" => $commissions['residualProducto'],
                            "residualServicio" => $commissions['residualServicio'],
                            "compra"         => $calculator->compra,
                            "pointGroup"     => $calculator->pointGroup,
                            "personal"       => $calculator->personal,
                            "infinito"       => $commissions['infinito'],
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
                ->whereBetween('created_at', [$from, $to])
                ->update(["state" => PaymentLog::TERMINADO]);

            PaymentOrderPoint::where('state', true)
                ->whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
                ->whereBetween('created_at', [$from, $to])
                ->update(["state" => false]);
            PaymentProductOrder::whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO])
                ->whereBetween('created_at', [$from, $to])
                ->update(["state" => PaymentProductOrder::TERMINADO]);
            PaymentProductOrderPoint::where("state", true)
                ->whereBetween('created_at', [$from, $to])
                ->update(["state" => false]);
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
            'products.*.quantity' => 'required|integer|min:1',
            'category'            => 'sometimes|in:product,service',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $user_id = Auth::id();
            DB::beginTransaction();
            $userModel = User::with(['file'])->find($user_id);

            if (!$userModel->is_admin) {
                DB::rollBack();
                return $this->sendError("No tiene permisos ese usuario");
            }

            $dataBody    = (object) $request->all();
            $category = $request->input('category');
            if ($category === null) {
                $requestedProductIds = collect($request->input('products', []))->pluck('product')->unique();
                $categories = Product::whereIn('id', $requestedProductIds)
                    ->pluck('reactivation_category')->filter()->unique();
                if ($categories->count() !== 1) {
                    DB::rollBack();
                    return $this->sendError('Los productos deben pertenecer a una sola categoria de reactivacion.', [], 422);
                }
                $category = $categories->first();
            }
            $category = strtolower((string) $category);
            $userUpdated = User::where("uuid", $dataBody->userCode)->first();

            if ($userUpdated == null) {
                DB::rollBack();
                return $this->sendError("No se existe el usuario seleccionado");
            }
            if (count($dataBody->products) == 0) {
                DB::rollBack();
                return $this->sendError("No se encuentra productos");
            }
            if (app(ActivationService::class)->isActiveForCategory($userUpdated, $category)) {
                DB::rollBack();
                return $this->sendError('El usuario ya se encuentra activo.', [], 422);
            }
            $eligibility = $this->reactivationEligibility($userUpdated, $category);
            if (!$eligibility['eligible']) {
                DB::rollBack();
                return $this->sendError($eligibility['message'], [
                    'reason' => $eligibility['reason'],
                    'eligible_from' => $eligibility['eligible_from'],
                ], 422);
            }
            $this->reconcileManualReactivations($userUpdated);
            if (ManualReactivation::where('user_id', $userUpdated->id)
                ->where('category', $category)->where('period', now()->startOfMonth()->toDateString())
                ->where('state', ManualReactivation::ACTIVE)->exists()) {
                DB::rollBack();
                return $this->sendError('El usuario ya tiene una reactivacion manual activa.', [], 422);
            }

            $createdPaymentLogIds = [];
            $createdPaymentOrderIds = [];

            $pack = $this->reactivationPack($userUpdated, $category);
            if (!$pack) {
                DB::rollBack();
                return $this->sendError("El usuario no tiene un paquete de {$category} para calcular la reactivacion.", [], 422);
            }
            $paymentLog = $category === ReactivationRule::SERVICE
                ? $this->validCategoryPaymentLogs($userUpdated, $category)
                    ->with(['paymentOrder.pack'])->latest('created_at')->first()
                : $this->productPackPaymentLog($userUpdated, $pack->id);

            $productIds = [];
            foreach ($dataBody->products as $key => $product) {
                $product = (object) $product;
                array_push($productIds, $product->product);
            }

            $productList       = Product::whereIn('id', $productIds)->lockForUpdate()->get();
            if ($productList->count() !== count(array_unique($productIds))) {
                DB::rollBack();
                return $this->sendError('Uno o mas productos seleccionados no existen.', [], 422);
            }
            $eligibleProductIds = ProductPointPack::where('pack_id', $pack->id)
                ->whereIn('product_id', $productIds)->pluck('product_id')->all();
            $categoryProductIds = $productList->where('reactivation_category', $category)->pluck('id')->all();
            if (count(array_unique($eligibleProductIds)) !== count(array_unique($productIds))
                || count(array_unique($categoryProductIds)) !== count(array_unique($productIds))) {
                DB::rollBack();
                return $this->sendError('Uno o mas productos no pertenecen a la categoria de reactivacion seleccionada.', [], 422);
            }
            $productListCreate = [];
            $totalAmount       = 0;
            $totalPoints       = 0;
            $discount          = 0;

            $discount = floatval($pack->discount ?? 0);

            foreach ($productList as $key => $product) {
                $keyDetail     = array_search($product->id, array_column($dataBody->products, 'product'));
                $productDetail = (object) $dataBody->products[$keyDetail];
                if ((int) $product->stock < (int) $productDetail->quantity) {
                    DB::rollBack();
                    return $this->sendError("Stock insuficiente para {$product->title}.", [], 422);
                }
                $subtotal      = $product->price * $productDetail->quantity;

                if ($discount > 0) {
                    $subtotal = $subtotal * (100 - $discount) / 100;
                }
                $totalAmount += $subtotal;

                $pointsPerUnit = $this->effectiveProductPoints($product, $pack->id, $category);
                $totalPoints += $pointsPerUnit * $productDetail->quantity;
            }

            $reactivationRule = ReactivationRule::where('category', $category)->where('state', true)->first();
            if (!$reactivationRule) {
                DB::rollBack();
                return $this->sendError('No existe una regla activa para esta categoria de reactivacion.', [], 422);
            }
            $minimumPoints = (float) $reactivationRule->minimum_points;
            if ($totalPoints < $minimumPoints) {
                DB::rollBack();
                return $this->sendError("La seleccion debe alcanzar al menos {$minimumPoints} puntos.", [
                    'selected_points' => $totalPoints, 'minimum_points' => $minimumPoints,
                ], 422);
            }

            $reactivation = ManualReactivation::create([
                'user_id' => $userUpdated->id,
                'activated_by' => $userModel->id,
                'category' => $category,
                'period' => now()->startOfMonth()->toDateString(),
                'amount' => $totalAmount,
                'points' => $totalPoints,
                'minimum_points' => $minimumPoints,
                'state' => ManualReactivation::ACTIVE,
            ]);

            $paymentProductOrder = PaymentProductOrder::create([
                'currency' => 'PEN',
                'amount'   => $totalAmount,
                'discount' => $discount,
                'points'   => $totalPoints,
                'user_id'  => $userUpdated->id,
                'pack_id'  => $pack->id,
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

                $pointsPerUnit = $this->effectiveProductPoints($product, $pack->id, $category);
                $_points = $pointsPerUnit * $productDetail->quantity;

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
            foreach ($productList as $product) {
                $keyDetail = array_search($product->id, array_column($dataBody->products, 'product'));
                $quantity = (int) ((object) $dataBody->products[$keyDetail])->quantity;
                $product->decrement('stock', $quantity);
            }

            $productOrderPoint = PaymentProductOrderPoint::create([
                'payment_product_order_id' => $paymentProductOrder->id,
                'user_id'                  => $userUpdated->id,
                'points'                   => $totalPoints,
                'state'                    => true
            ]);

            $sponsorCode = $paymentLog?->paymentOrder?->sponsor_code
                ?? SponsorRelation::where('user_code', $userUpdated->uuid)->value('sponsor_code')
                ?? 'COMPANY';

            // Referencia unica del ciclo mensual. No representa una nueva compra
            // del pack y por eso su importe es cero; permite separar ganancias
            // residuales de meses y reactivaciones diferentes.
            $monthlyReferenceOrder = PaymentOrder::create([
                'currency' => 'PEN',
                'amount' => 0,
                'sponsor_code' => $sponsorCode,
                'pack_id' => $pack->id,
                'token' => 'REACTIVATION-' . $reactivation->id . '-' . uniqid(),
            ]);
            $orderId = $monthlyReferenceOrder->id;
            $createdPaymentOrderIds[] = $orderId;

            PaymentOrderPoint::create([
                'manual_reactivation_id' => $reactivation->id,
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
            
            $maxNetworkLevel = (int) \App\Models\RangeRule::where('state', true)->max('depth_to');
            while (!empty($currentSponsorCode) && $level <= $maxNetworkLevel) {
                $sponsorUser                               = User::where('uuid', $currentSponsorCode)->first();
                if (!$sponsorUser) break;

                $relation = PaymentOrderPoint::where('user_code', $currentSponsorCode)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->first();
                $superiorSponsorCode = $relation ? $relation->sponsor_code : '';

                PaymentOrderPoint::create([
                    'manual_reactivation_id' => $reactivation->id,
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

            $residualSummary = $this->commissionService->confirmPointAfiliado(
                $userUpdated,
                $totalPoints,
                $reactivation->id,
                $orderId,
                $category
            );

            $createdPaymentOrderPointIds = PaymentOrderPoint::where('manual_reactivation_id', $reactivation->id)
                ->pluck('id')->all();
            $reactivation->update([
                'payment_product_order_id' => $paymentProductOrder->id,
                'payment_order_point_ids' => $createdPaymentOrderPointIds,
                'payment_product_order_point_ids' => [$productOrderPoint->id],
                'payment_log_ids' => $createdPaymentLogIds,
                'payment_order_ids' => $createdPaymentOrderIds,
            ]);

            ActivationService::clearCache();
            DB::commit();
            return $this->sendResponse([
                'reactivation_id' => $reactivation->id,
                'user_code' => $userUpdated->uuid,
                'active' => true,
                'amount' => $reactivation->amount,
                'points' => $reactivation->points,
                'category' => $category,
                'residual' => $residualSummary,
            ], 'Usuario reactivado en la red exitosamente.');
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

    public function resetAll(Request $request)
    {
        try {
            PaymentLog::with(['paymentOrder'])->where('state', PaymentLog::PAGADO)
                ->update(["state" => PaymentLog::TERMINADO]);

            PaymentOrderPoint::where('state', true)
                ->whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
                ->update(["state" => false]);
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
        if (!Auth::user()?->is_admin) return $this->sendError('No tiene permisos ese usuario', [], 403);
        $validator = Validator::make($request->all(), [
            'userCode' => 'required',
            'category' => 'sometimes|in:product,service',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();

            $dataBody    = (object) $request->all();
            $userCurrent = User::where("uuid", $dataBody->userCode)->first();
            if (!$userCurrent) {
                DB::rollBack();
                return $this->sendError('No existe el usuario seleccionado', [], 404);
            }
            $category = $request->input('category');
            if ($category === null) {
                $category = ManualReactivation::where('user_id', $userCurrent?->id)
                    ->where('period', now()->startOfMonth()->toDateString())
                    ->where('state', ManualReactivation::ACTIVE)
                    ->orderByRaw("CASE category WHEN 'product' THEN 1 ELSE 2 END")
                    ->value('category') ?? ReactivationRule::PRODUCT;
            }
            $category = strtolower((string) $category);
            $reactivation = ManualReactivation::where('user_id', $userCurrent->id)
                ->where('category', $category)
                ->where('period', now()->startOfMonth()->toDateString())
                ->where('state', ManualReactivation::ACTIVE)->latest('id')->lockForUpdate()->first();
            if (!$reactivation) {
                DB::rollBack();
                return $this->sendError(
                    'No existe una activacion mensual registrada que pueda desactivarse. No se modificaron el usuario, su paquete ni sus puntos historicos.',
                    [],
                    422
                );
            }

            PaymentOrderPoint::whereIn('id', $reactivation->payment_order_point_ids ?? [])
                ->update(['state' => false, 'point' => 0, 'type' => PaymentOrderPoint::RESET]);
            PaymentProductOrderPoint::whereIn('id', $reactivation->payment_product_order_point_ids ?? [])
                ->update(['state' => false, 'points' => 0]);
            PaymentProductOrder::whereKey($reactivation->payment_product_order_id)
                ->update(['state' => PaymentProductOrder::ANULADO, 'amount' => 0, 'discount' => 0, 'points' => 0]);
            PaymentProductOrderDetail::where('payment_product_order_id', $reactivation->payment_product_order_id)
                ->get(['product_id', 'quantity'])->each(function (PaymentProductOrderDetail $detail) {
                    Product::whereKey($detail->product_id)->increment('stock', (int) $detail->quantity);
                });
            PaymentProductOrderDetail::where('payment_product_order_id', $reactivation->payment_product_order_id)
                ->update(['price' => 0, 'subtotal' => 0]);
            PaymentLog::whereIn('id', $reactivation->payment_log_ids ?? [])->update(['state' => PaymentLog::RESET]);
            PaymentOrder::whereIn('id', $reactivation->payment_order_ids ?? [])->update(['amount' => 0]);
            $reactivation->update([
                'state' => ManualReactivation::DEACTIVATED,
                'deactivated_at' => now(),
                'deactivated_by' => Auth::id(),
            ]);

            ActivationService::clearCache();
            DB::commit();
            $userCurrent->refresh();
            return $this->sendResponse([
                'reactivation_id' => $reactivation->id,
                'user_code' => $userCurrent->uuid,
                'active' => $userCurrent->active,
                'category' => $category,
                'points' => 0,
            ], 'Puntos de activacion mensual desactivados. El usuario, su red, su paquete y sus puntos historicos no fueron eliminados.');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function deactivateInitialActivation(Request $request)
    {
        if (!Auth::user()?->is_admin) return $this->sendError('No tiene permisos ese usuario', [], 403);

        $validator = Validator::make($request->all(), [
            'userCode' => 'required|exists:users,uuid',
            'category' => 'required|in:product,service',
        ]);
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            $user = User::where('uuid', $request->input('userCode'))->lockForUpdate()->firstOrFail();
            if ($user->is_admin || strcasecmp((string) $user->uuid, 'DOSB') === 0) {
                DB::rollBack();
                return $this->sendError('La cuenta corporativa o administrativa no puede desactivarse.', [], 422);
            }

            $result = app(InitialActivationDeactivationService::class)
                ->deactivate($user, $request->input('category'));

            $user->refresh();
            $isActive = $user->active;
            DB::commit();

            return $this->sendResponse($result + [
                'user_code' => $user->uuid,
                'category' => $request->input('category'),
                'active' => $isActive,
                'points' => 0,
            ], 'Activacion inicial desactivada. Se conservaron el usuario, la red, el paquete, el descuento y el historial.');
        } catch (\DomainException $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 422);
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function commissionSummary(Request $request)
    {
        if (!Auth::user()?->is_admin) return $this->sendError('No tiene permisos ese usuario', [], 403);
        $validator = Validator::make($request->query(), [
            'month' => 'nullable|integer|between:1,12',
            'year' => 'nullable|integer|min:2000',
            'user_code' => 'nullable|exists:users,uuid',
        ]);
        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        // Sin filtros explícitos, Finanzas sigue el ciclo visible general:
        // durante la gracia conserva el cierre del mes anterior. Una consulta
        // histórica con month/year continúa respetando el período solicitado.
        [$visibleFrom] = app(ActivationService::class)->visiblePeriod(Carbon::now('America/Lima'));
        $month = (int) $request->query('month', $visibleFrom->month);
        $year = (int) $request->query('year', $visibleFrom->year);
        $from = Carbon::create($year, $month, 1)->startOfMonth();
        $to = $from->copy()->endOfMonth();
        $ledger = app(FinancialLedgerService::class);
        $summary = $ledger->summary($from, $to, $request->query('user_code'));
        $summary['period'] = ['month' => $month, 'year' => $year];
        $summary['formula'] = 'bono_total = patrocinio + residual + infinito';

        return $this->sendResponse($summary, 'Resumen financiero de comisiones');
    }

    public function reactivationStatus(string $userCode)
    {
        if (!Auth::user()?->is_admin) return $this->sendError('No tiene permisos ese usuario', [], 403);
        $user = User::where('uuid', $userCode)->first();
        if (!$user) return $this->sendError('No existe el usuario seleccionado', [], 404);
        $reactivations = ManualReactivation::where('user_id', $user->id)
            ->where('period', now()->startOfMonth()->toDateString())
            ->where('state', ManualReactivation::ACTIVE)->get()->keyBy('category');
        $categories = collect([ReactivationRule::PRODUCT, ReactivationRule::SERVICE])->mapWithKeys(
            function (string $category) use ($user, $reactivations) {
                $reactivation = $reactivations->get($category);
                $eligibility = $this->reactivationEligibility($user, $category);
                $isActive = app(ActivationService::class)->isActiveForCategory($user, $category);
                $canDeactivateInitial = app(InitialActivationDeactivationService::class)
                    ->canDeactivate($user, $category);
                $canReactivate = !$isActive && !$reactivation && $eligibility['eligible'];
                return [$category => [
                    'is_active' => $isActive,
                    // Campos planos para consumidores existentes.
                    'eligible' => $eligibility['eligible'],
                    'reason' => $eligibility['reason'],
                    'available' => $canReactivate,
                    'can_reactivate' => $canReactivate,
                    'manual_reactivation_active' => $reactivation !== null,
                    'reactivation' => $reactivation,
                    'reactivation_eligibility' => $eligibility,
                    'actions' => [
                        'can_reactivate' => $canReactivate,
                        'can_deactivate' => $reactivation !== null,
                        'can_deactivate_initial_activation' => $canDeactivateInitial,
                    ],
                ]];
            }
        );
        // Compatibilidad con el frontend existente: conserva el contrato plano
        // y, adicionalmente, entrega el detalle nuevo de ambas categorias.
        $selectedCategory = $categories->search(fn (array $item) => $item['actions']['can_deactivate']);
        if ($selectedCategory === false) {
            $selectedCategory = $categories->search(fn (array $item) => $item['actions']['can_reactivate']);
        }
        if ($selectedCategory === false) $selectedCategory = ReactivationRule::PRODUCT;
        $selected = $categories->get($selectedCategory, $categories->get(ReactivationRule::PRODUCT));
        return $this->sendResponse([
            'user_code' => $user->uuid,
            'is_active' => $user->active,
            'selected_category' => $selectedCategory,
            'manual_reactivation_active' => $selected['manual_reactivation_active'],
            'legacy_reactivation' => false,
            'reactivation' => $selected['reactivation'],
            'reactivation_eligibility' => $selected['reactivation_eligibility'],
            'actions' => $selected['actions'] + [
                'reactivate_label' => 'Reactivar puntos',
                'deactivate_label' => 'Desactivar puntos',
                'deactivate_initial_activation_label' => 'Desactivar activacion inicial',
            ],
            'categories' => $categories,
        ], 'Estado de reactivacion');
    }

    public function reactivationProducts(Request $request, string $userCode)
    {
        if (!Auth::user()?->is_admin) return $this->sendError('No tiene permisos ese usuario', [], 403);
        $user = User::where('uuid', $userCode)->first();
        if (!$user) return $this->sendError('No existe el usuario seleccionado', [], 404);
        $validator = Validator::make($request->query(), ['category' => 'sometimes|in:product,service']);
        if ($validator->fails()) return $this->sendError('Categoria no valida.', $validator->errors(), 422);
        $category = $request->query('category');
        if ($category === null) {
            $category = collect([ReactivationRule::PRODUCT, ReactivationRule::SERVICE])->first(function (string $candidate) use ($user) {
                return !app(ActivationService::class)->isActiveForCategory($user, $candidate)
                    && $this->reactivationEligibility($user, $candidate)['eligible']
                    && $this->reactivationPack($user, $candidate) !== null;
            }, ReactivationRule::PRODUCT);
        }
        $category = strtolower((string) $category);
        if (app(ActivationService::class)->isActiveForCategory($user, $category)) {
            return $this->sendError('El usuario ya se encuentra activo en esta categoria.', [], 422);
        }
        $eligibility = $this->reactivationEligibility($user, $category);
        if (!$eligibility['eligible']) {
            return $this->sendError($eligibility['message'], [
                'reason' => $eligibility['reason'],
                'eligible_from' => $eligibility['eligible_from'],
            ], 422);
        }
        $pack = $this->reactivationPack($user, $category);
        if (!$pack) {
            return $this->sendError('El usuario no tiene un paquete de productos asociado.', [], 422);
        }
        $packId = $pack?->id;
        $discount = (float) ($pack->discount ?? 0);
        $eligibleIds = ProductPointPack::where('pack_id', $packId)->pluck('product_id');
        $products = Product::with('file_image')->where('state', true)
            ->where('reactivation_category', $category)
            ->whereIn('id', $eligibleIds)->orderBy('title')->get()
            ->map(function (Product $product) use ($packId, $category, $discount) {
                $product->effective_points = $this->effectiveProductPoints($product, $packId, $category);
                $product->points = $product->effective_points;
                $publicPrice = (float) $product->price;
                $discountAmount = round($publicPrice * $discount / 100, 2);
                $product->public_price = $publicPrice;
                $product->discount_percentage = $discount;
                $product->discount_amount = $discountAmount;
                $product->final_price = round($publicPrice - $discountAmount, 2);
                return $product;
            });
        $rule = ReactivationRule::where('category', $category)->where('state', true)->first();
        if (!$rule) return $this->sendError('No existe una regla activa para esta categoria de reactivacion.', [], 422);
        $minimumPoints = (float) $rule->minimum_points;
        return $this->sendResponse([
            'pack_id' => $packId,
            'pack' => $pack ? [
                'id' => $pack->id,
                'title' => $pack->title,
                'category' => $pack->category,
                'discount' => $discount,
            ] : null,
            'minimum_points' => $minimumPoints,
            'category' => $category,
            'products' => $products,
        ], 'Productos para reactivacion');
    }

    private function effectiveProductPoints(Product $product, ?string $packId, string $category): float
    {
        $specific = $packId ? ProductPointPack::where('product_id', $product->id)
            ->where('pack_id', $packId)
            ->where('point', '>', 0)
            ->value('point') : null;

        // El respaldo solo puede salir de packs de la misma categoria. De este
        // modo un servicio nunca hereda los puntos de un pack de productos.
        $packCategory = $category === ReactivationRule::SERVICE ? 'Servicio' : 'Producto';
        $configured = ProductPointPack::where('product_id', $product->id)
            ->where('point', '>', 0)
            ->whereHas('pack', fn ($query) => $query->where('category', $packCategory))
            ->max('point');

        // En servicios, la regla activa define dinamicamente los puntos de la
        // reactivacion cuando no existe una asignacion positiva producto-pack.
        $fallback = $category === ReactivationRule::SERVICE
            ? ReactivationRule::where('category', $category)->where('state', true)->value('minimum_points')
            : $product->points;

        return (float) ($specific ?? $configured ?? $fallback ?? 0);
    }

    private function reactivationEligibility(User $user, string $category): array
    {
        if ($user->is_admin) {
            return ['eligible' => false, 'reason' => 'administrator', 'eligible_from' => null,
                'message' => 'Los administradores no requieren reactivacion mensual.'];
        }

        $firstProductPurchase = $this->validCategoryProductOrders($user, $category)->min('created_at');
        $firstPackPurchase = $this->validCategoryPaymentLogs($user, $category)->min('created_at');

        $firstPurchase = collect([$firstProductPurchase, $firstPackPurchase])->filter()->min();
        if (!$firstPurchase) {
            return ['eligible' => false, 'reason' => 'no_package_purchase', 'eligible_from' => null,
                'message' => 'El usuario aun no tiene una compra de paquete previa.'];
        }

        if (app(InitialActivationDeactivationService::class)->wasManuallyDeactivated($user, $category)) {
            return ['eligible' => true, 'reason' => 'initial_activation_manually_deactivated',
                'eligible_from' => now()->toIso8601String(),
                'message' => 'La activacion inicial fue desactivada manualmente y puede reactivarse en este periodo.'];
        }

        $eligibleFrom = Carbon::parse($firstPurchase)->startOfMonth()->addMonth();
        if (now()->lt($eligibleFrom)) {
            return ['eligible' => false, 'reason' => 'initial_package_period_active',
                'eligible_from' => $eligibleFrom->toIso8601String(),
                'message' => 'El paquete inicial todavia cubre el mes actual. La reactivacion estara disponible el siguiente mes.'];
        }

        return ['eligible' => true, 'reason' => 'monthly_activation_due',
            'eligible_from' => $eligibleFrom->toIso8601String(),
            'message' => 'El periodo inicial termino y el usuario puede reactivar sus puntos mensuales.'];
    }

    private function reactivationPack(User $user, string $category): ?Pack
    {
        // Usa exactamente los mismos criterios que reactivationEligibility.
        $productOrder = $this->validCategoryProductOrders($user, $category)
            ->with('pack')->latest('created_at')->first();

        if ($productOrder?->pack) return $productOrder->pack;

        return $this->validCategoryPaymentLogs($user, $category)
            ->with(['paymentOrder.pack'])->latest('created_at')->first()?->paymentOrder?->pack;
    }

    private function validCategoryProductOrders(User $user, string $category)
    {
        $reactivationOrderIds = ManualReactivation::where('user_id', $user->id)
            ->whereNotNull('payment_product_order_id')->pluck('payment_product_order_id');
        $packCategory = $this->storedPackCategory($category);

        return PaymentProductOrder::where('user_id', $user->id)
            ->whereNotIn('id', $reactivationOrderIds)
            ->whereIn('state', [
                PaymentProductOrder::PAGADO,
                PaymentProductOrder::ENVIADO,
                PaymentProductOrder::TERMINADO,
            ])
            ->whereHas('pack', fn ($query) => $query
                ->whereRaw('LOWER(TRIM(category)) = ?', [$packCategory]));
    }

    private function validCategoryPaymentLogs(User $user, string $category)
    {
        $packCategory = $this->storedPackCategory($category);
        $reactivationPaymentLogIds = ManualReactivation::where('user_id', $user->id)
            ->get(['payment_log_ids'])
            ->pluck('payment_log_ids')
            ->filter()
            ->flatten()
            ->filter()
            ->unique()
            ->values();

        return PaymentLog::where('user_id', $user->id)
            // RESET conserva una compra historica que fue cerrada por procesos
            // administrativos. Sirve como antecedente, pero se excluyen los
            // logs generados por reactivaciones para que no se auto-habiliten.
            ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO, PaymentLog::RESET])
            ->when($reactivationPaymentLogIds->isNotEmpty(), fn ($query) =>
                $query->whereNotIn('id', $reactivationPaymentLogIds))
            ->whereHas('paymentOrder.pack', fn ($query) => $query
                ->whereRaw('LOWER(TRIM(category)) = ?', [$packCategory]));
    }

    private function storedPackCategory(string $category): string
    {
        return $category === ReactivationRule::SERVICE ? 'servicio' : 'producto';
    }

    private function productPackPaymentLog(User $user, ?string $packId = null): ?PaymentLog
    {
        return PaymentLog::with(['paymentOrder.pack'])->where('user_id', $user->id)
            ->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO])
            ->whereHas('paymentOrder.pack', fn ($query) => $query->whereRaw('UPPER(category) = ?', ['PRODUCTO']))
            ->when($packId, fn ($query) => $query->whereHas('paymentOrder', fn ($order) => $order->where('pack_id', $packId)))
            ->latest('created_at')->first();
    }

    private function reconcileManualReactivations(User $user): void
    {
        ManualReactivation::where('user_id', $user->id)->where('state', ManualReactivation::ACTIVE)
            ->get()->each(function (ManualReactivation $reactivation) {
                $orderState = PaymentProductOrder::whereKey($reactivation->payment_product_order_id)->value('state');
                if (!in_array((int) $orderState, [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO], true)) {
                    $reactivation->update([
                        'state' => ManualReactivation::EXPIRED,
                        'deactivated_at' => now(),
                    ]);
                }
            });
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
            'userCode'    => 'required|exists:users,uuid',
            'sponsorCode' => 'required|exists:users,uuid|different:userCode',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $dataBody    = (object) $request->all();
            $userCurrent = User::where("uuid", 'like', $dataBody->userCode)->first();

            $descendants = $this->networkTreeService->getAllNetworkUsers($userCurrent->uuid);
            if (collect($descendants)->contains(fn ($code) => strcasecmp($code, $dataBody->sponsorCode) === 0)) {
                return $this->sendError('El patrocinador seleccionado pertenece a la red descendente del usuario.');
            }

            // El servicio incluye al propio usuario en el resultado. Cualquier
            // elemento adicional representa una rama descendente, sin importar
            // si proviene de la tabla actual o de las relaciones heredadas.
            if (count($descendants) > 1) {
                return $this->sendError('Este usuario tiene invitados debajo de él.');
            }

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
            SponsorRelation::updateOrCreate(
                ['user_code' => $userCurrent->uuid],
                ['sponsor_code' => $dataBody->sponsorCode, 'source' => 'manual', 'state' => true]
            );

            DB::commit();
            return $this->sendResponse(1, '');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function changeSponsorWithNetwork(Request $request)
    {
        if (!Auth::user()?->is_admin) {
            return $this->sendError('No tiene permisos para mover una red.', [], 403);
        }

        $validator = Validator::make($request->all(), [
            'userCode' => 'required|exists:users,uuid',
            'sponsorCode' => 'required|exists:users,uuid|different:userCode',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }

        $userCode = (string) $request->input('userCode');
        $sponsorCode = (string) $request->input('sponsorCode');
        if (strcasecmp($userCode, 'DOSB') === 0) {
            return $this->sendError('La raiz corporativa no puede moverse.', [], 422);
        }
        if (strcasecmp($sponsorCode, 'DOSB') === 0
            && strcasecmp($userCode, 'WAdz') !== 0) {
            return $this->sendError('DOSB solo puede tener a WAdz como usuario directo.', [], 422);
        }

        $descendants = $this->networkTreeService->getAllNetworkUsers($userCode);
        if (collect($descendants)->contains(fn ($code) => strcasecmp((string) $code, $sponsorCode) === 0)) {
            return $this->sendError('El patrocinador seleccionado pertenece a la rama que se intenta mover.', [], 422);
        }

        try {
            $result = DB::transaction(function () use ($userCode, $sponsorCode, $descendants) {
                $user = User::where('uuid', $userCode)->lockForUpdate()->firstOrFail();
                $sponsor = User::where('uuid', $sponsorCode)->lockForUpdate()->firstOrFail();
                $oldSponsor = $this->networkTreeService->sponsorCode($user->uuid);

                SponsorRelation::updateOrCreate(
                    ['user_code' => $user->uuid],
                    ['sponsor_code' => $sponsor->uuid, 'source' => 'manual_branch_move', 'state' => true]
                );

                // Solo los asientos personales activos representan el enlace
                // estructural actual. Las comisiones y movimientos historicos
                // de los descendientes permanecen intactos.
                PaymentOrderPoint::where('user_code', $user->uuid)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->where('state', true)
                    ->update(['sponsor_code' => $sponsor->uuid]);

                return [
                    'user_code' => $user->uuid,
                    'old_sponsor_code' => $oldSponsor,
                    'new_sponsor_code' => $sponsor->uuid,
                    'moved_descendants' => max(count($descendants) - 1, 0),
                    'moved_network' => true,
                ];
            });

            return $this->sendResponse($result, 'Usuario y red movidos correctamente.');
        } catch (Exception $e) {
            return $this->sendError('No se pudo mover la red: '.$e->getMessage(), [], 422);
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

            $directCodes = $this->networkTreeService->directUserCodes('DOSB');
            $directosLegacy = collect($directCodes)->map(fn ($code) => (object) ['guest_user_code' => $code]);
            $totalDirectos = count($directCodes);

            $now = Carbon::now('America/Lima');
            $activation = app(ActivationService::class);
            [$visibleFrom, $visibleTo] = $activation->visiblePeriod($now);
            $isGracePeriod = $activation->isMonthlyGracePeriod($now);
            $activos = 0;
            foreach ($directosLegacy as $guest) {
                $user = User::where('uuid', $guest->guest_user_code)->first();
                if ($user && $activation->isActiveForPeriod(
                    $user, $visibleFrom, $visibleTo, $isGracePeriod
                )) $activos++;
            }

            // Usando el servicio inyectado
            $networkCodes = $this->networkTreeService->getAllNetworkUsers('DOSB');
            $descendantCodes = array_values(array_filter($networkCodes, fn ($code) => strcasecmp($code, 'DOSB') !== 0));
            $totalRed = count($descendantCodes);
            $tree     = $this->networkTreeService->buildDescendantTree('DOSB', 0, 15);

            $historicalGroupVolume = DB::query()->fromSub(
                PaymentOrderPoint::select('payment_order_id', DB::raw('MAX(point) as point'))
                    ->whereIn('user_code', $descendantCodes)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->groupBy('payment_order_id'),
                'network_purchases'
            )->sum('point');
            $monthlyGroupVolume = DB::query()->fromSub(
                PaymentOrderPoint::select('payment_order_id', DB::raw('MAX(point) as point'))
                    ->whereIn('user_code', $descendantCodes)
                    ->where('type', PaymentOrderPoint::COMPRA)
                    ->when(!$isGracePeriod, fn ($query) => $query->where('state', true))
                    ->whereBetween('created_at', [$visibleFrom, $visibleTo])
                    ->groupBy('payment_order_id'),
                'monthly_network_purchases'
            )->sum('point');
            $totalPuntos = (float) $monthlyGroupVolume;
            $puntosPorInvitado = 0;

            $userModel->directos    = $totalDirectos;
            $userModel->activos     = $activos;
            $userModel->red_total   = $totalRed;
            $userModel->totalPoints = $totalPuntos;
            $userModel->points      = (object) [
                'patrocinio'         => 0,
                'residual'           => 0,
                'compra'             => (object) ['total_puntos' => 0],
                'pointGroup'         => (float) $totalPuntos,
                'pointGroupMonthly'  => (float) $monthlyGroupVolume,
                'pointGroupHistorical' => (float) $historicalGroupVolume,
                'personal'           => 0,
                'infinito'           => 0,
                'pointAfiliado'      => 0,
                'personalGlobal'     => 0,
                'patrocinioRequest'  => 0,
                'patrocinioServicio' => 0,
                'residualServicio'   => 0,
                'legacy_bonus'       => 0,
                'total_general'      => (float) $monthlyGroupVolume,
                'total_comisiones'   => 0
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
                    'volumen_grupal_mensual' => (float) $monthlyGroupVolume,
                    'volumen_grupal_historico' => (float) $historicalGroupVolume,
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
