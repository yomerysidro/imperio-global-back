<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use Illuminate\Support\Str;
use App\Models\User;
use App\Models\VerificationCodeUser;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Exception;
use App\Services\Core\CodeGenerator;
use App\Mail\CreateUserMail;
use App\Mail\PasswordUserMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\DB;
use App\Models\SponsorRelation;
use App\Services\Core\NetworkTreeService;

class LoginController extends BaseController
{
    public function verifySponsor(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }

        $sponsor = User::whereRaw('UPPER(uuid) = ?', [
            strtoupper(trim((string) $request->query('code'))),
        ])->first();

        $network = new NetworkTreeService();
        if (!$sponsor || !$network->belongsToNetwork('DOSB', $sponsor->uuid)) {
            return $this->sendError(
                'El codigo de patrocinador no es valido.',
                ['code' => ['El patrocinador no existe o no pertenece a la organizacion.']],
                422
            );
        }

        return $this->sendResponse([
            'sponsor_code' => $sponsor->uuid,
            'sponsor_name' => $sponsor->name,
        ], 'Patrocinador valido.');
    }

    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email'    => 'required|email',
            'password' => 'required'
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        $credentials = $request->only('email', 'password');

        if ( Auth::attempt($credentials) ) {
            $user             = Auth::user();

            $verificationCodeUser = VerificationCodeUser::where( "user_id" , $user->id )->where("type" , 1)->first();

            if( $verificationCodeUser != null ){
                if( $verificationCodeUser->state == false ) return $this->sendError('Error, Debes confirmar tu Correo Electrónico.' );
            }

            $_user = User::with(['file'])->find( $user->id );

            $success['name']  = $user->name;
            $success['admin']  = $user->is_admin;
            $success['token'] = $_user->createToken('accessToken')->accessToken;
            $success['photo'] = $_user->file?->path;
            $success['uuid'] =  $_user->uuid;

            return $this->sendResponse($success, 'You are successfully logged in.');
        } else {
            return $this->sendError('Usuario y contraseña no existen', ['error' => 'Unauthorised'], 404);
        }
    }

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name'     => 'required',
            'email'    => 'required|email',
            'dni'      => 'required',
            'password' => 'required|min:8',
            'sponsor_code' => 'required|string|exists:users,uuid',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $sponsor = User::whereRaw('UPPER(uuid) = ?', [
                strtoupper(trim((string) $request->sponsor_code)),
            ])->first();

            $network = new NetworkTreeService();
            if (!$sponsor || !$network->belongsToNetwork('DOSB', $sponsor->uuid)) {
                return $this->sendError(
                    'El codigo de patrocinador no pertenece a la organizacion.',
                    ['sponsor_code' => ['El codigo de patrocinador no es valido.']],
                    422
                );
            }

            $userExists = User::where("email" , $request->email)->first();

            if(  $userExists != null ) return $this->sendError( "Ese correo electronico ya existe" );

            $userExistDni = User::where("uuid" , trim($request->dni))->first();

            if(  $userExistDni != null ) return $this->sendError( "Este DNI ya existe" );

            DB::beginTransaction();

            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'uuid'     => $request->dni,
                'password' => bcrypt($request->password)
            ]);

            $codeGenerator = new CodeGenerator();

            $validation = VerificationCodeUser::create([
                'user_id' => $user->id,
                'type'  => 1,
                'code' => $codeGenerator->generate(),
                "state" => true
            ]);

            SponsorRelation::create([
                'user_code' => $user->uuid,
                'sponsor_code' => $sponsor->uuid,
                'source' => 'registration',
                'state' => true,
            ]);

            // $mailData = [
            //     'url' => env('APP_URL_FRONT') . '/auth/verification-code/'.$validation->id,
            //     'customer_name' => $request->name,
            //     'code' => $validation->code
            // ];

            // Mail::to( $request->email )->send(new CreateUserMail($mailData));

            $success['name']  = $user->name;
            $success['sponsor_code'] = $sponsor->uuid;
            $success['sponsor_name'] = $sponsor->name;
            $message          = '¡Genial! Se ha creado un usuario correctamente.';
            $success['validation'] = $validation->id;

            DB::commit();
            return $this->sendResponse($success, $message);

        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) DB::rollBack();
            $success['token'] = [];
            $message          = $e->getMessage();
            return $this->sendError( $e->getMessage() );
        }


    }

    public function validate(Request $request , string $uuid)
    {
        try {
            $validator = Validator::make($request->all(), [
                'code'     => 'required',
            ]);

            $data = (object)$request->all();

            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            $verificationCodeUser = VerificationCodeUser::find( $uuid );

            if(  $verificationCodeUser == null ) return $this->sendResponse( [], "Error, El correo no está registrado. Intentar otra vez" , false);

            if(  $verificationCodeUser->state == true ) return $this->sendResponse( [], "Error, El correo ya fue confirmado. Intentar otra vez" , false);

            if(  $verificationCodeUser->code != $data->code ) return $this->sendResponse( [], "Error, El código es incorrecto" , false);

            $message = "";
            if( $verificationCodeUser->type == 2) {
                $newPassword = $this->randomPassword();
                User::where( "id" , $verificationCodeUser->user_id )->update(
                    array("password" => bcrypt( $newPassword ) )
                );

                $userCurrent = User::find($verificationCodeUser->user_id);
                $message = "Haz recuperado tu cuenta, revisa tu correo Tú nueva contraseña es: <br> <b>".$newPassword."</b>";

                $mailData = [
                    'url' => env('APP_URL_FRONT') . '/auth/login',
                    'customer_name' => $userCurrent->name,
                    'password' => $newPassword
                ];

                Mail::to( $userCurrent->email )->send(new PasswordUserMail($mailData));

            }else{
                $message = "Felicidades, su cuenta se ha creado con éxito. Continúe y bloguéate a tu cuenta.";
            }

            VerificationCodeUser::where("id" , $uuid )->update(
                array( "state" => true )
            );

            return $this->sendResponse( [] , $message );

        }catch (Exception $e) {
            return $this->sendError( $e->getMessage() );
        }
    }

    public function recover(Request $request )
    {
        try {
            $validator = Validator::make($request->all(), [
                'email'     => 'required|email',
            ]);

            $data = (object)$request->all();

            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            $userCurrent = User::where( "email" , $request->email )->first();

            if(  $userCurrent == null ) return $this->sendResponse( [], "Error, El correo no está registrado. Intentar otra vez" , false);

            $codeGenerator = new CodeGenerator();

            $validation = VerificationCodeUser::create([
                'user_id' => $userCurrent->id,
                'type'  => 2,
                'code' => $codeGenerator->generate(),
            ]);

            $mailData = [
                'url' => env('APP_URL_FRONT') . '/auth/verification-code/'.$validation->id,
                'customer_name' => $userCurrent->name,
                'code' => $validation->code
            ];

            Mail::to($request->email)->send(new CreateUserMail($mailData));

            $success['validation'] = $validation->id;

            return $this->sendResponse( $success , "");

        }catch (Exception $e) {
            return $this->sendError( $e->getMessage() );
        }
    }

    private function randomPassword() {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
        $pass = array(); //remember to declare $pass as an array
        $alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
        for ($i = 0; $i < 8; $i++) {
            $n = rand(0, $alphaLength);
            $pass[] = $alphabet[$n];
        }
        return implode($pass); //turn the array into a string
    }

    public function resetPassword( Request $request )
    {
        try {

            $validator = Validator::make($request->all(), [
                'email'    => 'required|email',
                'password' => 'required|min:6'
            ]);

            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            $userExists = User::where("email" , $request->email)->first();

            if(  $userExists == null ) return $this->sendError( "Ese correo electronico no existe" );

            User::where( "id" , $userExists->id )->update(
                array("password" => bcrypt( $request->password ) )
            );

            return $this->sendResponse( 1 , 'User');
        } catch (Exception $e) {
            return $this->sendError( $e->getMessage() );
        }
    }
}
