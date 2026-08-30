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
    private const PASSWORD_RECOVERY_TYPE = 2;
    private const PASSWORD_RECOVERY_TTL_MINUTES = 15;

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

    public function validate(Request $request, string $uuid)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|digits:4',
        ]);

        if ($validator->fails()) {
            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }

        try {
            $verification = VerificationCodeUser::whereKey($uuid)
                ->where('type', self::PASSWORD_RECOVERY_TYPE)
                ->first();

            if ($verification === null) {
                return $this->sendError('La solicitud de recuperacion no es valida.', [], 422);
            }
            if ($verification->state) {
                return $this->sendError('Este codigo ya fue utilizado.', [], 422);
            }
            if ($verification->created_at->lt(now()->subMinutes(self::PASSWORD_RECOVERY_TTL_MINUTES))) {
                $verification->update(['state' => true]);
                return $this->sendError('El codigo ha expirado. Solicite uno nuevo.', [], 422);
            }
            if (!hash_equals((string) $verification->code, (string) $request->code)) {
                return $this->sendError('El codigo es incorrecto.', [], 422);
            }

            $user = User::find($verification->user_id);
            if ($user === null) {
                $verification->update(['state' => true]);
                return $this->sendError('La cuenta asociada ya no existe.', [], 422);
            }

            $newPassword = Str::password(12);
            DB::transaction(function () use ($user, $verification, $newPassword): void {
                $user->update(['password' => bcrypt($newPassword)]);
                $verification->update(['state' => true]);
                DB::table('oauth_access_tokens')
                    ->where('user_id', $user->id)
                    ->update(['revoked' => true]);
            });

            Mail::to($user->email)->send(new PasswordUserMail([
                'url' => env('APP_URL_FRONT') . '/auth/login',
                'customer_name' => $user->name,
                'password' => $newPassword,
            ]));

            return $this->sendResponse([], 'Cuenta recuperada. Revise su correo para obtener la nueva contrasena.');
        } catch (Exception $e) {
            return $this->sendError($e->getMessage());
        }
    }

    public function recover(Request $request )
    {
        try {
            if (is_string($request->input('email'))) {
                $request->merge(['email' => Str::lower(trim($request->string('email')->toString()))]);
            }

            $validator = Validator::make($request->all(), [
                'email'     => 'required|email',
            ]);

            if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

            $userCurrent = User::where('email', $request->email)->first();

            if ($userCurrent === null) {
                return $this->sendResponse([], 'Si el correo esta registrado, recibira un codigo de recuperacion.');
            }

            $codeGenerator = new CodeGenerator();

            $validation = DB::transaction(function () use ($userCurrent, $codeGenerator) {
                VerificationCodeUser::where('user_id', $userCurrent->id)
                    ->where('type', self::PASSWORD_RECOVERY_TYPE)
                    ->where('state', false)
                    ->update(['state' => true]);

                return VerificationCodeUser::create([
                    'user_id' => $userCurrent->id,
                    'type' => self::PASSWORD_RECOVERY_TYPE,
                    'code' => $codeGenerator->generate(),
                ]);
            });

            $mailData = [
                'url' => env('APP_URL_FRONT') . '/auth/verification-code/'.$validation->id,
                'customer_name' => $userCurrent->name,
                'code' => $validation->code
            ];

            Mail::to($userCurrent->email)->send(new CreateUserMail($mailData));

            $success['validation'] = $validation->id;

            return $this->sendResponse( $success , "");

        }catch (Exception $e) {
            return $this->sendError( $e->getMessage() );
        }
    }

}
