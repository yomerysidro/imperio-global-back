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
use App\Models\PaymentLog;

class RangeListBulk extends Command
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
    protected $signature = 'app:range-list-bulk';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        //
        $scheduleCron = ScheduleCron::create(array(
            'signature' => "app:range-list-bulk",
        ));
        try {
            DB::beginTransaction();

            $userModel = User::where("is_admin", 1)->first();

            $listRange = Range::where("state", 1)->orderBy('order', 'ASC')->get();

            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder.paymentLog'])->where('state' , true)->get();

            $userTreesList = PaymentOrderPoint::with(['user.paymentActive'])->whereIn("type", [PaymentOrderPoint::PATROCINIO])
                ->where("payment" , 1)->get();

            $userTreesListSponsor = PaymentOrderPoint::with([ 'user.paymentActive'])->whereIn("type", [PaymentOrderPoint::PATROCINIO])
                ->where("payment" , 1)->get();

            RangeUser::where("status", 1)->update(array("status" => 0));

            $response = array();

            foreach ($listRange as $keyRange => $range) {
                $range = (object) $range;

                if( $range->id == 2){
                    // PLATA = 2
                    $countRangeOld = RangeUser::where("status", 1)->where("range_id", 1)->count();
                    if( $countRangeOld == 0 ) continue;
                }else if( $range->id == 3){
                    // ORO = 3
                    $countRangeOld = RangeUser::where("status", 1)->where("range_id", 2)->count();
                    if( $countRangeOld == 0 ) continue;
                }else if( $range->id == 4){
                    // JADE = 4
                    $countRangeOld = RangeUser::where("status", 1)->where("range_id", 3)->count();
                    if( $countRangeOld == 0 ) continue;
                }else if( $range->id == 9){
                    // RUBI = 9
                    $countRangeOld = RangeUser::where("status", 1)->where("range_id", 4)->count();
                    if( $countRangeOld == 0 ) continue;
                }else if( $range->id == 5){
                    // DIAMANTE = 5
                    $countRangeOld = RangeUser::where("status", 1)->where("range_id", 9)->count();
                    if( $countRangeOld == 0 ) continue;
                }else if( $range->id == 6){
                    // DOBLE DIAMANTE = 6
                    $countRangeOld = RangeUser::where("status", 1)->where("range_id", 5)->count();
                    if( $countRangeOld == 0 ) continue;
                }else if( $range->id == 7){
                    // TRIPLE DIAMANTE = 7
                    $countRangeOld = RangeUser::where("status", 1)->where("range_id", 6)->count();
                    if( $countRangeOld == 0 ) continue;
                }else if( $range->id == 8){
                    // IMPERIO = 8
                    $countRangeOld = RangeUser::where("status", 1)->where("range_id", 7)->count();
                    if( $countRangeOld == 0 ) continue;
                }

                foreach ($userTreesList as $keyUser => $userPoint)
                {
                    if( $userPoint->user->paymentActive == null ) continue;

                    $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id" , $userPoint->user->id)->where("state" , true)->get();
                    $totalPoints = $this->calculator->pointsTotal( $userPoint->user->uuid , $paymentOrderPoints , $paymentProductOrderPoints);

                    $points = $this->calculator->points( $userPoint->user->uuid , $paymentOrderPoints , $paymentProductOrderPoints);

                    $countChild = 0;
                    
                    $_userTreesList = PaymentOrderPoint::with(['user.paymentActive'])->where("sponsor_code" , 'like', $userPoint->user->uuid)
                    ->whereIn("type", [PaymentOrderPoint::PATROCINIO])
                    ->where("payment" , 1)->get();

                    foreach ($_userTreesList as $key => $_user) {
                        if( $_user->user->paymentActive != null ) $countChild++;
                    }

                    if( $range->id == 1){
                        // BRONCE = 1
                        if($points->pointGroup >= 1000 && $userPoint->user->paymentActive != null){
                            $this->createUpdateRangeUser( $userPoint->user->id , $range->id, true);
                        }
                        
                    }else if( $range->id == 2){
                        // PLATA = 2
                        $countActive = $this->countTreeRangeDirect($userPoint->user->uuid, 1);
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive >= 2 && ( $points->pointGroup >= 3000 && $userPoint->user->paymentActive != null) ) );

                    }else if( $range->id == 3){
                        // ORO = 3
                        $countActive = $this->countTreeRangeDirect($userPoint->user->uuid, 2);
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive >= 3 && ( $points->pointGroup >= 8000 && $userPoint->user->paymentActive != null) ) );

                    }else if( $range->id == 4){
                        // JADE = 4
                        $countActive = $this->countTreeRangeDirect($userPoint->user->uuid, 3); //oro
                        $countActive2 = $this->countTreeRangeDirect($userPoint->user->uuid, 2); //plata
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive2 >= 3 && $countActive >= 1 && ( $points->pointGroup >= 24000 && $userPoint->user->paymentActive != null) ) );

                    }else if( $range->id == 9){
                        // RUBI = 9
                        $countActive = $this->countTreeRangeDirect($userPoint->user->uuid, 3); // oro
                        $countActive2 = $this->countTreeRangeDirect($userPoint->user->uuid, 4); // jade
                        $countActive3 = $this->countTreeRangeDirect($userPoint->user->uuid, 2); //plata
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive >= 1 && $countActive2 >= 1 && $countActive3 >= 2 && ( $points->pointGroup >= 68000 && $userPoint->user->paymentActive != null) ) );

                    }else if( $range->id == 5){
                        // DIAMANTE = 5
                        $countActive2 = $this->countTreeRangeDirect($userPoint->user->uuid, 2); // plata
                        $countActive3 = $this->countTreeRangeDirect($userPoint->user->uuid, 3); // ORO
                        $countActive9 = $this->countTreeRangeDirect($userPoint->user->uuid, 9); // rubi
                        $countActive4 = $this->countTreeRangeDirect($userPoint->user->uuid, 4); // jade
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive2 >= 1 && $countActive3 >= 2 && $countActive9 >= 1 && $countActive4 >= 1 && ( $points->pointGroup >= 100000 && $userPoint->user->paymentActive != null) ) );

                    }else if( $range->id == 6){
                        // DOBLE DIAMANTE = 6
                        $countActive2 = $this->countTreeRangeDirect($userPoint->user->uuid, 2); // plata
                        $countActive9 = $this->countTreeRangeDirect($userPoint->user->uuid, 9); // rubi
                        $countActive3 = $this->countTreeRangeDirect($userPoint->user->uuid, 3); // oro
                        $countActive4 = $this->countTreeRangeDirect($userPoint->user->uuid, 4); // jade
                        $countActive5 = $this->countTreeRangeDirect($userPoint->user->uuid, 5); // diamante
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ( $countActive9 >= 1 && $countActive3 >= 3 && $countActive4 >= 2 && $countActive5 >= 1 && ( $points->pointGroup >= 320000 && $userPoint->user->paymentActive != null) ) );

                    }else if( $range->id == 7){
                        // TRIPLE DIAMANTE = 7
                        $countActive2 = $this->countTreeRangeDirect($userPoint->user->uuid, 2); // plata
                        $countActive9 = $this->countTreeRangeDirect($userPoint->user->uuid, 9); // rubi
                        $countActive3 = $this->countTreeRangeDirect($userPoint->user->uuid, 3); // oro
                        $countActive4 = $this->countTreeRangeDirect($userPoint->user->uuid, 4); // jade
                        $countActive5 = $this->countTreeRangeDirect($userPoint->user->uuid, 5); // diamante
                        $countActive6 = $this->countTreeRangeDirect($userPoint->user->uuid, 6); // DOBLE DIAMANTE
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ( $countActive9 >= 2 && $countActive3 >= 1 && $countActive4 >= 3 && $countActive5 >= 2 && $countActive6 >= 0 && ( $points->pointGroup >= 600000 && $userPoint->user->paymentActive != null) ) );

                    }else if( $range->id == 8){
                        // IMPERIO = 8
                        $countActive2 = $this->countTreeRangeDirect($userPoint->user->uuid, 2); // plata
                        $countActive9 = $this->countTreeRangeDirect($userPoint->user->uuid, 9); // rubi
                        $countActive3 = $this->countTreeRangeDirect($userPoint->user->uuid, 3); // oro
                        $countActive4 = $this->countTreeRangeDirect($userPoint->user->uuid, 4); // jade
                        $countActive5 = $this->countTreeRangeDirect($userPoint->user->uuid, 5); // diamante
                        $countActive6 = $this->countTreeRangeDirect($userPoint->user->uuid, 6); // DOBLE DIAMANTE
                        $countActive7 = $this->countTreeRangeDirect($userPoint->user->uuid, 7); // TRIPLE DIAMANTE
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ( $countActive2 >= 2 && $countActive9 >= 1 && $countActive3 >= 1 && $countActive4 >= 1 && $countActive5 >= 1 && $countActive6 >= 1 && $countActive7 >= 1 && ( $userPoint->user->paymentActive != null) ) );

                    }
                }

            }

            $infinito = array();

            foreach ($userTreesListSponsor as $keyUser => $userPoint)
            {
                // 
                $userModel = User::with(['paymentActive'])->where("uuid", $userPoint->sponsor_code)->first();

                if( $userPoint->user->paymentActive !=null || $userModel->is_admin  ){

                    $childs = $this->loopTree( $userPoint->sponsor_code );

                    // array_push($infinito, array(
                    //     "user" => $userPoint->sponsor_code , 
                    //     "paymentActive" => $this->loopTreeNiveles( $childs , 0 , array() ) 
                    // ));

                    $totalPoints = $this->loopTreeBonoInifity( $childs , $paymentOrderPoints, 0, 0);

                    $maxRange = false;
                    $_rangeUser = RangeUser::with(['range'])->where("user_id", $userModel->id )->where("status" , 1)->first();
                    if( $_rangeUser != null ){
                        $maxRange = $this->loopTreeVerifyRangeMax( $childs, $_rangeUser->range->order, false );
                    }

                    if( $totalPoints > 0 ){

                        $pointInfinito = $totalPoints * 0.02;
                        if( $maxRange ){
                            $pointInfinito = $totalPoints * 0.08;
                        }

                        array_push($infinito, array(
                            "user" => $userPoint->sponsor_code , 
                            "totalPoints" => $totalPoints,
                            "maxRange" => $maxRange,
                            "pointInfinito" => $pointInfinito,
                        ));

                        $point = PaymentOrderPoint::where('type' , PaymentOrderPoint::INFINITO)
                        ->where('user_code' , $userPoint->user_code)
                        ->where('sponsor_code' , $userPoint->sponsor_code)
                        ->where('state' , 1)->first();

                        if( $point == null ){
                            if( $userModel->is_admin ) continue;
                            PaymentOrderPoint::create(array(
                                'payment_order_id' => $userPoint->user->paymentActive->payment_order_id,
                                'user_code' => $userPoint->user_code,
                                'sponsor_code' => $userPoint->sponsor_code,
                                'point' => $pointInfinito,
                                'payment' => false,
                                'type' => PaymentOrderPoint::INFINITO,
                                'user_id' => $userModel->id
                            ));
                        }
                    }

                }
            }

            DB::commit();

            ScheduleCron::where("id", $scheduleCron->id)->update(array(
                "response" => json_encode( array("range" => $response, "infinito" => $infinito ) ),
                "status" => 2
            ));
        } catch (\Exception $e) {
            DB::rollBack();

            ScheduleCron::where("id", $scheduleCron->id)->update(array(
                "status" => 3,
                "response" => $e->getMessage()
            ));
        }
    }

    private function loopTree( string $userCode )
    {
        $paymentOrderPoints = PaymentOrderPoint::with(['user.paymentActive'])->where("sponsor_code" , 'like', $userCode)
        ->whereIn("type", [PaymentOrderPoint::PATROCINIO])
        ->where("payment" , 1)->get();

        $a_paymentOrderPoint = array();

        foreach ($paymentOrderPoints as $key => $paymentOrderPoint) {
            $paymentOrderPoint = (object) $paymentOrderPoint;

            $paymentOrderPoint->childs = $this->loopTree( $paymentOrderPoint->user_code );
            array_push($a_paymentOrderPoint , $paymentOrderPoint);
        }

        return $a_paymentOrderPoint;
    }

    private function loopTreeActive( $a_paymentOrderPoint = array() , string $userCode )
    {
        $paymentOrderPoints = PaymentOrderPoint::with(['user.paymentActive'])->where("sponsor_code" , 'like', $userCode)
        ->whereIn("type", [PaymentOrderPoint::PATROCINIO])
        ->where("payment" , 1)->get();

        foreach ($paymentOrderPoints as $key => $paymentOrderPoint)
        {
            $paymentOrderPoint = (object) $paymentOrderPoint;
            array_push($a_paymentOrderPoint, $paymentOrderPoint);

            $a_paymentOrderPoint = $this->loopTreeActive( $a_paymentOrderPoint, $paymentOrderPoint->user_code );
        }

        return $a_paymentOrderPoint;
    }

    private function countTreeRange( string $userCode , $rangeId)
    {
        $paymentOrderPoints = $this->loopTreeActive( array(), $userCode);

        $a_paymentOrderPoint = array();

        $count = 0;

        foreach ($paymentOrderPoints as $key => $paymentOrderPoint) {
            $paymentOrderPoint = (object) $paymentOrderPoint;
            $rangeUser = RangeUser::where("user_id", $paymentOrderPoint->user->id )->where("status" , 1)->first();
            if( $rangeUser == null ) continue;
            if( $rangeUser->range_id == $rangeId && $paymentOrderPoint->user->paymentActive != null) $count++;

            $count += $this->countTreeRange( $paymentOrderPoint->user_code, $rangeId );

            array_push($a_paymentOrderPoint , $paymentOrderPoint);
        }

        return $count;
    }

    private function countTreeRangeDirect(string $userCode , $rangeId)
    {
        $paymentOrderPoints = PaymentOrderPoint::with(['user.paymentActive'])->where("sponsor_code" , 'like', $userCode)
        ->whereIn("type", [PaymentOrderPoint::PATROCINIO])
        ->where("payment" , 1)->get();

        $count = 0;

        foreach ($paymentOrderPoints as $key => $paymentOrderPoint) {
            $paymentOrderPoint = (object) $paymentOrderPoint;
            $rangeUser = RangeUser::where("user_id", $paymentOrderPoint->user->id )->where("status" , 1)->first();
            if( $rangeUser == null ) continue;
            if( $rangeUser->range_id == $rangeId && $paymentOrderPoint->user->paymentActive != null) $count++;

        }
        
        return $count;
    }

    private function createUpdateRangeUser( $userId, $rangeId, $active)
    {
        if( $active ){
            $orders = PaymentLog::where("user_id", $userId)->where( "state", PaymentLog::TERMINADO)->count();
            if( $orders > 0 || $rangeId == 1 ){
                $rangeUser = RangeUser::where("user_id", $userId )->first();
                if( $rangeUser == null ){
                    RangeUser::create(array(
                        "user_id" => $userId, "range_id" => $rangeId, "status" => 1
                    ));
                }else{
                    RangeUser::where("user_id", $userId)->update(array("range_id" => $rangeId, "status" => 1));
                }
            }
            
        }
    }

    private function loopTreeLevels( array $a_paymentOrderPoint , string $userCode )
    {
        $paymentOrderPoint = PaymentOrderPoint::select('user_code', 'sponsor_code')
            ->distinct()
            ->where("user_code" , 'like', $userCode)
            ->whereIn("type", [ PaymentOrderPoint::PATROCINIO ])
            ->where("payment" , 1)
            ->first();

        if( $paymentOrderPoint != null ){
            array_push( $a_paymentOrderPoint , $paymentOrderPoint  );

            $a_paymentOrderPoint = $this->loopTreeLevels( $a_paymentOrderPoint , $paymentOrderPoint->sponsor_code );

        }

        return $a_paymentOrderPoint;
    }

    private function loopTreeBonoInifity(array $paymentOrderPoints ,$points , $nivel, $totalPoint)
    {
        $nivel++;
        foreach ($paymentOrderPoints as $key => $paymentOrderPoint){
            if( $nivel >= 8 ){
                $granTotal = 0;
                if( $paymentOrderPoint->user?->paymentActive != null ){
                    $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id" , $paymentOrderPoint->user->id)->where("state" , true)->get();
                    $granTotal = $this->calculator->pointsTotal( $paymentOrderPoint->user_code , $points , $paymentProductOrderPoints);
                }
                $totalPoint += $granTotal;
            }
            $totalPoint = $this->loopTreeBonoInifity( $paymentOrderPoint->childs, $points, $nivel, $totalPoint);
        }

        return $totalPoint;

    }

    private function loopTreeVerifyRangeMax(array $paymentOrderPoints , $range, $isRangeMax)
    {
        foreach ($paymentOrderPoints as $key => $paymentOrderPoint){
            if( $paymentOrderPoint->user?->paymentActive != null ){
                $rangeUser = RangeUser::with(['range'])->where("user_id", $paymentOrderPoint->user->id )->where("status" , 1)->first();
                if( $rangeUser == null ) continue;
                if( $range > $rangeUser->range->order ){
                    $isRangeMax = true;
                }
                $isRangeMax = $this->loopTreeVerifyRangeMax($paymentOrderPoint->childs, $range, $isRangeMax);
            }
        }

        return $isRangeMax;
    }
}
