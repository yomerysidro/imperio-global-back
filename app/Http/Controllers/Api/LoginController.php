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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
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
        if (!$sponsor || !$network->belongsToNetwork(app(\App\Services\Core\CorporateRootService::class)->uuid(), $sponsor->uuid)) {
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
            'dni'      => 'required|string|max:100',
            'password' => 'required|min:8',
            'sponsor_code' => 'required|string|exists:users,uuid',
        ]);

        if ($validator->fails()) return $this->sendError('Error de validacion.', $validator->errors(), 422);

        try {
            $sponsor = User::whereRaw('UPPER(uuid) = ?', [
                strtoupper(trim((string) $request->sponsor_code)),
            ])->first();

            $network = new NetworkTreeService();
            if (!$sponsor || !$network->belongsToNetwork(app(\App\Services\Core\CorporateRootService::class)->uuid(), $sponsor->uuid)) {
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
            $network->lockRootForMutation();
            if (User::where('email', $request->email)->orWhere('uuid', trim($request->dni))->exists()) {
                throw new \RuntimeException('El correo o DNI ya esta registrado.');
            }
            $sponsor = User::whereRaw('UPPER(uuid) = ?', [strtoupper(trim((string) $request->sponsor_code))])->first();
            if (!$sponsor || !$network->belongsToNetwork(app(\App\Services\Core\CorporateRootService::class)->uuid(), $sponsor->uuid)) {
                throw new \RuntimeException('El patrocinador dejó de pertenecer a la red corporativa.');
            }

            $user = User::create([
                'name'     => $request->name,
                'email'    => $request->email,
                'uuid'     => trim($request->dni),
                'password' => bcrypt($request->password)
            ]);

            $codeGenerator = new CodeGenerator();

            $validation = VerificationCodeUser::create([
                'user_id' => $user->id,
                'type'  => 1,
                'code' => $codeGenerator->generate(),
                "state" => true
            ]);

            app(\App\Services\Core\AffiliationService::class)->attach($user, $sponsor->uuid, 'registration');

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

    private function recoveryKey(string $uuid): string
    {
        return 'password-recovery:'.$uuid;
    }

    private function digest(string $value): string
    {
        return hash_hmac('sha256', $value, config('app.key'));
    }

    private function requireRecoveryCache(): void
    {
        $driver = config('cache.stores.'.config('cache.default').'.driver');
        if ($driver === 'file') {
            $this->requireRecoveryFileCache();
            return;
        }
        if (!app()->environment('testing') && !in_array($driver, ['database', 'redis', 'memcached', 'dynamodb'], true)) {
            throw new \RuntimeException('Recovery requires persistent shared cache.');
        }
    }

    private function requireRecoveryFileCache(): void
    {
        // This is an operator assertion: filesystem probes cannot detect whether
        // other PHP workers or future deployments mount the same persistent disk.
        if (config('password_recovery.file_cache_shared') !== true) {
            throw new \RuntimeException('Recovery file cache requires persistent storage shared by all recovery workers.');
        }

        $settings = config('cache.stores.'.config('cache.default'));
        foreach ([$settings['path'] ?? null, $settings['lock_path'] ?? $settings['path'] ?? null] as $path) {
            if (!is_string($path) || !is_dir($path) || !is_writable($path)) {
                throw new \RuntimeException('Recovery cache and lock directories must exist and be writable.');
            }
        }

        $cache = Cache::store();
        if (!$cache->getStore() instanceof \Illuminate\Cache\FileStore) {
            throw new \RuntimeException('Recovery requires the configured file store.');
        }
        // A fresh repository must see the same data and contend for the same lock.
        $peer = Cache::resolve(config('cache.default'));
        $key = 'password-recovery:health:'.bin2hex(random_bytes(16));
        $value = bin2hex(random_bytes(16));
        $lock = $cache->lock($key.':lock', 10);
        $contender = $peer->lock($key.':lock', 10);
        $acquired = false;
        try {
            if (!$cache->put($key, $value, 10) || $peer->get($key) !== $value ||
                !$peer->forget($key) || $cache->get($key) !== null) {
                throw new \RuntimeException('Recovery file cache read/write/delete check failed.');
            }
            $acquired = $lock->get();
            if (!$acquired || $contender->get()) {
                throw new \RuntimeException('Recovery file cache lock exclusion check failed.');
            }
            if (!$lock->release()) {
                throw new \RuntimeException('Recovery file cache lock release check failed.');
            }
            $acquired = false;
            if (!$contender->get() || !$contender->release()) {
                throw new \RuntimeException('Recovery file cache lock reacquisition check failed.');
            }
        } finally {
            if ($acquired) {
                $lock->release();
            }
            $contender->release();
            $cache->forget($key);
        }
    }

    private function putRecovery(string $uuid, array $pending): void
    {
        if (!Cache::put($this->recoveryKey($uuid), $pending, max(1, $pending['expires_at'] - now()->timestamp))) {
            throw new \RuntimeException('Could not persist recovery.');
        }
    }

    private function withRecovery(string $uuid, callable $callback)
    {
        try {
            $this->requireRecoveryCache();
            $verification = VerificationCodeUser::whereKey($uuid)->where('type', self::PASSWORD_RECOVERY_TYPE)->first();
            if (!$verification) {
                return $this->sendError('La solicitud no es valida.', [], 422);
            }
            // Resolve ownership outside the transaction so a database cache read
            // cannot reuse a REPEATABLE READ snapshot taken before the user lock.
            return DB::transaction(function () use ($uuid, $callback, $verification) {
                // All recovery mutations lock the user first and reload the request.
                $user = User::whereKey($verification->user_id)->lockForUpdate()->first();
                $verification = VerificationCodeUser::whereKey($uuid)->lockForUpdate()->first();
                $pending = Cache::get($this->recoveryKey($uuid));
                if (!$user || !$verification || $verification->state || !$pending ||
                    (int) $pending['user_id'] !== (int) $user->id || $pending['expires_at'] <= now()->timestamp) {
                    return $this->sendError('La solicitud ha expirado, no es valida o ya fue utilizada.', [], 422);
                }
                return $callback($verification, $user, $pending);
            });
        } catch (\Throwable $e) {
            return $this->sendError('No se pudo procesar la recuperacion. Intente nuevamente.', [], 503);
        }
    }

    public function validate(Request $request, string $uuid)
    {
        $validator = Validator::make($request->all(), ['code' => 'required|digits:4']);
        if ($validator->fails()) {
            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }
        return $this->withRecovery($uuid, function ($verification, $user, $pending) use ($request) {
            if (!isset($pending['code_hash'])) {
                return $this->sendError('Este codigo ya fue utilizado.', [], 422);
            }
            $pending['attempts']++;
            if (!hash_equals($pending['code_hash'], $this->digest($verification->id.':'.$request->code))) {
                if ($pending['attempts'] >= 5) {
                    $verification->update(['state' => true]);
                    Cache::forget($this->recoveryKey($verification->id));
                } else {
                    $this->putRecovery($verification->id, $pending);
                }
                return $this->sendError('Codigo incorrecto. Maximo 5 intentos por solicitud.', [], 422);
            }
            $token = bin2hex(random_bytes(32));
            unset($pending['code_hash']);
            $pending['token_hash'] = hash('sha256', $token);
            $pending['expires_at'] = now()->addMinutes(5)->timestamp;
            $this->putRecovery($verification->id, $pending);
            return $this->sendResponse([
                'validation' => $verification->id,
                'recovery_token' => $token,
                'expires_in' => 300,
            ], 'Codigo verificado. Ingrese su nueva contrasena.')->header('Cache-Control', 'no-store');
        });
    }

    public function resetPassword(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'validation' => 'required|uuid',
            'recovery_token' => 'required|string|size:64|regex:/\A[a-f0-9]{64}\z/',
            'password' => ['required', 'string', 'min:8', 'confirmed', Password::defaults()],
            'password_confirmation' => 'required|string',
        ]);
        if ($validator->fails()) {
            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }
        return $this->withRecovery($request->validation, function ($verification, $user, $pending) use ($request) {
            if (!isset($pending['token_hash']) || !hash_equals($pending['token_hash'], hash('sha256', $request->recovery_token))) {
                return $this->sendError('La autorizacion no es valida.', [], 422);
            }
            $user->forceFill(['password' => Hash::make($request->password), 'remember_token' => Str::random(60)])->save();
            $verification->update(['state' => true]);
            $tokens = DB::table('oauth_access_tokens')->where('user_id', $user->id);
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', (clone $tokens)->select('id'))->update(['revoked' => true]);
            $tokens->update(['revoked' => true]);
            Cache::forget($this->recoveryKey($verification->id));
            return $this->sendResponse([], 'Contrasena actualizada. Ya puede iniciar sesion.');
        });
    }

    public function recover(Request $request)
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim($request->string('email')->toString()))]);
        }
        $validator = Validator::make($request->all(), ['email' => 'required|email']);
        if ($validator->fails()) {
            return $this->sendError('Error de validacion.', $validator->errors(), 422);
        }
        try {
            $this->requireRecoveryCache();
            $user = User::where('email', $request->email)->first();
            if (!$user) {
                return $this->sendResponse([], 'Si el correo esta registrado, recibira un codigo de recuperacion.');
            }
            return DB::transaction(function () use ($user) {
                $user = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                // Database history enforces resend limits even after cache eviction.
                $requests = VerificationCodeUser::where('user_id', $user->id)->where('type', self::PASSWORD_RECOVERY_TYPE);
                if ((clone $requests)->where('created_at', '>', now()->subMinute())->exists() ||
                    (clone $requests)->where('created_at', '>', now()->subHour())->count() >= 5) {
                    return $this->sendError('Espere para reenviar. Maximo 5 solicitudes por hora.', [], 429);
                }
                $requests->where('state', false)->update(['state' => true]);
                $code = (new CodeGenerator())->generate();
                // CHAR(4) contains a non-secret marker, never the actual code.
                $verification = VerificationCodeUser::create([
                    'user_id' => $user->id, 'type' => self::PASSWORD_RECOVERY_TYPE,
                    'code' => '----', 'state' => false,
                ]);
                $this->putRecovery($verification->id, [
                    'user_id' => $user->id,
                    'code_hash' => $this->digest($verification->id.':'.$code),
                    'attempts' => 0,
                    'expires_at' => now()->addMinutes(self::PASSWORD_RECOVERY_TTL_MINUTES)->timestamp,
                ]);
                Mail::to($user->email)->send(new CreateUserMail([
                    'url' => env('APP_URL_FRONT').'/auth/verification-code/'.$verification->id,
                    'customer_name' => $user->name, 'code' => $code,
                ]));
                return $this->sendResponse(['validation' => $verification->id], 'Codigo enviado.');
            });
        } catch (\Throwable $e) {
            return $this->sendError('No se pudo enviar el codigo. Intente nuevamente.', [], 503);
        }
    }
}
