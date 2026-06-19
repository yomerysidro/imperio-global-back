<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Pack;
use App\Models\File;
use Illuminate\Support\Facades\Auth;
use App\Models\PaymentLog;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderPoint;
use App\Models\SponsorshipPoint;
use App\Models\ResidualPoint;
use App\Models\Option;
use App\Models\LogPayment;
use App\Models\PaymentProductOrder;
use App\Models\PaymentProductOrderPoint;
use App\Services\Flow\FlowPayment;
use App\Services\Core\Calculator;


use Exception;

class PaymentOrderController extends BaseController
{
    //

    private $flowPayment;
    private $calculator;

    public function __construct()
    {
        $this->flowPayment = new FlowPayment();
        $this->calculator = new Calculator();
    }

    /**
     * FLOW ============
     *
     */

    public function flowCreate(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'packId' => 'required|exists:packs,id',
            'sponsorId'    => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {

            DB::beginTransaction();

            $userId = Auth::id();
            $_user = User::find($userId);
            if ($_user->is_admin) return $this->sendError('Este Usuario no puede realizar una compra');

            $paymentLogs = PaymentLog::where("user_id", $userId)
                ->where("confirm", true)
                ->whereIn("state", [PaymentLog::PAGADO])->count();

            if ($paymentLogs > 0) return $this->sendError('Ya existe una subscripcion activa');

            $dataBody = (object) $request->all();

            $sponsor = User::where("uuid", $dataBody->sponsorId)->first();

            if ($sponsor == null) return $this->sendError('Codigo de Patronisador no existe.');

            $packCurrent = Pack::find($dataBody->packId);

            if ($packCurrent == null) return $this->sendError("El plan no existe");

            $option = Option::where("option_key", "comision")->first();

            $totalAmount = $option == null ? $packCurrent->price : (floatval($packCurrent->price) + (($packCurrent->price) * (floatval($option->option_value)) / 100));

            $paymentOrder = PaymentOrder::create(
                array(
                    'currency' => "PEN",
                    'amount' => floatval(number_format($totalAmount, 2)),
                    'sponsor_code' => $dataBody->sponsorId,
                    'pack_id' => $packCurrent->id,
                )
            );

            $userCurrent = User::find($userId);

            $flowPaymentResult = $this->flowPayment->createEmail(
                $paymentOrder->id,
                "Pago " . $packCurrent->title,
                floatval($packCurrent->price),
                $userCurrent->email
            );

            if (!$flowPaymentResult->success) return $this->sendError($flowPaymentResult->message);

            PaymentOrder::where("id", $paymentOrder->id)->update(array("token" => $flowPaymentResult->data["token"]));

            $paymentLog = PaymentLog::create(
                array(
                    'payment_order_id' => $paymentOrder->id,
                    "confirm" => false,
                    'user_id' => $userId,
                    "state" => PaymentLog::PENDIENTEPAGO,
                )
            );

            LogPayment::create(array(
                'type'  => LogPayment::FLOW,
                'message' => "",
                'apiController' => "PaymentOrderController::flowCreate",
                'jsonRequest' => "",
                "log_order_id" => $paymentOrder->id
            ));

            DB::commit();

            return $this->sendResponse($flowPaymentResult->data["url"] . "?token=" . $flowPaymentResult->data["token"], 'Payment');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage());
        }
    }

    public function flowConfirm(Request $request, string $uuid)
    {

        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();

            $logPayment = LogPayment::where("log_order_id", $uuid)->first();

            if ($logPayment == null) return $this->sendError("No se encuentra log", [], 422);

            $paymentOrder = null;

            if ($logPayment->type == LogPayment::FLOW) {
                $paymentOrder = PaymentOrder::find($uuid);
            } else if ($logPayment->type == LogPayment::FLOWPRODUCT) {
                $paymentOrder = PaymentProductOrder::find($uuid);
            }

            if ($paymentOrder == null) {
                DB::rollBack();

                if ($logPayment->type == LogPayment::FLOW) {
                    PaymentLog::where("payment_order_id", $uuid)->update(
                        array(
                            "state" => PaymentLog::ERROR,
                            "confirm" => true,
                            "message" => "No existe la orden"
                        )
                    );
                } else if ($logPayment->type == LogPayment::FLOWPRODUCT) {
                    PaymentProductOrder::where("id", $uuid)->update(
                        array(
                            "state" => PaymentProductOrder::ERROR,
                        )
                    );
                }

                LogPayment::where("log_order_id", $uuid)->update(array(
                    'message' => "No existe la orden => " . $uuid,
                    'apiController' => "PaymentOrderController::flowConfirm",
                    'jsonRequest' => json_encode($request->all()),
                ));
                return $this->sendError("No existe la orden", [], 422);
            }

            if (!isset($dataBody->token)) {
                DB::rollBack();
                if ($logPayment->type == LogPayment::FLOW) {
                    PaymentLog::where("payment_order_id", $uuid)->update(
                        array(
                            "state" => PaymentLog::ERROR,
                            "confirm" => true,
                            "message" => "No se recibio el token"
                        )
                    );
                } else if ($logPayment->type == LogPayment::FLOWPRODUCT) {
                    PaymentProductOrder::where("id", $uuid)->update(
                        array(
                            "state" => PaymentProductOrder::ERROR,
                        )
                    );
                }

                LogPayment::where("id", $logPayment->id)->update(array(
                    'message' => "No se recibio el token => " . $uuid,
                    'apiController' => "PaymentOrderController::flowConfirm",
                    'jsonRequest' => json_encode($request->all()),
                ));

                return $this->sendError("No se recibio el token", [], 422);
            }


            /*
            1 pendiente de pago
            2 pagada
            3 rechazada
            4 anulada
            5 error
            */

            $flowPaymentResult = $this->flowPayment->confirm($dataBody->token);

            if (!$flowPaymentResult->success) {
                DB::rollBack();

                if ($logPayment->type == LogPayment::FLOW) {
                    PaymentLog::where("payment_order_id", $uuid)->update(
                        array(
                            "state" => PaymentLog::ERROR,
                            "confirm" => true,
                            "message" => $flowPaymentResult->message
                        )
                    );
                } else if ($logPayment->type == LogPayment::FLOWPRODUCT) {
                    PaymentProductOrder::where("id", $uuid)->update(
                        array(
                            "state" => PaymentProductOrder::ERROR,
                        )
                    );
                }

                LogPayment::where("id", $logPayment->id)->update(array(
                    'message' => $flowPaymentResult->message,
                    'apiController' => "PaymentOrderController::flowConfirm",
                    'jsonRequest' => json_encode($dataBody->token),
                    'jsonResponse' => json_encode($flowPaymentResult)
                ));
                return $this->sendError($flowPaymentResult->message, [], 422);
            }

            /*{
                "flowOrder": 3567899,
                "commerceOrder": "sf12377",
                "requestDate": "2017-07-21 12:32:11",
                "status": 1,
                "subject": "game console",
                "currency": "CLP",
                "amount": 12000,
                "payer": "pperez@gamil.com",
                "optional": {
                  "RUT": "7025521-9",
                  "ID": "899564778"
                },
                "pending_info": {
                  "media": "Multicaja",
                  "date": "2017-07-21 10:30:12"
                },
                "paymentData": {
                  "date": "2017-07-21 12:32:11",
                  "media": "webpay",
                  "conversionDate": "2017-07-21",
                  "conversionRate": 1.1,
                  "amount": 12000,
                  "currency": "CLP",
                  "fee": 551,
                  "balance": 11499,
                  "transferDate": "2017-07-24"
                },
                "merchantId": "string"
              }
            */

            if ($flowPaymentResult->data->status == PaymentLog::PENDIENTEPAGO) {
                return $this->sendResponse(array(), 'Pendiente');
            }

            if ($flowPaymentResult->data->status == PaymentLog::PAGADO) {

                if ($logPayment->type == LogPayment::FLOW) {
                    $paymentLog = PaymentLog::where("payment_order_id", $uuid)->first();

                    $userCurrent = User::find($paymentLog->user_id);

                    $paymentLogsCount = PaymentLog::where("user_id", $paymentLog->user_id)
                        ->where("confirm", false)
                        ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])->count();

                    $packCurrent = Pack::find($paymentOrder->pack_id);

                    if ($packCurrent == null) {
                        DB::rollBack();
                        PaymentLog::where("payment_order_id", $uuid)->update(
                            array(
                                "state" => PaymentLog::ERROR,
                                "confirm" => true,
                                "message" => "El plan no existe"
                            )
                        );
                        return $this->sendError("El plan no existe", [], 422);
                    }

                    $this->confirmPoint($paymentOrder, $userCurrent, $packCurrent);

                    PaymentLog::where("payment_order_id", $uuid)->update(
                        array(
                            "state" => $flowPaymentResult->data->status,
                            "message" => "",
                            "confirm" => true,
                            "log"   => json_encode($flowPaymentResult->data)
                        )
                    );
                } else if ($logPayment->type == LogPayment::FLOWPRODUCT) {
                    // PaymentProductOrder
                    // $userCurrent = User::find( $paymentOrder->user_id );
                    PaymentProductOrderPoint::create(
                        array(
                            'payment_product_order_id'  => $uuid,
                            'user_id'                   => $paymentOrder->user_id,
                            'points'                    => $paymentOrder->points,
                            'state'                     => true
                        )
                    );

                    PaymentProductOrder::where("id", $uuid)->update(
                        array(
                            "state" => PaymentProductOrder::PAGADO,
                            "token" => $dataBody->token,
                        )
                    );
                }
            }

            DB::commit();
            return $this->sendResponse(array(), 'Confirm');
        } catch (Exception $e) {
            DB::rollBack();
            PaymentLog::where("payment_order_id", $uuid)->update(
                array(
                    "state" => 5,
                    "confirm" => true,
                    "message" => $e->getMessage()
                )
            );
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    /**
     * OFFLINE ============
     *
     */
    public function flowCreateOffline(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'packId' => 'required|exists:packs,id',
            'sponsorId'    => 'required',
        ]);

        if ($validator->fails()) {

            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }

        try {

            DB::beginTransaction();

            $userId = Auth::id();

            $paymentLogs = PaymentLog::where("user_id", $userId)->where("confirm", true)->where("state", 2)->count();

            if ($paymentLogs > 0) return $this->sendError('Ya existe una subscripcion activa');

            $dataBody = (object) $request->all();

            $sponsor = User::where("uuid", 'like', $dataBody->sponsorId)->first();

            if ($sponsor == null) return $this->sendError('Codigo de Patronisador no existe.');

            $packCurrent = Pack::find($dataBody->packId);

            if ($packCurrent == null) return $this->sendError("El plan no existe");

            $option = Option::where("option_key", "comision")->first();

            $totalAmount = $option == null ? $packCurrent->price : (floatval($packCurrent->price) + (($packCurrent->price) * (floatval($option->option_value)) / 100));

            $paymentOrder = PaymentOrder::create(
                array(
                    'currency' => "PEN",
                    'amount' => floatval(number_format($totalAmount, 2)),
                    'sponsor_code' => $dataBody->sponsorId,
                    'pack_id' => $packCurrent->id,
                )
            );

            $userCurrent = User::find($userId);

            PaymentOrder::where("id", $paymentOrder->id)->update(array("token" => "NOT_TOKEN"));

            $paymentLog = PaymentLog::create(
                array(
                    'payment_order_id' => $paymentOrder->id,
                    "confirm" => false,
                    "state" => 1,
                    'user_id' => $userId,
                )
            );

            DB::commit();

            return $this->sendResponse($paymentOrder->id, 'Payment');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage());
        }
    }

    public function flowCreateConfirmOffline(Request $request, string $uuid)
    {
        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();

            if (!isset($dataBody->token)) {
                DB::rollBack();
                PaymentLog::where("payment_order_id", $uuid)->update(
                    array(
                        "state" => 5,
                        "confirm" => true,
                        "message" => "No se recibio el token"
                    )
                );

                return $this->sendError("No se recibio el token", [], 422);
            }

            $paymentOrder = PaymentOrder::find($uuid);

            if ($paymentOrder == null) {
                DB::rollBack();
                PaymentLog::where("payment_order_id", $uuid)->update(
                    array(
                        "state" => 5,
                        "confirm" => true,
                        "message" => "No existe la orden"
                    )
                );
                return $this->sendError("No existe la orden", [], 422);
            }

            $paymentLog = PaymentLog::where("payment_order_id", $uuid)->first();

            $userCurrent = User::find($paymentLog->user_id);

            $paymentLogsCount = PaymentLog::where("user_id", $paymentLog->user_id)
                ->where("confirm", false)
                ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])->count();

            $packCurrent = Pack::find($paymentOrder->pack_id);

            if ($packCurrent == null) {
                DB::rollBack();
                PaymentLog::where("payment_order_id", $uuid)->update(
                    array(
                        "state" => 5,
                        "confirm" => true,
                        "message" => "El plan no existe"
                    )
                );
                return $this->sendError("El plan no existe", [], 422);
            }

            $this->confirmPoint($paymentOrder, $userCurrent, $packCurrent);

            PaymentLog::where("payment_order_id", $uuid)->update(
                array(
                    "state" => PaymentLog::PAGADO,
                    "message" => "",
                    "confirm" => true,
                    "log"   => json_encode(array())
                )
            );

            DB::commit();
            return $this->sendResponse(array(), 'Confirm');
        } catch (Exception $e) {
            DB::rollBack();
            PaymentLog::where("payment_order_id", $uuid)->update(
                array(
                    "state" => 5,
                    "confirm" => true,
                    "message" => $e->getMessage()
                )
            );
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function cancelAllPayment()
    {
        try {
            PaymentLog::where("state", PaymentLog::PAGADO)->update(
                array("state" => PaymentLog::TERMINADO)
            );

            PaymentOrderPoint::where("state", true)->update(
                array("state" => false)
            );

            return $this->sendResponse("Reiniciado", 'Confirm');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function cancelAllPaymentByUser(string $code)
    {
        try {

            $user = User::where("uuid", 'like', $code)->first();

            PaymentLog::where("state", PaymentLog::PAGADO)->where("user_id", $user->id)->update(
                array("state" => PaymentLog::TERMINADO)
            );

            PaymentOrderPoint::where("state", true)->where("user_code", $code)->update(
                array("state" => false)
            );

            return $this->sendResponse("Reiniciado Usuario", 'Confirm');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function deleteAllPaymentByUser(string $code)
    {
        try {

            $user = User::where("uuid", 'like', $code)->first();

            PaymentLog::where("state", PaymentLog::PAGADO)->where("user_id", $user->id)->delete();

            PaymentOrderPoint::where("state", true)->where("user_code", $code)->delete();

            return $this->sendResponse("Eliminado Usuario", 'Confirm');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    /**
     * IZIPAY ============
     *
     */

    public function createPaymentIzipay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'packId' => 'required|exists:packs,id',
            'sponsorId'    => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();

            $userId = Auth::id();
            $_user = User::find($userId);
            if ($_user->is_admin) return $this->sendError('Este Usuario no puede realizar una compra');
            $paymentLogs = PaymentLog::where("user_id", $userId)
                ->where("confirm", true)
                ->whereIn("state", [PaymentLog::PAGADO])->count();

            if ($paymentLogs > 0) return $this->sendError('Ya existe una subscripcion activa');

            $dataBody = (object) $request->all();

            $sponsor = User::where("uuid", $dataBody->sponsorId)->first();

            if ($sponsor == null) return $this->sendError('Codigo de Patronisador no existe.');

            $packCurrent = Pack::find($dataBody->packId);

            if ($packCurrent == null) return $this->sendError("El plan no existe");

            $option = Option::where("option_key", "comision")->first();

            $totalAmount = $option == null ? $packCurrent->price : (floatval($packCurrent->price) + (floatval($packCurrent->price) * (floatval($option->option_value)) / 100));

            $_orderId = uniqid($packCurrent->title);

            $cadena_sin_tildes = transliterator_transliterate('Any-Latin; Latin-ASCII;', $_orderId);

            // Ahora, removemos todo lo que no sea una letra, número o espacio.
            $cadena_limpia = preg_replace('/[^A-Za-z0-9\s]/', '', $cadena_sin_tildes);

            // Opcional: reemplazar espacios múltiples por uno solo
            $orderId = preg_replace('/\s+/', ' ', $cadena_limpia);

            $paymentOrder = PaymentOrder::create(
                array(
                    'currency' => "PEN",
                    'amount' => $totalAmount,
                    'sponsor_code' => $dataBody->sponsorId,
                    'pack_id' => $packCurrent->id,
                    "token" => $orderId
                )
            );

            $userCurrent = User::find($userId);

            $store = array(
                "amount" => floatval($totalAmount) * 100,
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

            if ($response['status'] != 'SUCCESS') {
                DB::rollBack();
                $error = $response['answer'];
                return $this->sendError($error['errorMessage'], $response);
            }

            $formToken = $response["answer"]["formToken"];

            DB::commit();

            return $this->sendResponse(array(
                "formToken" => $formToken,
                "publicKey" => env('IZIPAY_PUBLIC_KEY'),
                "endpoint" => env('IZIPAY_CLIENT_ENDPOINT'),
                "ssss" => array(
                    "packCurrent" => $packCurrent->price,
                    "totalAmount" => $totalAmount
                )
            ), 'Payment');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), "Error api");
        }
    }

    public function confirmPaymentIzipay(Request $request)
    {
        try {

            $validator = Validator::make($request->all(), [
                'clientAnswer' => 'required',
                'hash'    => 'required',
                'hashAlgorithm' => 'required',
                'rawClientAnswer'  => 'required'
            ]);

            if ($validator->fails()) {
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY,
                    'message' => "Error de validacion",
                    'apiController' => "IzipayController::confirmPayment",
                    'jsonRequest' => json_encode($request->all()),
                    'jsonResponse' => json_encode($validator->errors()),
                ));
                return $this->sendError('Error de validacion.', $validator->errors(), 422);
            }

            if (!$this->checkHash(
                $request->input("hashAlgorithm"),
                $request->input("rawClientAnswer"),
                $request->input("hash")
            )) {
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY,
                    'message' => "Firma inválida -" . $request->input("hash"),
                    'apiController' => "IzipayController::confirmPayment",
                    'jsonRequest' => json_encode($request->all()),
                ));
                return $this->sendError('Firma inválida');
            }

            DB::beginTransaction();

            $formAnswer = (object) $request->input('clientAnswer');
            $orderId = $formAnswer->orderDetails['orderId'];

            $paymentOrder = PaymentOrder::where("token", $orderId)->first();

            if ($paymentOrder == null) {
                DB::rollBack();
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY,
                    'message' => "Payment Order no existe",
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode($request->all()),
                    'jsonResponse' => json_encode($formAnswer),
                ));
                return $this->sendError("Payment Order no existe");
            }

            $paymentLog = PaymentLog::where("payment_order_id", $paymentOrder->id)->first();

            if ($paymentLog == null) {
                DB::rollBack();
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY,
                    'message' => "Payment Log no existe su orden => " . $orderId,
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode($request->all()),
                    'jsonResponse' => json_encode($formAnswer),
                ));
                return $this->sendError("Payment Log no existe su orden => " . $orderId);
            }

            $userCurrent = User::find($paymentLog->user_id);

            $packCurrent = Pack::find($paymentOrder->pack_id);

            if ($packCurrent == null) {
                DB::rollBack();
                PaymentLog::where("payment_order_id", $paymentOrder->id)->update(
                    array(
                        "state" => PaymentLog::ERROR,
                        "confirm" => true,
                        "message" => "El plan no existe"
                    )
                );
                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAY,
                    'message' => "Plan no existe",
                    'apiController' => "IzipayController::notificationIpn",
                    'jsonRequest' => json_encode($request->all()),
                    'jsonResponse' => json_encode($formAnswer),
                ));
                return $this->sendError("El plan no existe");
            }

            $this->confirmPoint($paymentOrder, $userCurrent, $packCurrent);

            PaymentLog::where("payment_order_id", $paymentOrder->id)->update(
                array(
                    "state" => PaymentLog::PAGADO,
                    "message" => "PAGADO",
                    "confirm" => true,
                    "log"   => json_encode($formAnswer)
                )
            );

            DB::commit();
            return $this->sendResponse(array(), 'Confirm');
        } catch (Exception $e) {
            DB::rollBack();

            LogPayment::create(array(
                'type'  => LogPayment::IZIPAY,
                'message' => $e->getMessage(),
                'apiController' => "IzipayController::notificationIpn",
                'jsonRequest' => json_encode($request->all()),
                'jsonResponse' => json_encode($e),
            ));

            return $this->sendError($e->getMessage());
        }
    }

    public function paymentOffline(Request $request)
    {

        $validator = Validator::make($request->all(), [
            'packId' => 'required|exists:packs,id',
            'sponsorId'    => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();
            $userId = Auth::id();
            $currentPayment = PaymentLog::where("user_id", $userId)
                ->orderBy('created_at', 'desc')
                ->first();

            if ($currentPayment?->state == PaymentLog::PAGADO) return $this->sendError("Hay un Plan activo", [], 402);

            $sponsor = User::where("uuid", $dataBody->sponsorId)->first();
            if ($sponsor == null) return $this->sendError('Codigo de Patronisador no existe.');

            // verificar plan
            $packCurrent = Pack::find($dataBody->packId);

            if ($packCurrent == null) return $this->sendError("El plan no existe");

            $option = Option::where("option_key", "comision")->first();
            // aumentar comision
            $totalAmount = $option == null ? $packCurrent->price : (floatval($packCurrent->price) + (($packCurrent->price) * (floatval($option->option_value)) / 100));

            $paymentOrder = PaymentOrder::create(
                array(
                    'currency' => "PEN",
                    'amount' => floatval(number_format($totalAmount, 2)),
                    'sponsor_code' => $dataBody->sponsorId,
                    'pack_id' => $packCurrent->id,
                )
            );

            $paymentLog = PaymentLog::create(
                array(
                    'payment_order_id' => $paymentOrder->id,
                    "confirm" => true,
                    'user_id' => $userId,
                    "state" => PaymentLog::PAGADO,
                )
            );

            $userCurrent = User::find($userId);

            LogPayment::create(array(
                'type'  => LogPayment::OFFLINE,
                'message' => "Offline",
                'apiController' => "PaymentOrderController::paymentOffline",
                'jsonRequest' => "",
                "log_order_id" => $paymentOrder->id
            ));

            $this->confirmPoint($paymentOrder, $userCurrent, $packCurrent);

            DB::commit();
            return $this->sendResponse(array(), 'Offline');
        } catch (Exception $e) {
            DB::rollBack();

            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function paymentCash(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'packId' => 'required|exists:packs,id',
            'sponsorId'    => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();
            $userId = Auth::id();

            $sponsor = User::where("uuid", $dataBody->sponsorId)->first();

            if ($sponsor == null) return $this->sendError('Codigo de Patronisador no existe.');

            $packCurrent = Pack::find($dataBody->packId);

            if ($packCurrent == null) return $this->sendError("El plan no existe");

            $option = Option::where("option_key", "comision")->first();

            $totalAmount = $option == null ? $packCurrent->price : (floatval($packCurrent->price) + (floatval($packCurrent->price) * (floatval($option->option_value)) / 100));

            $orderId = uniqid($packCurrent->title);

            $userCurrent = User::find($userId);

            $paymentOrder = PaymentOrder::create(
                array(
                    'currency' => "PEN",
                    'amount' => $totalAmount,
                    'sponsor_code' => $dataBody->sponsorId,
                    'pack_id' => $packCurrent->id,
                    "token" => $orderId
                )
            );

            $paymentLog = PaymentLog::create(
                array(
                    'payment_order_id' => $paymentOrder->id,
                    "confirm" => false,
                    'user_id' => $userId,
                    "state" => PaymentLog::PREORDER,
                )
            );

            // $this->confirmPoint( $paymentOrder , $userCurrent , $packCurrent);

            // PaymentLog::where("payment_order_id" , $paymentOrder->id )->update(
            //     array(
            //         "state" => PaymentLog::PAGADO,
            //         "message" => "PAGADO",
            //         "confirm" => true,
            //         "log"   => ""
            //     )
            // );

            DB::commit();
            return $this->sendResponse(array(), 'paymentCash');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    public function paymentCashConfirm(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'sponsorId'    => 'required',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            DB::beginTransaction();
            $dataBody = (object) $request->all();
            $userId = Auth::id();

            $userSponsor = User::where("uuid", $dataBody->sponsorId)->first();

            if ($userSponsor == null) return $this->sendError('Codigo de Patronisador no existe.');

            $option = Option::where("option_key", "comision")->first();

            $paymentLog = PaymentLog::where("state", PaymentLog::PREORDER)->where('user_id', $userSponsor->id)->first();

            if ($paymentLog == null) return $this->sendError('No tienes una pre orden activa.');

            $paymentOrder = PaymentOrder::where("id", $paymentLog->payment_order_id)->first();

            if ($paymentOrder == null) return $this->sendError('No tienes una pre orden activa.');

            $packCurrent = Pack::find($paymentOrder->pack_id);

            $this->confirmPoint($paymentOrder, $userSponsor, $packCurrent);

            PaymentLog::where("payment_order_id", $paymentOrder->id)->update(
                array(
                    "state" => PaymentLog::PAGADO,
                    "message" => "PAGADO",
                    "confirm" => true,
                    "log"   => ""
                )
            );

            DB::commit();

            return $this->sendResponse(array(), 'paymentCashConfirm');
        } catch (Exception $e) {
            DB::rollBack();
            return $this->sendError($e->getMessage(), [], 402);
        }
    }

    /**
     * functions ============
     *
     */

    // REEMPLAZA ESTA FUNCIÓN EN UserController.php
    // En PaymentOrderController.php — reemplaza loopTree()
    private function loopTree(array $a_paymentOrderPoint, string $userCode)
    {
        $paymentOrderPoint = PaymentOrderPoint::select('user_code', 'sponsor_code')
            ->distinct()
            ->where("user_code", $userCode)
            ->where("type", PaymentOrderPoint::COMPRA)  // ← igual que el fix anterior
            ->where("payment", 1)
            ->where("state", true)
            ->orderBy('created_at', 'desc')
            ->first();

        if ($paymentOrderPoint != null && !empty($paymentOrderPoint->sponsor_code)) {
            array_push($a_paymentOrderPoint, $paymentOrderPoint);
            $a_paymentOrderPoint = $this->loopTree($a_paymentOrderPoint, $paymentOrderPoint->sponsor_code);
        }

        return $a_paymentOrderPoint;
    }

    private function confirmPoint($paymentOrder, $userCurrent, $packCurrent)
    {

        $paymentLogsCount = PaymentLog::where("user_id", $userCurrent->id)
            ->whereIn("state", [PaymentLog::TERMINADO, PaymentLog::PAGADO])->count();

        // puntos patrocinio
        $sponsorshipPoint = SponsorshipPoint::where("pack_id", $paymentOrder->pack_id)->first();
        // puntos residuales
        $residualPoint = ResidualPoint::first();

        if ($paymentLogsCount == 0) {

            // punto de compra
            PaymentOrderPoint::create(array(
                'payment_order_id' => $paymentOrder->id,
                'user_code' => $userCurrent->uuid,
                'sponsor_code' => $paymentOrder->sponsor_code,
                'point' => $packCurrent->points,
                'payment' => true,
                'type' => PaymentOrderPoint::COMPRA,
                'user_id' => $userCurrent->id
            ));

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
        } else if ($paymentLogsCount > 0) {
            $option = Option::where("option_key", 'reactive_point')->first();
            // punto de compra
            PaymentOrderPoint::create(array(
                'payment_order_id' => $paymentOrder->id,
                'user_code' => $userCurrent->uuid,
                'sponsor_code' => $paymentOrder->sponsor_code,
                'point' => floatval($option->option_value ?? "200"),
                'payment' => true,
                'type' => PaymentOrderPoint::COMPRA,
                'user_id' => $userCurrent->id
            ));
            // pago puntos residual
            $level = $residualPoint->level1;

            $option = Option::where("option_key", 'point_residual')->first();
            // floatval($packCurrent->points)
            $point = (floatval($option->option_value)) * floatval($level) / 100;

            //-- se paso a la opcion de compras
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

        // PaymentOrderPoint::create(array(
        //     'payment_order_id' => $paymentOrder->id,
        //     'user_code' => $userCurrent->uuid,
        //     'sponsor_code' => $paymentOrder->sponsor_code,
        //     'point' => $point,
        //     'payment' => true,
        //     'type' => PaymentOrderPoint::GRUPAL,
        //     'user_id' => $userCurrent->id
        // ));

        $_paymentOrderPoints = $this->loopTree(array(), $userCurrent->uuid);

        $sponsorshipPoint = SponsorshipPoint::where("pack_id", $paymentOrder->pack_id)->first();

        $residualPoint = ResidualPoint::first();

        if ($paymentLogsCount == 0) {
            foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                if ($key == 0) continue;
                // $key ya vale 1 para el patrocinador directo → level1
                if ($key > 5) break;  // hasta nivel 5
                $level = $sponsorshipPoint->{'level' . $key};  // level1 para el directo ← CORRECCIÓN
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
        } else
        if ($paymentLogsCount > 0) {
            foreach ($_paymentOrderPoints as $key => $_paymentOrderPoint) {
                $_paymentOrderPoint = (object) $_paymentOrderPoint;
                if ($key == 0) continue;
                $key++;
                if ($key > 7) break;
                $level = $residualPoint->{'level' . ($key)};
                $option = Option::where("option_key", 'point_residual')->first();
                $point = floatval($option->option_value) * floatval($level) / 100;

                //-- se paso a la opcion de compras
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
            if ($paymentLogsCount > 0) {
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

    private function checkHash($algorithm, $answer, $hash)
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
        $krAnswer = str_replace('\/', '/',  $answer);
        $calculateHash = hash_hmac("sha256", $krAnswer, $key);

        return ($calculateHash == $hash);
    }

    private function reactivarPlan($userId)
    {
        // ver total de puntos
        $paymentOrderPoints = PaymentOrderPoint::with(['paymentOrder.paymentLog'])->where('state', true)->get();
        $paymentProductOrderPoints = PaymentProductOrderPoint::where("user_id", $userId)->where("state", true)->get();

        $userCurrent = User::find($userId);

        $calculatorPoint = $this->calculator->points($userCurrent->uuid, $paymentOrderPoints, $paymentProductOrderPoints);
        $totalPoints = $calculatorPoint->patrocinio + $calculatorPoint->residual + $calculatorPoint->compra->total_puntos + $calculatorPoint->pointGroup + $calculatorPoint->personal;
        $maxPointsProduct = Option::where("option_key", "max_points_product")->first();

        $paymentLog = PaymentLog::with(['paymentOrder.pack'])
            ->where("user_id",  $userId)
            ->where(function ($query) {
                $query->where('state', PaymentLog::TERMINADO);
            })
            ->orderBy('created_at', 'desc')
            ->first();


        if ($totalPoints >= floatval($maxPointsProduct->option_value)) {
            if ($paymentLog != null) {

                // === REACTIVAR EL PLAN
                $_paymentOrder = PaymentOrder::find($paymentLog->payment_order_id);
                $paymentOrder = PaymentOrder::create(
                    array(
                        'currency' => "PEN",
                        'amount' => floatval(number_format(0, 2)),
                        'sponsor_code' => $_paymentOrder->sponsor_code,
                        'pack_id' => $_paymentOrder->pack_id,
                    )
                );

                $_paymentLog = PaymentLog::create(
                    array(
                        'payment_order_id' => $paymentOrder->id,
                        "confirm" => true,
                        'user_id' => $userId,
                        "state" => PaymentLog::PAGADO,
                    )
                );

                LogPayment::create(array(
                    'type'  => LogPayment::IZIPAYPRODUCT,
                    'message' => "IZIPAYPRODUCT",
                    'apiController' => "PaymentProductOrderController::izipayConfirmPayment",
                    'jsonRequest' => "",
                    "log_order_id" => $paymentOrder->id
                ));
            }
        }
    }
}
