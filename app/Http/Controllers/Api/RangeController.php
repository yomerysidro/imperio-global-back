<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\User;
use App\Models\Range;
use App\Models\File;
use App\Models\PaymentOrderPoint;
use App\Models\RangeUser;
use App\Models\PaymentProductOrderPoint;
use Illuminate\Support\Facades\Storage;
use App\Services\Core\Calculator;

class RangeController extends BaseController
{
    //

    private $calculator;

    public function __construct()
    {
        $this->calculator = new Calculator();
    }

    public function list( Request $request )
    {
        try {
            $user_id = Auth::id();

            $ranges = Range::with(['file'])->get();

            return $this->sendResponse( $ranges , 'list');
        } catch (Exception $e) {

            return $this->sendError( $e->getMessage() );
        }
    }

    public function register( Request $request )
    {
        try {
            $validator = Validator::make( $request->all() , [
                'title'    => 'required',
                'points' => 'required|numeric',
                'childs'    => 'required|numeric'
            ]);
            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            DB::beginTransaction();
            $user_id = Auth::id();
            $userModel = User::with(['file'])->find($user_id);

            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );

            $fileId = 0;

            if($request->hasfile('file'))
            {
                $filePath = Storage::disk('public')->put('files/ranges', $request->file('file'));
                $fileModel = File::create(array(
                    'path' => $filePath,
                    'name' => $request->file('file')->getClientOriginalName(),
                    'extension' => $request->file('file')->getClientOriginalExtension(),
                    'size' => $request->file('file')->getSize()
                ));
                $fileId = $fileModel->id;
            }

            $count = Range::where("state", 1)->count();

            $dataBody = (object) $request->all();
            $rangeCurrent = Range::create(array(
                "title"     => $dataBody->title,
                "points"    => $dataBody->points,
                "childs"    => $dataBody->childs,
                'file'      => $fileId,
                'order'      => $count + 1,
            ));

            DB::commit();

            return $this->sendResponse( $rangeCurrent->id, 'Creado');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function update( Request $request , $id)
    {
        try {
            $validator = Validator::make( $request->all() , [
                'title'    => 'required',
                'points' => 'required|numeric',
                'childs'    => 'required|numeric'
            ]);
            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);
            DB::beginTransaction();
            $user_id = Auth::id();
            $userModel = User::with(['file'])->find($user_id);
            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );

            $dataBody = (object) $request->all();

            Range::where("id" , $id)->update(array(
                "title"     => $dataBody->title,
                "points"    => $dataBody->points,
                "childs"    => $dataBody->childs,
            ));

            $fileId = 0;

            if($request->hasfile('file'))
            {
                $filePath = Storage::disk('public')->put('files/ranges', $request->file('file'));
                $fileModel = File::create(array(
                    'path' => $filePath,
                    'name' => $request->file('file')->getClientOriginalName(),
                    'extension' => $request->file('file')->getClientOriginalExtension(),
                    'size' => $request->file('file')->getSize()
                ));
                $fileId = $fileModel->id;
            }

            if( $fileId > 0 ){
                Range::where("id" , $id)->update(
                    array(
                        'file'     => $fileId,
                    )
                );
            }
            DB::commit();

            return $this->sendResponse( array() , 'update');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }


