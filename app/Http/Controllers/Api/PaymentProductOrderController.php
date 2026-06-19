<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Exception;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use App\Models\Product;
use App\Models\File;
use App\Models\PaymentProductOrder;
use App\Models\PaymentProductOrderDetail;
use App\Models\LogPayment;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentProductOrderPoint;
use App\Models\Pack;
use App\Models\ProductPointPack;
use App\Http\Controllers\BaseController as BaseController;
use App\Http\Resources\PaginationCollection;
use App\Models\Option;
use App\Services\Flow\FlowPayment;
use App\Models\PaymentOrderPoint;
use App\Services\Core\Calculator;
use App\Models\AfiliadosPoint;
use App\Models\SponsorshipPoint;
use App\Models\ResidualPoint;

use App\Services\Core\FileUpload;


class PaymentProductOrderController extends BaseController
{
    //

    private $flowPayment;
    private $calculator;
    private $fileUpload;
    private $fileUploadPath;

    public function __construct()
    {
        $this->flowPayment = new FlowPayment();
        $this->calculator = new Calculator();
        $this->fileUpload = new FileUpload();
        $this->fileUploadPath = 'voucher-product';
    }

    public function findAll(Request $request)
    {
        try {
            $limit = $this->limit;

            if( $request->has('limit') ) $limit = intval( $request->query('limit') );

            $muscleGroupList = array();

            $user_id = Auth::id();
            $userModel = User::with([])->find($user_id);

            // $userDetail = UserDetail::where("user_id" , $user_id)->first();

            $paymentProductOrderList = PaymentProductOrder::with(['user','pack','details']);


            // if( $request->has('code') ) if( !empty($request->query('code')) ) $userList = $userList->where("uuid" , 'like' , $request->query('code') );
            // if( $request->has('email') ) if( !empty($request->query('email')) )$userList = $userList->where("email" , 'like' , $request->query('email') );
            // if( $request->has('name') ) if( !empty($request->query('name')) ) $userList = $userList->where("name" , 'like' , '%'.( $request->query('name') ).'%' );
            if( !$userModel->is_admin ){
                $paymentProductOrderList = $paymentProductOrderList->where("user_id" , $user_id );
            }

            if( $request->has('codeuser') ) if( !empty($request->query('codeuser')) ){
                $userCode = $request->query('codeuser');
                $paymentProductOrderList = $paymentProductOrderList->whereHas("user" , function($query) use($userCode ) { return $query->where('uuid', 'like' , $userCode . '%'); } );
            }
            $paymentProductOrderList = $paymentProductOrderList->orderBy('created_at', 'desc')->paginate($limit);

            // $userList = $userList

            return $this->sendResponse( new PaginationCollection($paymentProductOrderList), 'Lista');

        } catch (\Throwable $th) {

            return $this->sendError( $th->getMessage());
        }
    }

    public function findAllDetails(Request $request)
    {
        try {
            $limit = $this->limit;

            if( $request->has('limit') ) $limit = intval( $request->query('limit') );

            $muscleGroupList = array();

            $userId = Auth::id();
            $userModel = User::with([])->find($userId);

            // $userDetail = UserDetail::where("user_id" , $user_id)->first();

            $paymentProductOrderList = PaymentProductOrderDetail::with(['paymentProductOrder']);


            // if( $request->has('code') ) if( !empty($request->query('code')) ) $userList = $userList->where("uuid" , 'like' , $request->query('code') );
            // if( $request->has('email') ) if( !empty($request->query('email')) )$userList = $userList->where("email" , 'like' , $request->query('email') );
            // if( $request->has('name') ) if( !empty($request->query('name')) ) $userList = $userList->where("name" , 'like' , '%'.( $request->query('name') ).'%' );
            if( !$userModel->is_admin ){
                $paymentProductOrderList = $paymentProductOrderList->whereHas("paymentProductOrder" , function( $query ) use ($userId) { $query->where('user_id', $userId ); } );
            }

            $paymentProductOrderList = $paymentProductOrderList->orderBy('created_at', 'desc')->paginate($limit);

            // $userList = $userList

            return $this->sendResponse( new PaginationCollection($paymentProductOrderList), 'Lista');

        } catch (\Throwable $th) {

            return $this->sendError( $th->getMessage());
        }
    }

    public function search(Request $request)
    {
        try {

            $muscleGroupList = array();

            $user_id = Auth::id();
            $userModel = User::with([])->find($user_id);

            // $userDetail = UserDetail::where("user_id" , $user_id)->first();

            $paymentProductOrderList = PaymentProductOrder::with(['user','pack','details']);

            if( !$userModel->is_admin ){
                $paymentProductOrderList = $paymentProductOrderList->where("user_id" , $user_id );
            }

            if( $request->has('state') ) if( !empty($request->query('state')) ) $paymentProductOrderList = $paymentProductOrderList->where("state" , $request->query('state') );

            if( $request->has('codeuser') ) if( !empty($request->query('codeuser')) ){
                $userCode = $request->query('codeuser');
                $paymentProductOrderList = $paymentProductOrderList->whereHas("user" , function($query) use($userCode ) { return $query->where('uuid', 'like' , $userCode . '%'); } );
            }
            $paymentProductOrderList = $paymentProductOrderList->orderBy('created_at', 'desc')->get();

            // $userList = $userList

            return $this->sendResponse( $paymentProductOrderList, 'Search');

        } catch (\Throwable $th) {

            return $this->sendError( $th->getMessage());
        }
    }

