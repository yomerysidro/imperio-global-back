<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Range;
use App\Models\ScheduleCron;
use App\Models\PaymentOrderPoint;
use App\Models\RangeUser;
use App\Models\PaymentProductOrderPoint;
use App\Services\Core\Calculator;
use App\Services\Core\FinancialLedgerService;
use App\Models\PaymentLog;
use App\Models\UserEmailTemp;
use App\Models\PaymentProductOrder;

use Exception;

use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use App\Exports\ReportExcelUsers;

class ResetUsersActive extends Command
{

    private $calculator;

    public function __construct()
    {
        parent::__construct();
        $this->calculator = new Calculator();
    }

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:reset-users-active';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restear usuarios';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //

        $scheduleCron = ScheduleCron::create(array(
            'signature' => "app:reset-users-active",
        ));
        try {

            setlocale(LC_TIME, 'es_ES.UTF-8'); // Para funciones de fecha nativas (no estrictamente necesario para Carbon)
            Carbon::setLocale('es');           // Esto es lo importante para Carbon

            DB::beginTransaction();

            $userList = User::with(['range.range'])->get();

            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder'])->where('state' , true)->get();

            $fechaActual = Carbon::now();

            $oneMonthAgo = $fechaActual->subMonth();

            // Obtener mes y año
            $mes = $oneMonthAgo->translatedFormat('F'); // o 'F' para nombre del mes
            $año = $oneMonthAgo->format('Y');
            $month = $oneMonthAgo->format('m');
            $from = $oneMonthAgo->copy()->startOfMonth();
            $to = $oneMonthAgo->copy()->endOfMonth();
            $ledger = app(FinancialLedgerService::class);

            $subject = "Resumen General de puntos y bonos del último mes - Imperio Global";

            foreach ($userList as $key => $user) {
                if( $user->is_admin ){
                    // ==== SOLO PARA EL ADMIN
                    $jsonBody = array();
                    foreach ($userList as $keyTemp => $_user){
                        $_user = (object) $_user;
                        $_user->payment = PaymentLog::with(['paymentOrder.pack' ])->where( "user_id" ,  $_user->id )
                        ->where( function ($query) {
                            $query->where('state' , PaymentLog::PAGADO)
                            ->orWhere('state' , PaymentLog::TERMINADO);
                        })
                        ->orderBy('created_at', 'desc')
                        ->first();
                        $productPayment = PaymentProductOrder::with('pack')
                            ->where('user_id', $_user->id)
                            ->whereIn('state', [PaymentProductOrder::PAGADO, PaymentProductOrder::ENVIADO, PaymentProductOrder::TERMINADO])
                            ->latest('created_at')->first();
                        $latestPackagePayment = collect([$_user->payment, $productPayment])
                            ->filter()->sortByDesc('created_at')->first();

                        $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id" , $_user->id)->where("state" , true)->get();

                        $calculator = $this->calculator->points($_user->uuid, $paymentOrderPoints,
                            $paymentProductOrderPoints, (int) $month, (int) $año);
                        $calculatorPoint = $this->calculator->pointsTotal($_user->uuid, $paymentOrderPoints,
                            $paymentProductOrderPoints, (int) $month, (int) $año);
                        $commissions = $ledger->summary($from, $to, $_user->uuid);
                        
                        array_push( $jsonBody , (object) array(
                            "fullname" => $_user->name,
                            "email" => $_user->email,
                            "uuid" => $_user->uuid,
                            "pack" => $_user->payment?->paymentOrder?->pack?->title ?? "Sin Plan",
                            "status" => $_user->payment == null ? "--" : ( $_user->payment->state == PaymentLog::PAGADO ? "Activo" : "Inactivo" ),
                            "totalPoint" => $calculatorPoint,
                            "planPoints" => (float) ($latestPackagePayment?->paymentOrder?->pack?->points
                                ?? $latestPackagePayment?->pack?->points ?? 0),
                            "personalPurchasePoints" => (float) $paymentProductOrderPoints->sum('points'),
                            "range" => $_user->range == null ? "Sin Rango" : $_user->range->range->title,
                            "points" => (object) array(
                                "patrocinio"    => $commissions['patrocinio'],
                                "residual"      => $commissions['residual'],
                                "compra"        => $calculator->compra,
                                "pointGroup"    => $calculator->pointGroup,
                                "personal"      => $calculator->personal,
                                "infinito"      => $commissions['infinito'],
                                "pointAfiliado" => $calculator->pointAfiliado,
                                "personalGlobal" => $calculator->personalGlobal
                            ),
                        ) );
                    }

                    

                    // crear archivo excel
                    $excelBody = array();
                    $globalPatrocinio = 0.0;
                    $globalResidual = 0.0;
                    $globalInfinito = 0.0;

                    foreach ($jsonBody as $key => $json) {
                        $payable = (float) ($json->points?->pointAfiliado ?? 0)
                            + (float) ($json->points?->patrocinio ?? 0)
                            + (float) ($json->points?->residual ?? 0)
                            + (float) ($json->points?->infinito ?? 0);
                        $globalPatrocinio += (float) ($json->points?->patrocinio ?? 0);
                        $globalResidual += (float) ($json->points?->residual ?? 0);
                        $globalInfinito += (float) ($json->points?->infinito ?? 0);
                        array_push(
                            $excelBody,
                            array(
                                $json->fullname,
                                $json->uuid,
                                $json->status,
                                $json->pack,
                                $json->points?->pointAfiliado ?? 0,
                                $json->points?->patrocinio ?? 0,
                                0,
                                $json->points?->residual ?? 0,
                                $payable,
                                $json->planPoints,
                                $json->personalPurchasePoints,
                                $json->points->infinito ?? 0,
                                $payable,
                                $json->range
                            )
                        );
                    }

                    $globalPayable = $globalPatrocinio + $globalResidual + $globalInfinito;
                    $excelBody[] = ['TOTAL GENERAL EMPRESA', '', '', '', 0,
                        round($globalPatrocinio, 2), 0, round($globalResidual, 2),
                        round($globalPayable, 2), '', '', round($globalInfinito, 2),
                        round($globalPayable, 2), ''];

                    // 1. Guardar Excel
                    $fecha = Carbon::now()->format('YmdHis');
                    $nameFile = "exports/reporte_usuarios_{$fecha}.xlsx";

                    Excel::store(new ReportExcelUsers($excelBody), $nameFile);

                    $userTemp = UserEmailTemp::create(array(
                        'userId' => $user->id,
                        'isAdmin' => $user->is_admin,
                        'status' => UserEmailTemp::PENDIENTE,
                        'email' => $user->email,
                        'subject' => $subject . " ". strtoupper($mes) ."-".$año ,
                        'month'=> $month,
                        'year'=> $año,
                        'jsonBody'=> serialize($jsonBody),
                        'fileAttachment' => $nameFile
                    ));

                }else{
                    // ==== SOLO USUARIOS
                    $user = (object) $user;

                    $user->payment = PaymentLog::with(['paymentOrder.pack' ])->where( "user_id" ,  $user->id )
                        ->where( function ($query) {
                            $query->where('state' , PaymentLog::PAGADO)
                            ->orWhere('state' , PaymentLog::TERMINADO);
                        })
                        ->orderBy('created_at', 'desc')
                        ->first();

                    if( $user->payment == null ) continue;

                    $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id" , $user->id)->where("state" , true)->get();

                    $calculator = $this->calculator->points($user->uuid, $paymentOrderPoints,
                        $paymentProductOrderPoints, (int) $month, (int) $año);
                    $calculatorTotalPoint = $this->calculator->pointsTotal($user->uuid, $paymentOrderPoints,
                        $paymentProductOrderPoints, (int) $month, (int) $año);
                    $commissions = $ledger->summary($from, $to, $user->uuid);
                    
                    $jsonBody = array(
                        "email" => $user->email,
                        "range" => $user->range == null ? "Sin Rango" : $user->range->range->title,
                        "pack" => $user->payment?->paymentOrder?->pack?->title ?? "Sin Plan",
                        "status" => $user->payment == null ? "--" : ( $user->payment->state == PaymentLog::PAGADO ? "Activo" : "Inactivo" ),
                        "points" => (object) array(
                            "patrocinio"    => $commissions['patrocinio'],
                            "residual"      => $commissions['residual'],
                            "compra"        => $calculator->compra,
                            "pointGroup"    => $calculator->pointGroup,
                            "personal"      => $calculator->personal,
                            "infinito"      => $commissions['infinito'],
                            "pointAfiliado" => $calculator->pointAfiliado,
                            "personalGlobal" => $calculator->personalGlobal,
                            "patrocinioRequest" => $calculator->patrocinioRequest,
                        ),
                        "totalPoint" => $calculatorTotalPoint
                    );

                    $userTemp = UserEmailTemp::create(array(
                        'userId' => $user->id,
                        'isAdmin' => $user->is_admin,
                        'status' => UserEmailTemp::PENDIENTE,
                        'email' => $user->email,
                        'subject' => $subject. " ". strtoupper($mes) ."-".$año,
                        'month'=> $month,
                        'year'=> $año,
                        'jsonBody'=> serialize($jsonBody),
                    ));

                }
            }

            PaymentLog::with(['paymentOrder'])->where('state' , PaymentLog::PAGADO)
                ->update(array( "state" => PaymentLog::TERMINADO ));

            PaymentOrderPoint::where('state', true)
                ->whereIn('type', [PaymentOrderPoint::COMPRA, PaymentOrderPoint::GRUPAL])
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->update(["state" => false]);
            PaymentProductOrder::where('state', PaymentProductOrder::PAGADO)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->update(["state" => PaymentProductOrder::TERMINADO]);
            PaymentProductOrderPoint::where('state', true)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->update(["state" => false]);

            RangeUser::where("status", true)->update( array("status" => false) );

            DB::commit();

            ScheduleCron::where("id", $scheduleCron->id)->update(array(
                "response" => json_encode( array() ),
                "status" => 2
            ));

        }catch (Exception $e){
            DB::rollBack();
            ScheduleCron::where("id", $scheduleCron->id)->update(array(
                "status" => 3,
                "response" => $e->getMessage()
            ));
        }
    }
}
