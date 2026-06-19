<?php

namespace App\Http\Controllers\Api;

use Exception;
use Illuminate\Http\Request;

use Lyra\Client;
use Lyra\Exceptions\LyraException;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\PaymentLog;
use App\Models\User;
use App\Models\Pack;
use App\Models\Option;
use App\Models\PaymentOrder;
use App\Models\LogPayment;
use App\Models\PaymentOrderPoint;
use App\Models\SponsorshipPoint;
use App\Models\ResidualPoint;

use Illuminate\Support\Facades\Auth;

class IzipayController extends BaseController
{

    private $client;

    public function __construct()
    {
    }

    public function getFormToken()
    {
        $store = array(
            "amount" => 250,
            "currency" => "PEN",
            "orderId" => uniqid("MyOrderId"),
            "customer" => array(
                "email" => "sample@example.com",
            )
        );
        $response = $this->post("V4/Charge/CreatePayment", $store);

        if ($response['status'] != 'SUCCESS') {
            echo ($response);
            $error = $response['answer'];
            throw ("error " . $error['errorCode'] . ": " . $error['errorMessage']);
        }

        $formToken = $response["answer"]["formToken"];
        return $this->sendResponse( array(
            "token" => $formToken
        ) , 'You are successfully logged in.');
    }

    public function success(Request $request)
    {
        // if (empty($_POST)) throw ("no post data received!");

        // $formAnswer['kr-hash'] = $_POST['kr-hash'];
        // $formAnswer['kr-hash-algorithm'] = $_POST['kr-hash-algorithm'];
        // $formAnswer['kr-answer-type'] = $_POST['kr-answer-type'];
        // $formAnswer['kr-answer'] = json_decode($_POST['kr-answer'], true);

        // if (!$this->checkHash()) {
        //     //something wrong, probably a fraud ....
        //     throw ('invalid signature');
        // }

        // if ($formAnswer['kr-answer']['orderStatus'] != 'PAID') {
        //     return 'Transaction not paid !';
        // } else {
        //     $dataPost = json_encode($_POST, JSON_PRETTY_PRINT);
        //     $formAnswer = json_encode($formAnswer["kr-answer"], JSON_PRETTY_PRINT);
        //     return view('izipay.paid', compact('formAnswer', 'dataPost'));
        // }
    }

    public function notificationIpn(Request $request)
    {
        $validator = Validator::make( $request->all() , [
            'kr-hash' => 'required',
            'kr-hash-algorithm'    => 'required',
            'kr-answer-type'    => 'required',
            'kr-answer'    => 'required',
        ]);

        if ($validator->fails()){
            LogPayment::create(array(
                'type'  => LogPayment::IZIPAY ,
                'message' => "Error de validacion",
                'apiController' => "IzipayController::notificationIpn",
                'jsonRequest' => json_encode( $request->all() ),
                'jsonResponse' => json_encode( $validator->errors() ),
            ));
            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }

        try{
            if( !$this->checkHash( $request->input("kr-hash-algorithm") ,
            $request->input("kr-answer"),
            $request->input("kr-hash") ) ){
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Firma inválida -".$request->input("kr-hash"),
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode( $request->all() ),
                ));
                return $this->sendError('Firma inválida' );
            }

            DB::beginTransaction();

            $rawAnswer = json_decode($request->input('kr-answer'), true);
            $formAnswer = $rawAnswer['kr-answer'];
            $orderId = $formAnswer['orderDetails']['orderId'];

            $paymentLog = PaymentLog::where( "token" , $orderId )->first();