    public function changeState(Request $request)
    {
        $validator = Validator::make( $request->all() , [
            'orderId'                 => 'required',
            'state'                 => 'required'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();
            $userId = Auth::id();
            $userCurrent = User::find($userId);
            if(  !$userCurrent->is_admin ) return $this->sendError('Este usuario no tiene permisos');

            $paymentProductOrder = PaymentProductOrder::find( $dataBody->orderId );
            if( $paymentProductOrder == null ) return $this->sendError( "No se encuentra orden de pedido" );

            PaymentProductOrder::where("id" , $dataBody->orderId)->update(
                array( "state" => $dataBody->state )
            );

            // AUTOMATIZACIÓN DE PUNTOS: Si el nuevo estado es PAGADO (2), disparamos la red
            if ($dataBody->state == 2) {
                $user = User::find($paymentProductOrder->user_id);
                $pack = Pack::find($paymentProductOrder->pack_id);
                if ($user && $pack) {
                    $commissionService = new \App\Services\Core\Services\CommissionService();
                    $commissionService->distribute($paymentProductOrder, $user, $pack);
                }
            }

            DB::commit();
            return $this->sendResponse( 1 , 'Estado actualizado y puntos distribuidos');

        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->sendError( $th->getMessage());
        }
    }

    public function points()
    {
        try {
            DB::beginTransaction();

            $userId = Auth::id();
            $userCurrent = User::find($userId);

            $paymentProductOrderPoint = PaymentProductOrderPoint::where("user_id" , $userId)->where("state" , true)->get();

            DB::commit();

            return $this->sendResponse( $paymentProductOrderPoint , 'Lista');

        } catch (\Throwable $th) {
            DB::rollBack();
            return $this->sendError( $th->getMessage());
        }
    }
    /**
     * FLOW ============
     *
    */
    public function flowCreate( Request $request )
    {
        $validator = Validator::make( $request->all() , [
            'phone'                 => 'required',
            'address'                 => 'required',
            'details'               => 'required|array',
            'details.*.product'     => 'required|exists:products,id',
            'details.*.quantity'    => 'required|numeric'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $dataBody = (object) $request->all();

            DB::beginTransaction();

            $userId = Auth::id();
            $userCurrent = User::find($userId);
            // if(  $_user->is_admin ) return $this->sendError('Este Usuario no puede realizar una compra');

            $packId = null;
            // if(  $userCurrent->is_admin ) return $this->sendError('Este Usuario no puede realizar una compra');
            $totalAmount = 0;
            $totalPoints = 0;
            $discount = 0;

            $paymentLog = PaymentLog::where( "user_id" , $userId )
                ->where("confirm" , true)->whereIn("state" , [PaymentLog::PAGADO, PaymentLog::TERMINADO])->first();

            if( $paymentLog != null ){
                $PaymentOrder = PaymentOrder::find( $paymentLog->payment_order_id );
                $packId = $PaymentOrder->pack_id;
                $packCurrent = Pack::find($packId);
                $discount = floatval( $packCurrent->discount );
            }

            $optionDiscount = Option::where("option_key" , "second_buy_plan")->first();
            $_paymentProductOrders = PaymentProductOrder::where("user" , $userId)->where("state", PaymentProductOrder::PAGADO)->get();

            if( $packId == $optionDiscount->option_value ){
                if( count($_paymentProductOrders) == 0 ){
                    $discount = 0;
                }
            }
            $productIds = array();

            if( count( $dataBody->details ) == 0 ) return $this->sendError( "No se encuentra productos" );

            foreach( $dataBody->details as $key => $product ) {
                $product = (object) $product;
                array_push($productIds , $product->product);
            }

            $productList = Product::whereIn('id' , $productIds)->get();

            $productListCreate = array();

            foreach( $productList as $key => $product ) {
                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];

                $totalAmount += ( $product->price *  $productDetail->quantity );
                if( $packId != null ){
                    $productPointPack = ProductPointPack::where("product_id" , $product->id )->where("pack_id" , $packId)->first();
                    if(  $productPointPack == null ) $totalPoints += $product->points * $productDetail->quantity;
                    else{
                        $totalPoints += $productPointPack->point * $productDetail->quantity;
                    }
                }else{
                    $totalPoints += $product->points * $productDetail->quantity;
                }

            }

            if( $discount > 0 ){
                $totalAmount = $totalAmount * (100 - $discount) / 100;
            }

            $paymentProductOrder = PaymentProductOrder::create(
                array(
                    'currency'  => 'PEN',
                    'amount'    => $totalAmount,
                    'discount'  => 0,
                    'points'    => $totalPoints,
                    'user_id'   => $userId,
                    'pack_id'   => $packId,
                    'phone'     => $dataBody->phone,
                    'address'   => $dataBody->address,
                    'state'     => PaymentProductOrder::PENDIENTEPAGO,
                    'type'      => self::PAYMENT_FLOW,
                    'token'     => 'NOT_FOUND',
                )
            );

            foreach( $productList as $key => $product ) {
                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];

                $price = $product->price;
                $subtotal = $product->price * $productDetail->quantity;
                if( $discount > 0 ){
                    $price = $price * $discount /100;
                    $subtotal = $subtotal * $discount /100;
                }

                array_push(
                    $productListCreate,
                    array(
                        'payment_product_order_id'  => $paymentProductOrder->id,
                        'product_id'                => $product->id,
                        'product_title'             => $product->title,
                        'quantity'                  => $productDetail->quantity,
                        'price'                     => $product->price,
                        'subtotal'                  => $product->price * $productDetail->quantity,
                        'points'                    => $product->points,
                        'created_at'                => now(),
                        'updated_at'                => now(),
                    )
                );

                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];

                $totalAmount += ( $product->price *  $productDetail->quantity );
                $totalPoints += $product->points;
            }

            PaymentProductOrderDetail::insert($productListCreate);

            $flowPaymentResult = $this->flowPayment->createEmail(
                $paymentProductOrder->id,
                "Pago Productos".$paymentProductOrder->id,
                floatval( $totalAmount ),
                $userCurrent->email
            );

            if( !$flowPaymentResult->success ){
                DB::rollBack();
                return $this->sendError( $flowPaymentResult->message );
            }

            PaymentProductOrder::where('id', $paymentProductOrder->id)->update(
                array(
                    'token'     => $flowPaymentResult->data["token"],
                )
            );

            LogPayment::create(
                array(
                    'type' => LogPayment::FLOWPRODUCT,
                    'message' => "",
                    'apiController' => "PaymentProductOrderController::flowCreate",
                    'jsonRequest' => "",
                    'log_order_id' => $paymentProductOrder->id
                )
            );

            DB::commit();

            return $this->sendResponse( $flowPaymentResult->data["url"] ."?token=" . $flowPaymentResult->data["token"] , 'Payment');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    /**
     * IZIPAY ============
     *
    */

    public function izipayCreate( Request $request )
    {
        $validator = Validator::make( $request->all() , [
            'phone'                 => 'required',
            'address'                 => 'required',
            'details'               => 'required|array',
            'details.*.product'     => 'required|exists:products,id',
            'details.*.quantity'    => 'required|numeric'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);
        $responseArr = array();
        try {
            $dataBody = (object) $request->all();
            DB::beginTransaction();

            $userId = Auth::id();
            $userCurrent = User::find($userId);
            // if(  $userCurrent->is_admin ) return $this->sendError('Este Usuario no puede realizar una compra');
            $packId = null;
            $totalAmount = 0;
            $totalPoints = 0;
            $discount = 0;



            $paymentLog = PaymentLog::where( "user_id" , $userId )
                ->where("confirm" , true)->whereIn("state" , [PaymentLog::PAGADO, PaymentLog::TERMINADO])->first();

            if( $paymentLog != null ){
                $PaymentOrder = PaymentOrder::find( $paymentLog->payment_order_id );
                $packId = $PaymentOrder->pack_id;

                $packCurrent = Pack::find($packId);
                $discount = floatval( $packCurrent->discount );
            }

            $productIds = array();

            if( count( $dataBody->details ) == 0 ) return $this->sendError( "No se encuentra productos" );

            foreach( $dataBody->details as $key => $product ) {
                $product = (object) $product;
                array_push($productIds , $product->product);
            }

            $productList = Product::whereIn('id' , $productIds)->get();

            $productListCreate = array();

            foreach( $productList as $key => $product ) {
                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];

                $totalAmount += ( $product->price *  $productDetail->quantity );
                if( $packId != null ){
                    $productPointPack = ProductPointPack::where("product_id" , $product->id )->where("pack_id" , $packId)->first();
                    if(  $productPointPack == null ) $totalPoints += 0;
                    else{
                        $totalPoints += $productPointPack->point *  $productDetail->quantity;
                    }
                }else{
                    $totalPoints += 0;
                }

            }

            if( $discount > 0 ){
                $totalAmount = $totalAmount * (100 - $discount) / 100;
            }

            array_push($responseArr , array("PaymentProductOrder" => array(
                'currency'  => 'PEN',
                'amount'    => $totalAmount,
                'discount'  => $discount,
                'points'    => $totalPoints,
                'user_id'   => $userId,
                'pack_id'   => $packId,
                'phone'     => $dataBody->phone,
                'address'   => $dataBody->address,
                'state'     => PaymentProductOrder::PENDIENTEPAGO,
                'type'      => self::PAYMENT_IZIPAY,
                'token'     => 'NOT_FOUND',
            )));



            $paymentProductOrder = PaymentProductOrder::create(
                array(
                    'currency'  => 'PEN',
                    'amount'    => $totalAmount,
                    'discount'  => $discount,
                    'points'    => $totalPoints,
                    'user_id'   => $userId,
                    'pack_id'   => $packId,
                    'phone'     => $dataBody->phone,
                    'address'   => $dataBody->address,
                    'state'     => PaymentProductOrder::PENDIENTEPAGO,
                    'type'      => self::PAYMENT_IZIPAY,
                    'token'     => 'NOT_FOUND',
                )
            );

            foreach( $productList as $key => $product ) {
                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];
                $price = $product->price;
                $subtotal = $product->price * $productDetail->quantity;
                $_points = 0;
                $productPointPack = ProductPointPack::where("product_id" , $product->id )->where("pack_id" , $packId)->first();
                if(  $productPointPack != null ) $_points = $productPointPack->point *  $productDetail->quantity;


                if( $discount > 0 ){
                    $price = $price * (100 - $discount) /100;
                    $subtotal = $subtotal * (100 - $discount) /100;
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

            $orderId = uniqid( $paymentProductOrder->id );

            // BODY izipay
            $store = array(
                "amount" => round((round( $totalAmount , 2 ) * 100), 2),
                "currency" => "PEN",
                "orderId" => $orderId,
                "customer" => array(
                    "email" => $userCurrent->email
                )
            );

            $response = $this->post("V4/Charge/CreatePayment", $store);

            if ($response['status'] != 'SUCCESS'){
                DB::rollBack();
                $error = $response['answer'];
                return $this->sendError( $error['errorMessage'] );
            }

            $formToken = $response["answer"]["formToken"];

            LogPayment::create(
                array(
                    'type' => LogPayment::IZIPAYPRODUCT,
                    'message' => "",
                    'apiController' => "PaymentProductOrderController::flowCreate",
                    'jsonRequest' => "",
                    'log_order_id' => $paymentProductOrder->id
                )
            );

            PaymentProductOrder::where('id' , $paymentProductOrder->id)->update(
                array("token" => $orderId)
            );

            DB::commit();

            return $this->sendResponse( array(
                "id" => $paymentProductOrder->id,
                "formToken" => $formToken,
                "publicKey" => env('IZIPAY_PUBLIC_KEY'),
                "endpoint" => env('IZIPAY_CLIENT_ENDPOINT')
            ) , 'Payment');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function izipayConfirmPayment( Request $request )
    {
        $validator = Validator::make( $request->all() , [
            'clientAnswer' => 'required',
            'hash'    => 'required',
            'hashAlgorithm' => 'required',
            'rawClientAnswer'  => 'required',
            'orderId'  => 'required'
        ]);

        if ($validator->fails()){

            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }

        try{
            DB::beginTransaction();
            $dataBody = (object) $request->all();

            $logPayment = LogPayment::where( 'log_order_id' , $dataBody->orderId )->where( 'type' , LogPayment::IZIPAYPRODUCT )->first();

            if( $logPayment == null){
                DB::rollBack();
                return $this->sendError('No se encuentra id orden de pago');
            }

            if( !$this->checkHash( $request->input("hashAlgorithm") ,
                $request->input("rawClientAnswer"),
                $request->input("hash") ) ){
                DB::rollBack();
                LogPayment::where( 'log_order_id' , $dataBody->orderId )->update(array(
                    'message' => "Firma inválida -".$request->input("hash"),
                    'apiController' => "IzipayController::confirmPayment",
                    'jsonRequest' => json_encode( $request->all() ),
                ));
                return $this->sendError('Firma inválida' );
            }

            $formAnswer = (object) $request->input('clientAnswer');
            $orderId = $formAnswer->orderDetails['orderId'];

            $paymentProductOrder = PaymentProductOrder::where( "token" , $orderId )->first();

            if( $paymentProductOrder == null ) {
                DB::rollBack();
                LogPayment::where( 'log_order_id' , $dataBody->orderId )->update(array(
                    'message' => "Payment Order no existe",
                    'apiController' => "IzipayController::izipayConfirmPayment",
                    'jsonRequest' => json_encode( $request->all() ),
                    'jsonResponse' => json_encode( $formAnswer ),
                ));
                return $this->sendError( "Payment Order no existe" );
            }

            $userCurrent = User::find( $paymentProductOrder->user_id );

            PaymentProductOrder::where("id" , $paymentProductOrder->id )->update(
                array(
                    "state" => PaymentLog::PAGADO,
                )
            );

            LogPayment::where( 'log_order_id' , $dataBody->orderId )->update(array(
                'message' => "PAGADO",
                'apiController' => "IzipayController::izipayConfirmPayment",
                'jsonRequest' => json_encode( $request->all() ),
                'jsonResponse' => json_encode( $formAnswer ),
            ));

            PaymentProductOrderPoint::create(
                array(
                    'payment_product_order_id'  => $paymentProductOrder->id,
                    'user_id'                   => $paymentProductOrder->user_id,
                    'points'                    => $paymentProductOrder->points,
                    'state'                     => true
                )
            );

            // ver total de puntos
            $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder.paymentLog'])->where('state' , true)->get();
            $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id" , $paymentProductOrder->user_id)->where("state" , true)->get();

            // $calculatorPoint = $this->calculator->points( $userCurrent->uuid , $paymentOrderPoints , $paymentProductOrderPoints);
            // $totalPoints = $calculatorPoint->patrocinio + $calculatorPoint->residual + $calculatorPoint->compra + $calculatorPoint->pointGroup + $calculatorPoint->personal;
            $personalPoint = 0;
            foreach ($paymentProductOrderPoints as $key => $paymentProductOrderPoint) {
                $personalPoint = $personalPoint + $paymentProductOrderPoint->points;
            }

            $maxPointsProduct = Option::where("option_key" , "max_points_product")->first();

            if( $personalPoint >= floatval($maxPointsProduct->option_value) )
            {
                $paymentLog = PaymentLog::with(['paymentOrder.pack'])
                    ->where( "user_id" ,  $paymentProductOrder->user_id )

                    ->orderBy('created_at', 'desc')
                    ->first();

                if( $paymentLog != null ){

                    if( $paymentLog->state  == PaymentLog::TERMINADO ){
                        // === REACTIVAR EL PLAN
                        $_paymentOrder = PaymentOrder::find( $paymentLog->payment_order_id );

                        $paymentOrder = PaymentOrder::create(
                            array(
                                'currency' => "PEN",
                                'amount' => 0,
                                'sponsor_code' => $_paymentOrder->sponsor_code,
                                'pack_id' => $_paymentOrder->pack_id,
                            )
                        );

                        $_paymentLog = PaymentLog::create(
                            array(
                                'payment_order_id' => $paymentOrder->id,
                                "confirm" => true,
                                'user_id' => $paymentProductOrder->user_id,
                                "state" => PaymentLog::PAGADO,
                            )
                        );

                        $__paymentLog = PaymentLog::with(['paymentOrder.pack'])
                        ->where( "id" ,  $_paymentLog ->id )

                        ->orderBy('created_at', 'desc')
                        ->first();

                        $this->confirmPoint($paymentOrder , $userCurrent , $__paymentLog->paymentOrder->pack, true);

                        $option = Option::where("option_key", 'reactive_point')->first();

                        // desabilitado - 15-06

                        // PaymentOrderPoint::create(array(
                        //     'payment_order_id' => $paymentOrder->id,
                        //     'user_code' => $userCurrent->uuid,
                        //     'sponsor_code' => $paymentOrder->sponsor_code,
                        //     'point' => floatval($option->option_value ?? "200"),
                        //     'payment' => true,
                        //     'type' => PaymentOrderPoint::COMPRA,
                        //     'user_id' => $userCurrent->id
                        // ));

                        LogPayment::create(array(
                            'type'  => LogPayment::IZIPAYPRODUCT ,
                            'message' => "IZIPAYPRODUCT",
                            'apiController' => "PaymentProductOrderController::izipayConfirmPayment",
                            'jsonRequest' => "",
                            "log_order_id" => $paymentOrder->id
                        ));
                    }

                }

            }

            $this->confirmPointAfiliado( $userCurrent, $paymentProductOrder->points , $personalPoint);

            DB::commit();
            return $this->sendResponse( array() , 'Confirm');

        }catch (Exception $e) {
            DB::rollBack();

            LogPayment::where( 'log_order_id' , $dataBody->orderId )->update(array(
                'message' => $e->getMessage(),
                'apiController' => "IzipayController::notificationIpn",
                'jsonRequest' => json_encode( $request->all() ),
                'jsonResponse' => json_encode( $e ),
            ));

            return $this->sendError( $e->getMessage() );
        }
    }

    /**
     * OFFLINE ============
     *
    */
    public function paymentOffline( Request $request )
    {
        $validator = Validator::make( $request->all() , [
            'phone'                 => 'required',
            'address'                 => 'required',
            'details'               => 'required|array',
            'details.*.product'     => 'required|exists:products,id',
            'details.*.quantity'    => 'required|numeric'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $dataBody = (object) $request->all();
            DB::beginTransaction();

            $userId = Auth::id();
            $userCurrent = User::find($userId);
            $packId = null;
            // if(  $userCurrent->is_admin ) return $this->sendError('Este Usuario no puede realizar una compra');

            $paymentLog = PaymentLog::where( "user_id" , $userId )->where("confirm" , true)->where("state" , PaymentLog::PAGADO)->first();
            if( $paymentLog != null ){
                $PaymentOrder = PaymentOrder::find( $paymentLog->payment_order_id );
                $packId = $PaymentOrder->pack_id;
            }

            $totalAmount = 0;
            $totalPoints = 0;

            $productIds = array();

            if( count( $dataBody->details ) == 0 ) return $this->sendError( "No se encuentra productos" );

            foreach( $dataBody->details as $key => $product ) {
                $product = (object) $product;
                array_push($productIds , $product->product);
            }

            $productList = Product::whereIn('id' , $productIds)->get();

            $productListCreate = array();

            foreach( $productList as $key => $product ) {
                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];

                $totalAmount += ( $product->price *  $productDetail->quantity );
                $totalPoints += $product->points;

            }

            $paymentProductOrder = PaymentProductOrder::create(
                array(
                    'currency'  => 'PEN',
                    'amount'    => $totalAmount,
                    'discount'  => 0,
                    'points'    => $totalPoints,
                    'user_id'   => $userId,
                    'pack_id'   => $packId,
                    'phone'     => $dataBody->phone,
                    'address'   => $dataBody->address,
                    'state'     => PaymentProductOrder::PENDIENTEPAGO,
                    'type'      => self::PAYMENT_FLOW,
                    'token'     => 'NOT_FOUND',
                )
            );

            foreach( $productList as $key => $product ) {
                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];

                $totalAmount += ( $product->price *  $productDetail->quantity );
                $totalPoints += $product->points;

                $productListCreate[] = array(
                    'payment_product_order_id'  => $paymentProductOrder->id,
                    'product_id'                => $product->id,
                    'product_title'             => $product->title,
                    'quantity'                  => $productDetail->quantity,
                    'price'                     => $product->price,
                    'subtotal'                  => $product->price * $productDetail->quantity,
                    'points'                    => $product->points,
                    'created_at'                => now(),
                    'updated_at'                => now(),
                );
            }

            PaymentProductOrderDetail::insert($productListCreate);

            DB::commit();

            return $this->sendResponse( array(
                "order" => $paymentProductOrder->id,
            ) , 'Payment offline');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function paymentOfflineConfirm( Request $request )
    {
        $validator = Validator::make( $request->all() , [
            'orderId'  => 'required'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {

            $dataBody = (object) $request->all();
            DB::beginTransaction();

            $userId = Auth::id();
            $userCurrent = User::find($userId);
            $paymentProductOrder = PaymentProductOrder::where( "id" , $dataBody->orderId )->first();
            if( $paymentProductOrder == null ) return $this->sendError( "orden no existe" );

            PaymentProductOrder::where("id" , $paymentProductOrder->id )->update(
                array(
                    "state" => PaymentLog::PAGADO,
                )
            );

            LogPayment::where( 'log_order_id' , $dataBody->orderId )->update(array(
                'message' => "PAGADO",
                'apiController' => "IzipayController::izipayConfirmPayment",
                'jsonRequest' => json_encode( $request->all() ),
                'jsonResponse' => "",
            ));

            // AUTOMATIZACIÓN DE RED HÍBRIDA: Los puntos suben por toda la genealogía
            // Incluyendo los puntos personales (distribute ya maneja la creación de puntos tipo B)
            $user = User::find($paymentProductOrder->user_id);
            $pack = Pack::find($paymentProductOrder->pack_id);
            if ($user && $pack) {
                $commissionService = new \App\Services\Core\Services\CommissionService();
                $commissionService->distribute($paymentProductOrder, $user, $pack);
            }

            DB::commit();

            return $this->sendResponse( array(
                "order" => $paymentProductOrder->id,
            ) , 'Pago confirmado y red activada de forma autónoma.');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }
    }

    public function paymentCash( Request $request )
    {
        $validator = Validator::make( $request->all() , [
            'phone'                 => 'required',
            'address'                 => 'required',
            'details'               => 'required',
            'file'               => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $dataBody = (object) $request->all();
            DB::beginTransaction();
            $userId = Auth::id();
            $userCurrent = User::find($userId);
            // if(  $userCurrent->is_admin ) return $this->sendError('Este Usuario no puede realizar una compra');
            $packId = null;
            $totalAmount = 0;
            $totalPoints = 0;
            $discount = 0;

            $paymentLog = PaymentLog::where( "user_id" , $userId )
                ->where("confirm" , true)->whereIn("state" , [PaymentLog::PAGADO, PaymentLog::TERMINADO])->first();

            if( $paymentLog != null ){
                $PaymentOrder = PaymentOrder::find( $paymentLog->payment_order_id );
                $packId = $PaymentOrder->pack_id;

                $packCurrent = Pack::find($packId);
                $discount = floatval( $packCurrent->discount );
            }

            $productIds = array();

            $dataBody->details = json_decode($dataBody->details);

            if( count( $dataBody->details ) == 0 ) return $this->sendError( "No se encuentra productos" );

            foreach( $dataBody->details as $key => $product ) {
                $product = (object) $product;
                array_push($productIds , $product->product);
            }

            $productList = Product::with([])->whereIn('id' , $productIds)->get();

            $productListCreate = array();

            foreach( $productList as $key => $product ) {
                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];

                $totalAmount += ( $product->price *  $productDetail->quantity );
                if( $packId != null ){
                    $productPointPack = ProductPointPack::where("product_id" , $product->id )->where("pack_id" , $packId)->first();
                    if(  $productPointPack == null ) $totalPoints += 0;
                    else{
                        $totalPoints += $productPointPack->point *  $productDetail->quantity;
                    }
                }else{
                    $totalPoints += 0;
                }

            }

            if( $discount > 0 ){
                $totalAmount = $totalAmount * (100 - $discount) / 100;
            }

            $fileId = 0;

            if($request->hasfile('file')) $fileId = $this->fileUpload->upload( $request->file('file') , $this->fileUploadPath);

            $paymentProductOrder = PaymentProductOrder::create(
                array(
                    'currency'  => 'PEN',
                    'amount'    => $totalAmount,
                    'discount'  => $discount,
                    'points'    => $totalPoints,
                    'user_id'   => $userId,
                    'pack_id'   => $packId,
                    'phone'     => $dataBody->phone,
                    'address'   => $dataBody->address,
                    'state'     => PaymentProductOrder::PREORDER,
                    'type'      => self::PAYMENT_ADMIN,
                    'token'     => 'NOT_FOUND',
                    'file'      => $fileId
                )
            );

            foreach( $productList as $key => $product ) {
                $keyDetail = array_search( $product->id , array_column($dataBody->details , 'product')  );
                $productDetail = (object) $dataBody->details[$keyDetail];
                $price = $product->price;
                $subtotal = $product->price * $productDetail->quantity;
                $_points = 0;
                $productPointPack = ProductPointPack::where("product_id" , $product->id )->where("pack_id" , $packId)->first();
                if(  $productPointPack != null ) $_points = $productPointPack->point *  $productDetail->quantity;

                if( $discount > 0 ){
                    $price = $price * (100 - $discount) /100;
                    $subtotal = $subtotal * (100 - $discount) /100;
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

            DB::commit();
            return $this->sendResponse( array() , 'paymentCash');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() , [] , 402 );
        }
    }

    public function paymentCashConfirm( Request $request )
    {
        $validator = Validator::make( $request->all() , [
            'paymentId'    => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();
            $userId = Auth::id();

            $paymentProductOrder = PaymentProductOrder::where("id", $dataBody->paymentId)->where("state", PaymentProductOrder::PREORDER)->first();

            if( $paymentProductOrder == null ) return $this->sendError('No se encontro orden de pago.');

            PaymentProductOrder::where("id" , $paymentProductOrder->id )->update(
                array(
                    "state" => PaymentLog::PAGADO,
                )
            );

            PaymentProductOrderPoint::create(
                array(
                    'payment_product_order_id'  => $paymentProductOrder->id,
                    'user_id'                   => $paymentProductOrder->user_id,
                    'points'                    => $paymentProductOrder->points,
                    'state'                     => true
                )
            );

            $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id" , $paymentProductOrder->user_id)->where("state" , true)->get();

            $personalPoint = 0;
            foreach ($paymentProductOrderPoints as $key => $paymentProductOrderPoint) {
                $personalPoint = $personalPoint + $paymentProductOrderPoint->points;
            }

            $maxPointsProduct = Option::where("option_key" , "max_points_product")->first();

            $userCurrent = User::find( $paymentProductOrder->user_id );

            if( $personalPoint >= floatval($maxPointsProduct->option_value) )
            {

                $this->confirmPointAfiliado( $userCurrent, $paymentProductOrder->points , $personalPoint);

                $paymentLog = PaymentLog::with(['paymentOrder.pack'])
                    ->where( "user_id" ,  $paymentProductOrder->user_id )

                    ->orderBy('created_at', 'desc')
                    ->first();

                if( $paymentLog != null ){

                    if( $paymentLog->state  == PaymentLog::TERMINADO ){
                        // === REACTIVAR EL PLAN
                        $_paymentOrder = PaymentOrder::find( $paymentLog->payment_order_id );

                        $paymentOrder = PaymentOrder::create(
                            array(
                                'currency' => "PEN",
                                'amount' => 0,
                                'sponsor_code' => $_paymentOrder->sponsor_code,
                                'pack_id' => $_paymentOrder->pack_id,
                            )
                        );

                        $_paymentLog = PaymentLog::create(
                            array(
                                'payment_order_id' => $paymentOrder->id,
                                "confirm" => true,
                                'user_id' => $paymentProductOrder->user_id,
                                "state" => PaymentLog::PAGADO,
                            )
                        );

                        $__paymentLog = PaymentLog::with(['paymentOrder.pack'])
                        ->where( "id" ,  $_paymentLog ->id )

                        ->orderBy('created_at', 'desc')
                        ->first();

                        $this->confirmPoint($paymentOrder , $userCurrent , $__paymentLog->paymentOrder->pack, true);

                        $option = Option::where("option_key", 'reactive_point')->first();

                        // desabilitado - 15-06

                        // PaymentOrderPoint::create(array(
                        //     'payment_order_id' => $paymentOrder->id,
                        //     'user_code' => $userCurrent->uuid,
                        //     'sponsor_code' => $paymentOrder->sponsor_code,
                        //     'point' => floatval($option->option_value ?? "200"),
                        //     'payment' => true,
                        //     'type' => PaymentOrderPoint::COMPRA,
                        //     'user_id' => $userCurrent->id
                        // ));
                    }

                }

            }

            DB::commit();

            return $this->sendResponse( array() , 'paymentCashConfirm');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() , [] , 402 );
        }
    }

    /**
     * IZIPAY CURL ============
     *
    */
    private function post(string $target, array $datos)
    {
        $auth = env('IZIPAY_USERNAME') . ":" . env('IZIPAY_PASSWORD');
        $url = env('IZIPAY_ENDPOINT') . "/api-payment/" . $target;
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_USERPWD, $auth);
        curl_setopt($curl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($datos));
        $raw_response = curl_exec($curl);
        $response = json_decode($raw_response, true);
        return $response;
    }

    private function checkHash( $algorithm , $answer, $hash)
    {
        if (!in_array($algorithm, array("sha256_hmac"))) return false;

        if ($algorithm == "sha256_hmac") {
            $key = env('IZIPAY_SHA256_KEY');
        } elseif ($algorithm == "password") {
            $key = env('IZIPAY_PASSWORD');
        } else {
            return false;
        }
        /* on some servers, / can be escaped */
        $krAnswer = str_replace('\/', '/',  $answer );
        $calculateHash = hash_hmac("sha256", $krAnswer, $key);

        return ($calculateHash == $hash);
    }

    private function confirmPointAfiliado( $userCurrent, $points , $totalPoints)
    {
        $paymentLog = PaymentLog::where( "user_id" , $userCurrent->id )
                ->whereIn("state" , [PaymentLog::TERMINADO, PaymentLog::PAGADO] )->orderBy('created_at', 'desc')->first();
        if( $paymentLog != null ){

            $paymentLogsCount = PaymentLog::where( "user_id" , $userCurrent->id )
                ->whereIn("state" , [PaymentLog::TERMINADO, PaymentLog::PAGADO] )->count();

            if( $paymentLogsCount > 1 ){
                $_paymentOrderPoints = $this->loopTree( array() , $userCurrent->uuid );
                $_userCurrent = User::with(['paymentActive','range'])->where('uuid', $userCurrent->uuid )->first();
                $afiliadosPoint = ResidualPoint::first();
                $countLevel = 0;
                foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                    $_paymentOrderPoint = (object) $_paymentOrderPoint;
                    $countLevel++;
                    if( $key > 15 ) continue;
                    // $level = $afiliadosPoint->{'level'.($key)};
                    // $point = $points * floatval($level) / 100;

                    // antes PaymentOrderPoint::AFILIADOS
                    $userSponsor = User::with(['paymentActive','range'])->where('uuid', $_paymentOrderPoint->sponsor_code )->first();
                    $point = 0;
                    if( $countLevel == 1 ) $point = $points * 14 / 100;
                    if( $countLevel == 2 ) $point = $points * 10 / 100;
                    if( $countLevel == 3 ) $point = $points * 18 / 100;
                    // ----
                    if( $countLevel == 4 && ($_userCurrent->range?->range_id ?? 0) >= 3 ) $point = $points * 10 / 100;
                    if( $countLevel == 5 && ($_userCurrent->range?->range_id ?? 0) >= 3 ) $point = $points * 10 / 100;
                    if( $countLevel == 6 && ($_userCurrent->range?->range_id ?? 0) >= 3 ) $point = $points * 10 / 100;
                    // ----

                    if( $countLevel == 7 && ($_userCurrent->range?->range_id ?? 0) >= 4 ) $point = $points * 10 / 100;
                    if( $countLevel == 8 && ($_userCurrent->range?->range_id ?? 0) >= 4 ) $point = $points * 10 / 100;
                    if( $countLevel == 9 && ($_userCurrent->range?->range_id ?? 0) >= 4 ) $point = $points * 10 / 100;

                    // ----

                    if( $countLevel == 10 && ($_userCurrent->range?->range_id ?? 0) > 4 ) $point = $points * 5 / 100;
                    if( $countLevel == 11 && ($_userCurrent->range?->range_id ?? 0) > 4 ) $point = $points * 5 / 100;
                    if( $countLevel == 12 && ($_userCurrent->range?->range_id ?? 0) > 4 ) $point = $points * 5 / 100;
                    // ----

                    if( $countLevel == 13 && ($_userCurrent->range?->range_id ?? 0) > 5 ) $point = $points * 3 / 100;
                    if( $countLevel == 14 && ($_userCurrent->range?->range_id ?? 0) > 5 ) $point = $points * 3 / 100;
                    if( $countLevel == 15 && ($_userCurrent->range?->range_id ?? 0) > 5 ) $point = $points * 3 / 100;

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

    private function confirmPoint( $paymentOrder , $userCurrent , $packCurrent, $reactiveAdmin = false)
    {

        $paymentLogsCount = PaymentLog::where( "user_id" , $userCurrent->id )
                ->whereIn("state" , [PaymentLog::TERMINADO, PaymentLog::PAGADO] )->count();



        // puntos patrocinio
        $sponsorshipPoint = SponsorshipPoint::where("pack_id" , $paymentOrder->pack_id)->first();
        // puntos residuales
        $residualPoint = ResidualPoint::first();

        if( $paymentLogsCount == 0 ){

            // punto de compra
            if( !$reactiveAdmin ){
                PaymentOrderPoint::create(array(
                    'payment_order_id' => $paymentOrder->id,
                    'user_code' => $userCurrent->uuid,
                    'sponsor_code' => $paymentOrder->sponsor_code,
                    'point' => $packCurrent->points,
                    'payment' => true,
                    'type' => PaymentOrderPoint::COMPRA,
                    'user_id' => $userCurrent->id
                ));
            }

            // pago puntos patrocinio
            $level = $sponsorshipPoint->level1;
            $point = floatval($packCurrent->points) * floatval($level) / 100;

            PaymentOrderPoint::create(array(
                'payment_order_id' => $paymentOrder->id,
                'user_code' => $userCurrent->uuid,
                'sponsor_code' => $paymentOrder->sponsor_code,
                'point' => $point,
                'payment' => true,
                'type' => PaymentOrderPoint::PATROCINIO,
                'user_id' => $userCurrent->id
            ));

        }else if( $paymentLogsCount > 0 ){

            $option = Option::where("option_key", 'reactive_point')->first();
            // punto de compra
            if( !$reactiveAdmin ){
                PaymentOrderPoint::create(array(
                    'payment_order_id' => $paymentOrder->id,
                    'user_code' => $userCurrent->uuid,
                    'sponsor_code' => $paymentOrder->sponsor_code,
                    'point' => floatval($option->option_value ?? "200"),
                    'payment' => true,
                    'type' => PaymentOrderPoint::COMPRA,
                    'user_id' => $userCurrent->id
                ));
            }

            // pago puntos residual
            $level = $residualPoint->level1;
            $option = Option::where("option_key", 'point_residual')->first();
            // floatval($packCurrent->points)
            $point = ( floatval($option->option_value) ) * floatval($level) / 100;

            // PaymentOrderPoint::create(array(
            //     'payment_order_id' => $paymentOrder->id,
            //     'user_code' => $userCurrent->uuid,
            //     'sponsor_code' => $paymentOrder->sponsor_code,
            //     'point' => $point,
            //     'payment' => false,
            //     'type' => PaymentOrderPoint::RESIDUAL,
            //     'user_id' => $userCurrent->id
            // ));
        }

        $_paymentOrderPoints = $this->loopTree( array() , $userCurrent->uuid );

        $sponsorshipPoint = SponsorshipPoint::where("pack_id" , $paymentOrder->pack_id)->first();

        $residualPoint = ResidualPoint::first();

        if( $paymentLogsCount == 0 ){
            foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                $_paymentOrderPoint = (object) $_paymentOrderPoint;
                if( $key == 0 ) continue;
                $key++;
                if( $key > 5 ) break;
                $level = $sponsorshipPoint->{'level'.($key)};
                $point = floatval($packCurrent->points) * floatval($level) / 100;
                PaymentOrderPoint::create(array(
                    'payment_order_id' => $paymentOrder->id,
                    'user_code' => $_paymentOrderPoint->user_code,
                    'sponsor_code' => $_paymentOrderPoint->sponsor_code,
                    'point' => $point,
                    'payment' => false,
                    'type' => PaymentOrderPoint::PATROCINIO,
                    'user_id' => $userCurrent->id
                ));
            }
        }else
        if( $paymentLogsCount > 0 ){
            foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                $_paymentOrderPoint = (object) $_paymentOrderPoint;
                $key++;
                if( $key > 7 ) break;
                $level = $residualPoint->{'level'.($key)};

                $option = Option::where("option_key", 'point_residual')->first();
                $point = floatval($option->option_value) * floatval($level) / 100;

                // PaymentOrderPoint::create(array(
                //     'payment_order_id' => $paymentOrder->id,
                //     'user_code' => $_paymentOrderPoint->user_code,
                //     'sponsor_code' => $_paymentOrderPoint->sponsor_code,
                //     'point' => $point,
                //     'payment' => false,
                //     'type' => PaymentOrderPoint::RESIDUAL,
                //     'user_id' => $userCurrent->id
                // ));
            }

        }
        foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
            $_paymentOrderPoint = (object) $_paymentOrderPoint;

            $point = $packCurrent->points;
            if( $paymentLogsCount > 0 ){
                $option = Option::where("option_key", 'reactive_point')->first();
                $point = floatval($option->option_value);
            }

            PaymentOrderPoint::create(array(
                'payment_order_id' => $paymentOrder->id,
                'user_code' => $_paymentOrderPoint->user_code,
                'sponsor_code' => $_paymentOrderPoint->sponsor_code,
                'point' => $point,
                'payment' => false,
                'type' => PaymentOrderPoint::GRUPAL,
                'user_id' => $userCurrent->id
            ));
        }

    }

    private function loopTree( array $a_paymentOrderPoint , string $userCode )
    {
        $paymentOrderPoint = PaymentOrderPoint::select('user_code', 'sponsor_code')
            ->distinct()
            ->where("user_code" , 'like', $userCode)
            ->whereIn("type", [ PaymentOrderPoint::PATROCINIO ])
            ->where("payment" , true)
            ->first();

        if( $paymentOrderPoint != null ){
            array_push( $a_paymentOrderPoint , $paymentOrderPoint  );

            $a_paymentOrderPoint = $this->loopTree( $a_paymentOrderPoint , $paymentOrderPoint->sponsor_code );

        }

        return $a_paymentOrderPoint;
    }

}
