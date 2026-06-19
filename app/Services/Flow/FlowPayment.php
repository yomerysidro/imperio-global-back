<?php
namespace App\Services\Flow;

use Exception;
use App\Services\ServiceResponse;

class FlowPayment extends ServiceResponse{

    function createEmail(
        string $commerceOrder,
        string $subject,
        float $amount,
        string $email
    )
    {
        try {

            $optional = array(
                "rut" => "9999999-9",
                "otroDato" => "otroDato"
            );
            $optional = json_encode($optional);

            //Prepara el arreglo de datos
            $params = array(
                "commerceOrder" => $commerceOrder, //rand(1100,2000),
                "subject" => $subject,
                "currency" => "PEN",
                "amount" => floatval( number_format($amount , 2)),
                "email" => $email,
                "paymentMethod" => 9,
                "urlConfirmation" => env("APP_URL") . "/api/v1/payment/flow/confirm/".$commerceOrder,
                "urlReturn" => env("APP_URL_FRONT") ."/admin/payment/confirm",
                "timeout" => 7200
                // "optional" => $optional
            );
            //Define el metodo a usar
            $serviceName = "payment/createEmail";

            // Instancia la clase FlowApi
            $flowApi = new FlowApi;
            // Ejecuta el servicio
            $response = $flowApi->send($serviceName, $params,"POST");

            //Prepara url para redireccionar el browser del pagador
            // $redirect = $response["url"] . "?token=" . $response["token"];

            return $this->response( true ,  $response , "");
        } catch (Exception $e) {
            return $this->response( false ,  $e->getCode() , $e->getCode() . " - " . $e->getMessage());
        }
    }

    function confirm( string $token)
    {
        try {

            $params = array(
                "token" => $token
            );

            $serviceName = "payment/getStatus";

            $flowApi = new FlowApi();

            $response = $flowApi->send($serviceName, $params, "GET");

            return $this->response( true ,  (object)$response , "");
        } catch (Exception $e) {
            return $this->response( false ,  $e->getCode() , $e->getCode() . " - " . $e->getMessage());
        }
    }

}
