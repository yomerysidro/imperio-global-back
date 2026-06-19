<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\Option;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use App\Models\User;

use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
use App\Models\SponsorshipPoint;
use App\Models\ResidualPoint;
use App\Models\LogPayment;

use App\Models\PaymentProductOrder;
use App\Models\PaymentProductOrderDetail;

use App\Models\PaymentProductOrderPoint;

use App\Models\ProductPointPack;
use Illuminate\Support\Facades\Schema;

class OptionController extends BaseController
{
    //

    public function search( Request $request )
    {
        try {
            $optionList = Option::with([]);

            if( $request->has('key') ) $optionList = $optionList->where("option_key"  ,  $request->query('key')  );

            $optionList = $optionList->get();

            return $this->sendResponse( $optionList, 'Lista');
        }
        catch (\Throwable $th) {

            return $this->sendError($th->getMessage() , $th);
        }

    }

    public function create( Request $request )
    {
        try {
            $dataBody = (object) $request->all();

            $option = Option::create(array(
                'option_key' => $dataBody->option_key,
                'option_value' => $dataBody->option_value,
            ));

            return $this->sendResponse( $option->id, 'creado');
        }
        catch (\Throwable $th) {

            return $this->sendError($th->getMessage() , $th);
        }
    }

    public function truncate( Request $request )
    {
        try {
           //  DB::beginTransaction();
            $user_id = Auth::id();

            $userModel = User::with(['file'])->find($user_id);

            if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );

            Schema::disableForeignKeyConstraints();

            PaymentLog::truncate();
            LogPayment::truncate();

            PaymentOrderPoint::truncate();
            PaymentOrder::truncate();



            PaymentProductOrderDetail::truncate();
            PaymentProductOrderPoint::truncate();
            PaymentProductOrder::truncate();
            ProductPointPack::truncate();

            Schema::enableForeignKeyConstraints();

            // DB::commit();
            return $this->sendResponse( "base de datos truncate", 'Lista');
        }
        catch (\Throwable $th) {
            DB::rollBack();
            return $this->sendError($th->getMessage() , $th);
        }
    }

    public function reboot( Request $request )
    {
        try {
            //  DB::beginTransaction();
             $user_id = Auth::id();

             $userModel = User::with(['file'])->find($user_id);

             if( !$userModel->is_admin ) return $this->sendError( "No tiene permisos ese usuario" );

             PaymentLog::query()->update(array(
                "state" => PaymentLog::REBBOT
             ));

             Schema::disableForeignKeyConstraints();

             PaymentOrderPoint::truncate();
             PaymentProductOrderPoint::truncate();

             Schema::enableForeignKeyConstraints();

             // DB::commit();
             return $this->sendResponse( "base de datos truncate", 'Lista');
         }
         catch (\Throwable $th) {
             DB::rollBack();
             return $this->sendError($th->getMessage() , $th);
         }
    }
}
