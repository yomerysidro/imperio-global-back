<?php

namespace App\Services;

class ServiceResponse
{
    public function response( bool $success , $data , string $message)
    {
        return (object) array(
            "success" => $success,
            "data" => $data ,
            "message" => $message ,
        );
    }
}