            if( $paymentLog == null ){
                DB::rollBack();
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Payment Log no existe su orden => ".$orderId,
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode( $request->all() ),
                    'jsonResponse' => json_encode( $rawAnswer ),
                ));
                return $this->sendError( "Payment Log no existe su orden => ".$orderId );
            }

            $userCurrent = User::find( $paymentLog->user_id );

            $paymentLogsCount = PaymentLog::where( "user_id" , $paymentLog->user_id )
                ->where("confirm" , false)
                ->whereIn("state" , [PaymentLog::TERMINADO, PaymentLog::PAGADO] )->count();

            $paymentOrder = PaymentOrder::find( $paymentLog->payment_order_id );

            if( $paymentOrder == null ) {
                DB::rollBack();
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Payment Order no existe",
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode( $request->all() ),
                    'jsonResponse' => json_encode( $rawAnswer ),
                ));
                return $this->sendError( "Payment Order no existe" );
            }

            $packCurrent = Pack::find( $paymentOrder->pack_id );

            if( $packCurrent == null ){
                DB::rollBack();
                PaymentLog::where("payment_order_id" , $paymentOrder->id )->update(
                    array(
                        "state" => PaymentLog::ERROR,
                        "confirm" => true,
                        "message" => "El plan no existe"
                    )
                );
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Plan no existe",
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode( $request->all() ),
                    'jsonResponse' => json_encode( $rawAnswer ),
                ));
                return $this->sendError( "El plan no existe" );
            }

            // punto de compra
            PaymentOrderPoint::create(array(
                'payment_order_id' => $paymentOrder->id,
                'user_code' => $userCurrent->uuid,
                'sponsor_code' => "",
                'point' => $packCurrent->points,
                'payment' => true,
                'type' => 'B'
            ));

            // puntos patrocinio
            $sponsorshipPoint = SponsorshipPoint::where("pack_id" , $paymentOrder->pack_id)->first();
            // puntos residuales
            $residualPoint = ResidualPoint::first();

            if( $paymentLogsCount <= 1 ){
                // pago puntos patrocinio
                $level = $sponsorshipPoint->level1;
                $point = floatval($packCurrent->points) * floatval($level) / 100;

                PaymentOrderPoint::create(array(
                    'payment_order_id' => $paymentOrder->id,
                    'user_code' => $userCurrent->uuid,
                    'sponsor_code' => $paymentOrder->sponsor_code,
                    'point' => $point,
                    'payment' => true,
                    'type' => 'P'
                ));

            }else if( $paymentLogsCount > 1 ){
                // pago puntos residual
                $level = $residualPoint->level1;
                $point = floatval($packCurrent->points) * floatval($level) / 100;

                PaymentOrderPoint::create(array(
                    'payment_order_id' => $paymentOrder->id,
                    'user_code' => $userCurrent->uuid,
                    'sponsor_code' => $paymentOrder->sponsor_code,
                    'point' => $point,
                    'payment' => true,
                    'type' => 'R'
                ));
            }

            $_paymentOrderPoints = $this->loopTree( array() , $userCurrent->uuid );

            $sponsorshipPoint = SponsorshipPoint::where("pack_id" , $paymentOrder->pack_id)->first();

            $residualPoint = ResidualPoint::first();

            if( $paymentLogsCount <= 1 ){
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
                        'type' => 'P'
                    ));
                }
            }else
            if( $paymentLogsCount > 1 ){
                foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                    $_paymentOrderPoint = (object) $_paymentOrderPoint;
                    if( $key == 0 ) continue;
                    $key++;
                    if( $key > 7 ) break;
                    $level = $residualPoint->{'level'.($key)};
                    $point = floatval($packCurrent->points) * floatval($level) / 100;
                    PaymentOrderPoint::create(array(
                        'payment_order_id' => $paymentOrder->id,
                        'user_code' => $_paymentOrderPoint->user_code,
                        'sponsor_code' => $_paymentOrderPoint->sponsor_code,
                        'point' => $point,
                        'payment' => false,
                        'type' => 'R'
                    ));
                }

            }

            PaymentLog::where("payment_order_id" , $paymentOrder->id )->update(
                array(
                    "state" => PaymentLog::PAGADO,
                    "message" => "",
                    "confirm" => true,
                    "log"   => json_encode( $rawAnswer )
                )
            );

            DB::commit();
            return $this->sendResponse( array() , 'Confirm');

        }catch (Exception $e) {
            DB::rollBack();

            LogPayment::create(array(
                'type'  => LogPayment::IZIPAY ,
                'message' => $e->getMessage(),
                'apiController' => "IzipayController::notificationIpn",
                'jsonRequest' => json_encode( $request->all() ),
                'jsonResponse' => json_encode( $e ),
            ));

            return $this->sendError( $e->getMessage() );
        }
    }

    public function createPayment(Request $request)
    {
        $validator = Validator::make( $request->all() , [
            'packId' => 'required|exists:packs,id',
            'sponsorId'    => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();

            $userId = Auth::id();
            $_user = User::find($userId);
            if(  $_user->is_admin ) return $this->sendError('Este Usuario no puede realizar una compra');
            $paymentLogs = PaymentLog::where( "user_id" , $userId )
                ->where("confirm" , true)
                ->whereIn("state" , [PaymentLog::PAGADO])->count();

            if( $paymentLogs > 0 ) return $this->sendError('Ya existe una subscripcion activa');

            $dataBody = (object) $request->all();

            $sponsor = User::where("uuid" , $dataBody->sponsorId)->first();

            if( $sponsor == null ) return $this->sendError('Codigo de Patronisador no existe.');

            $packCurrent = Pack::find( $dataBody->packId);

            if( $packCurrent == null ) return $this->sendError( "El plan no existe" );

            $option = Option::where("option_key" , "comision")->first();

            $totalAmount = $option == null ? $packCurrent->price : ( floatval($packCurrent->price)+( ($packCurrent->price) * (floatval($option->option_value))/100 ));

            $orderId = uniqid( $packCurrent->title );

            $paymentOrder = PaymentOrder::create(
                array(
                    'currency' => "PEN",
                    'amount' => floatval( number_format($totalAmount , 2)),
                    'sponsor_code' => $dataBody->sponsorId,
                    'pack_id' => $packCurrent->id,
                    "token" => $orderId
                )
            );

            $userCurrent = User::find($userId);

            $store = array(
                "amount" => floatval( number_format($totalAmount , 2)) * 100,
                "currency" => "PEN",
                "orderId" => $orderId,
                "customer" => array(
                    "email" => $userCurrent->email
                )
            );

            $response = $this->post("V4/Charge/CreatePayment", $store);

            $paymentLog = PaymentLog::create(
                array(
                    'payment_order_id' => $paymentOrder->id,
                    "confirm" => false,
                    'user_id' => $userId,
                    "state" => PaymentLog::PENDIENTEPAGO,
                )
            );

            if ($response['status'] != 'SUCCESS'){
                DB::rollBack();
                $error = $response['answer'];
                return $this->sendError( $error['errorMessage'] );
            }

            $formToken = $response["answer"]["formToken"];

            DB::commit();

            return $this->sendResponse( array(
                "formToken" => $formToken,
                "publicKey" => env('IZIPAY_PUBLIC_KEY'),
                "endpoint" => env('IZIPAY_CLIENT_ENDPOINT')
            ) , 'Payment');

        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError( $e->getMessage() );
        }

    }

    public function confirmPayment( Request $request )
    {
        try{

            $validator = Validator::make( $request->all() , [
                'clientAnswer' => 'required',
                'hash'    => 'required',
                'hashAlgorithm' => 'required',
                'rawClientAnswer'  => 'required'
            ]);

            if ($validator->fails()){
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Error de validacion",
                    'apiController' => "IzipayController::confirmPayment",
                    'jsonRequest' => json_encode( $request->all() ),
                    'jsonResponse' => json_encode( $validator->errors() ),
                ));
                return $this->sendError('Error de validacion.', $validator->errors(), 422);
            }

            if( !$this->checkHash( $request->input("hashAlgorithm") ,
                $request->input("rawClientAnswer"),
                $request->input("hash") ) ){
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Firma inválida -".$request->input("hash"),
                    'apiController' => "IzipayController::confirmPayment",
                    'jsonRequest' => json_encode( $request->all() ),
                ));
                return $this->sendError('Firma inválida' );
            }

            DB::beginTransaction();

            $formAnswer = (object) $request->input('clientAnswer');
            $orderId = $formAnswer->orderDetails['orderId'];

            $paymentOrder = PaymentOrder::where( "token" , $orderId )->first();

            if( $paymentOrder == null ) {
                DB::rollBack();
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Payment Order no existe",
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode( $request->all() ),
                    'jsonResponse' => json_encode( $formAnswer ),
                ));
                return $this->sendError( "Payment Order no existe" );
            }

            $paymentLog = PaymentLog::where( "payment_order_id" , $paymentOrder->id )->first();

            if( $paymentLog == null ){
                DB::rollBack();
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Payment Log no existe su orden => ".$orderId,
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode( $request->all() ),
                    'jsonResponse' => json_encode( $formAnswer ),
                ));
                return $this->sendError( "Payment Log no existe su orden => ".$orderId );
            }

            $userCurrent = User::find( $paymentLog->user_id );

            $packCurrent = Pack::find( $paymentOrder->pack_id );

            if( $packCurrent == null ){
                DB::rollBack();
                PaymentLog::where("payment_order_id" , $paymentOrder->id )->update(
                    array(
                        "state" => PaymentLog::ERROR,
                        "confirm" => true,
                        "message" => "El plan no existe"
                    )
                );
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY ,
                    'message' => "Plan no existe",
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode( $request->all() ),
                    'jsonResponse' => json_encode( $formAnswer ),
                ));
                return $this->sendError( "El plan no existe" );
            }

            // punto de compra

            $paymentLogsCount = PaymentLog::where( "user_id" , $paymentLog->user_id )
                ->where("confirm" , false)
                ->whereIn("state" , [PaymentLog::TERMINADO, PaymentLog::PAGADO] )->count();

            PaymentOrderPoint::create(array(
                'payment_order_id' => $paymentOrder->id,
                'user_code' => $userCurrent->uuid,
                'sponsor_code' => "",
                'point' => $packCurrent->points,
                'payment' => true,
                'type' => 'B'
            ));

            // puntos patrocinio
            $sponsorshipPoint = SponsorshipPoint::where("pack_id" , $paymentOrder->pack_id)->first();
            // puntos residuales
            $residualPoint = ResidualPoint::first();

            if( $paymentLogsCount <= 1 ){
                // pago puntos patrocinio
                $level = $sponsorshipPoint->level1;
                $point = floatval($packCurrent->points) * floatval($level) / 100;

                PaymentOrderPoint::create(array(
                    'payment_order_id' => $paymentOrder->id,
                    'user_code' => $userCurrent->uuid,
                    'sponsor_code' => $paymentOrder->sponsor_code,
                    'point' => $point,
                    'payment' => true,
                    'type' => 'P'
                ));

            }else if( $paymentLogsCount > 1 ){
                // pago puntos residual
                $level = $residualPoint->level1;
                $point = floatval($packCurrent->points) * floatval($level) / 100;

                PaymentOrderPoint::create(array(
                    'payment_order_id' => $paymentOrder->id,
                    'user_code' => $userCurrent->uuid,
                    'sponsor_code' => $paymentOrder->sponsor_code,
                    'point' => $point,
                    'payment' => true,
                    'type' => 'R'
                ));
            }

            $_paymentOrderPoints = $this->loopTree(array(), $userCurrent->uuid);
            $sponsorshipPoint = SponsorshipPoint::where("pack_id", $paymentOrder->pack_id)->first();
            $residualPoint = ResidualPoint::first();
            $isService = ($packCurrent->category === 'SERVICIO');

            foreach ($_paymentOrderPoints as $key => $_relation) {
                $_relation = (object) $_relation;
                
                $receiverCode = $_relation->sponsor_code;
                if (empty($receiverCode)) continue;
                
                $receiver = User::where('uuid', $receiverCode)->first();
                if (!$receiver) continue;

                $level = $key + 1; // Nivel 1 = Patrocinador Directo

                // --- PUNTOS GRUPALES (Para el Rango) ---
                PaymentOrderPoint::create([
                    'payment_order_id' => $paymentOrder->id,
                    'user_code'        => $userCurrent->uuid,
                    'sponsor_code'     => $receiverCode,
                    'point'            => $packCurrent->points,
                    'payment'          => false,
                    'type'             => PaymentOrderPoint::GRUPAL,
                    'user_id'          => $receiver->id,
                    'state'            => true
                ]);

                // --- BONOS ECONÓMICOS ---
                $calculatedPoint = 0;
                $type = null;

                if ($paymentLogsCount <= 1 && $level <= 5) {
                    if ($sponsorshipPoint) {
                        $percent = $sponsorshipPoint->{"level$level"} ?? 0;
                        $calculatedPoint = floatval($packCurrent->points) * floatval($percent) / 100;
                        $type = $isService ? PaymentOrderPoint::PATROCINIO_SERVICIO : PaymentOrderPoint::PATROCINIO;
                    }
                } elseif ($paymentLogsCount > 1 && $level <= 7) {
                    if ($residualPoint) {
                        $percent = $residualPoint->{"level$level"} ?? 0;
                        $calculatedPoint = floatval($packCurrent->points) * floatval($percent) / 100;
                        $type = $isService ? PaymentOrderPoint::RESIDUAL_SERVICIO : PaymentOrderPoint::RESIDUAL;
                    }
                }

                if ($calculatedPoint > 0 && $type) {
                    PaymentOrderPoint::create([
                        'payment_order_id' => $paymentOrder->id,
                        'user_code'        => $userCurrent->uuid,
                        'sponsor_code'     => $receiverCode,
                        'point'            => $calculatedPoint,
                        'payment'          => false,
                        'type'             => $type,
                        'user_id'          => $receiver->id,
                        'state'            => true
                    ]);
                }
            }

            PaymentLog::where("payment_order_id" , $paymentOrder->id )->update(
                array(
                    "state" => PaymentLog::PAGADO,
                    "message" => "PAGADO",
                    "confirm" => true,
                    "log"   => json_encode( $formAnswer )
                )
            );

            DB::commit();
            return $this->sendResponse( array() , 'Confirm');

        }catch (Exception $e) {
            DB::rollBack();

            LogPayment::create(array(
                'type'  => LogPayment::IZIPAY ,
                'message' => $e->getMessage(),
                'apiController' => "IzipayController::notificationIpn",
                'jsonRequest' => json_encode( $request->all() ),
                'jsonResponse' => json_encode( $e ),
            ));

            return $this->sendError( $e->getMessage() );
        }
    }

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

    private function loopTree(array $a_paymentOrderPoint, string $userCode)
    {
        // 1. Buscamos por COMPRA (Tipo 'B')
        $paymentOrderPoint = PaymentOrderPoint::select('user_code', 'sponsor_code')
            ->where("user_code", $userCode)
            ->where("type", "B")
            ->where("state", true)
            ->orderBy('created_at', 'desc')
            ->first();

        // 2. Si no existe punto de compra, intentamos buscar en la orden de pago original
        if (!$paymentOrderPoint) {
            $order = PaymentOrder::whereHas('paymentLog', function($q) {
                $q->whereIn('state', [PaymentLog::PAGADO, PaymentLog::TERMINADO]);
            })->whereHas('user', function($q) use ($userCode) {
                $q->where('uuid', $userCode);
            })->first();

            if ($order) {
                $paymentOrderPoint = (object)[
                    'user_code' => $userCode,
                    'sponsor_code' => $order->sponsor_code
                ];
            }
        }

        if ($paymentOrderPoint != null && !empty($paymentOrderPoint->sponsor_code)) {
            array_push($a_paymentOrderPoint, $paymentOrderPoint);
            // Llamada recursiva
            $a_paymentOrderPoint = $this->loopTree($a_paymentOrderPoint, $paymentOrderPoint->sponsor_code);
        }

        return $a_paymentOrderPoint;
    }
}
