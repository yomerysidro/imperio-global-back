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
use App\Services\Core\NetworkTreeService;
use App\Services\Core\RangeQualificationService;

class RangeController extends BaseController
{
    //

    private $calculator;
    private $networkTreeService;

    public function __construct()
    {
        $this->calculator = new Calculator();
        $this->networkTreeService = new NetworkTreeService();
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

            $engine = app(RangeQualificationService::class);
            $response = ['range' => $engine->recalculateAll(), 'infinito' => $engine->distributeInfinity()];
            DB::commit();
            return $this->sendResponse($response, 'Rangos recalculados desde la configuracion de BD');

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

    private function loopTreeActive(array $a_paymentOrderPoint, string $userCode)
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
        $codes = $this->networkTreeService->getAllNetworkUsers($userCode);
        $codes = array_values(array_filter($codes, fn ($code) => strcasecmp($code, $userCode) !== 0));

        return User::whereIn('uuid', $codes)
            ->whereHas('range', fn ($query) => $query->where('range_id', $rangeId)->where('status', true))
            ->get()
            ->filter(fn ($user) => $user->active)
            ->count();
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