    public function users( Request $request)
    {
        $error = array();
        try {
            DB::beginTransaction();
            $user_id = Auth::id();
            $userModel = User::with(['file'])->find($user_id);

            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );

            $listRange = Range::where("state", 1)->orderBy('order', 'ASC')->get();

            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder.paymentLog', 'paymentOrder.pack'])->where('state' , true)->get();

            $userTreesList = PaymentOrderPoint::with(['user.paymentActive', 'sponsor.paymentActive'])->whereIn("type", [PaymentOrderPoint::PATROCINIO])
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

                    $countChild = 0;
                    
                    $_userTreesList = PaymentOrderPoint::with(['user.paymentActive'])->where("sponsor_code" , 'like', $userPoint->user->uuid)
                    ->whereIn("type", [PaymentOrderPoint::PATROCINIO])
                    ->where("payment" , 1)->get();

                    foreach ($_userTreesList as $key => $_user) {
                        if( $_user->user->paymentActive != null ) $countChild++;
                    }

                    if( $range->id == 1){
                        // BRONCE = 1
                        if($totalPoints >= 1000 && $userPoint->user->paymentActive != null){
                            $this->createUpdateRangeUser( $userPoint->user->id , $range->id, true);
                            array_push( $response, array(
                                "rango" => 1, "hijos" => $countChild, "usuario" => $userPoint->user->uuid
                            ) );
                        }
                        
                    }else if( $range->id == 2){
                        // PLATA = 2
                        $countActive = $this->countTreeRange($userPoint->user->uuid, 1);
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive >= 2 && $countChild >= 2) );
                        if($countActive >= 2 && $countChild >= 2){
                            array_push( $response, array(
                                "rango" => 2, "hijos" => $countChild, "rang_hijos" => $countActive, "usuario" => $userPoint->user->uuid
                            ) );
                        }
                        

                    }else if( $range->id == 3){
                        // ORO = 3
                        $countActive = $this->countTreeRange($userPoint->user->uuid, 2);
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive >= 2 && $countChild >= 3) );

                    }else if( $range->id == 4){
                        // JADE = 4
                        $countActive = $this->countTreeRange($userPoint->user->uuid, 3); //oro
                        $countActive2 = $this->countTreeRange($userPoint->user->uuid, 2); //plata
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive2 >= 2 && $countActive >= 1 && $countChild >= 4) );

                    }else if( $range->id == 9){
                        // RUBI = 9
                        $countActive = $this->countTreeRange($userPoint->user->uuid, 3); // oro
                        $countActive2 = $this->countTreeRange($userPoint->user->uuid, 4); // jade
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive >= 2 && $countActive2 >= 1 && $countChild >= 4) );

                    }else if( $range->id == 5){
                        // DIAMANTE = 5
                        $countActive2 = $this->countTreeRange($userPoint->user->uuid, 2); // plata
                        $countActive3 = $this->countTreeRange($userPoint->user->uuid, 3); // ORO
                        $countActive9 = $this->countTreeRange($userPoint->user->uuid, 9); // rubi
                        $countActive4 = $this->countTreeRange($userPoint->user->uuid, 4); // jade
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive2 >= 3 && $countActive3 >= 1 && $countActive9 >= 1 && $countActive4 >= 1 && $countChild >= 5) );

                    }else if( $range->id == 6){
                        // DOBLE DIAMANTE = 6
                        $countActive2 = $this->countTreeRange($userPoint->user->uuid, 2); // plata
                        $countActive9 = $this->countTreeRange($userPoint->user->uuid, 9); // rubi
                        $countActive3 = $this->countTreeRange($userPoint->user->uuid, 3); // oro
                        $countActive4 = $this->countTreeRange($userPoint->user->uuid, 4); // jade
                        $countActive5 = $this->countTreeRange($userPoint->user->uuid, 5); // diamante
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive2 >= 2 && $countActive9 >= 1 && $countActive3 >= 1 && $countActive4 >= 2 && $countActive5 >= 1 && $countChild >= 6) );

                    }else if( $range->id == 7){
                        // TRIPLE DIAMANTE = 7
                        $countActive2 = $this->countTreeRange($userPoint->user->uuid, 2); // plata
                        $countActive9 = $this->countTreeRange($userPoint->user->uuid, 9); // rubi
                        $countActive3 = $this->countTreeRange($userPoint->user->uuid, 3); // oro
                        $countActive4 = $this->countTreeRange($userPoint->user->uuid, 4); // jade
                        $countActive5 = $this->countTreeRange($userPoint->user->uuid, 5); // diamante
                        $countActive6 = $this->countTreeRange($userPoint->user->uuid, 6); // DOBLE DIAMANTE
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ($countActive2 >= 2 && $countActive9 >= 1 && $countActive3 >= 1 && $countActive4 >= 2 && $countActive5 >= 1 && $countActive6 >= 1 && $countChild >= 7) );

                    }else if( $range->id == 8){
                        // IMPERIO = 8
                        $countActive2 = $this->countTreeRange($userPoint->user->uuid, 2); // plata
                        $countActive9 = $this->countTreeRange($userPoint->user->uuid, 9); // rubi
                        $countActive3 = $this->countTreeRange($userPoint->user->uuid, 3); // oro
                        $countActive4 = $this->countTreeRange($userPoint->user->uuid, 4); // jade
                        $countActive5 = $this->countTreeRange($userPoint->user->uuid, 5); // diamante
                        $countActive6 = $this->countTreeRange($userPoint->user->uuid, 6); // DOBLE DIAMANTE
                        $countActive7 = $this->countTreeRange($userPoint->user->uuid, 7); // TRIPLE DIAMANTE
                        $this->createUpdateRangeUser( $userPoint->user->id , $range->id, ( $countActive9 >= 3 && $countActive3 >= 3 && $countActive4 >= 3 && $countActive5 >= 1 && $countActive6 >= 1 && $countActive7 >= 1 && $countChild >= 8) );

                    }
                }

            }

            $infinito = array();

            foreach ($userTreesListSponsor as $keyUser => $userPoint)
            {
                // 
                $userModel = User::with(['paymentActive'])->where("uuid", $userPoint->sponsor_code)->first();

                if( $userPoint->user->paymentActive !=null || $userModel->is_admin  ){
                    array_push($error, $userPoint);
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

            return $this->sendResponse( array("range" => $response, "infinito" => $infinito ) , 'users ranges');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() , $error);
        }
    }

    public function usersByCode( Request $request, string $userCode)
    {
        try {

            $user = User::where("uuid" , $userCode)->first();
            if( $user == null ) return $this->sendError( "usuario no existe" );

            DB::beginTransaction();



            DB::commit();

            return $this->sendResponse( array() , 'users lists range');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
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

    private function createUpdateRangeUser( $userId, $rangeId, $active)
    {
        if( $active ){
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

    private function loopTreeNiveles(array $paymentOrderPoints , $nivel, $nivelArray)
    {
        $nivel++;
        foreach ($paymentOrderPoints as $key => $paymentOrderPoint){
            array_push( $nivelArray , array("nivel" => $nivel, "code" => $paymentOrderPoint->user_code));

            $nivelArray = $this->loopTreeNiveles($paymentOrderPoint->childs, $nivel, $nivelArray);
        }
        return $nivelArray;
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
