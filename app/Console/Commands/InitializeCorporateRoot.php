<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InitializeCorporateRoot extends Command
{
    protected $signature = 'mlm:initialize-root {email} {--name=Empresa}';
    protected $description = 'Initialize a corporate account in an empty installation without importing local data';

    public function handle(): int
    {
        $configured = app(\App\Services\Core\CorporateRootService::class)->configuredUuid();
        if ($configured) {
            $root = app(\App\Services\Core\CorporateRootService::class)->user();
            if (strcasecmp($root->email, $this->argument('email')) !== 0
                || DB::table('sponsor_relations')->where('user_code', $root->uuid)->where('state', true)->exists()) {
                $this->error('La configuración no corresponde a una cuenta raíz válida.');
                return self::FAILURE;
            }
            $this->info('La cuenta raíz ya está configurada: '.$root->uuid);
            return self::SUCCESS;
        }
        if (User::exists()) {
            $this->error('Existen cuentas. Configure MLM_ROOT_UUID con la raíz real; no se crearán ni modificarán usuarios.');
            return self::FAILURE;
        }
        if (!filter_var($this->argument('email'), FILTER_VALIDATE_EMAIL)) return self::FAILURE;
        $password = $this->secret('Contraseña inicial (mínimo 12 caracteres)');
        if (strlen((string) $password) < 12) return self::FAILURE;
        // A database advisory lock also protects initialization before a root row exists.
        $lock = DB::selectOne('SELECT GET_LOCK(?, 10) AS acquired', ['mlm_initialize_'.DB::getDatabaseName()]);
        if ((int) $lock->acquired !== 1) return self::FAILURE;
        try {
            $root = DB::transaction(function () use ($password) {
                if (User::exists()) throw new \RuntimeException('La instalación ya contiene usuarios.');
                return User::create(['uuid' => (string) Str::uuid(), 'email' => $this->argument('email'),
                    'name' => $this->option('name'), 'password' => $password, 'is_admin' => true]);
            });
            $this->info('Configure MLM_ROOT_UUID='.$root->uuid.' y reconstruya la caché de configuración.');
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?)', ['mlm_initialize_'.DB::getDatabaseName()]);
        }
        return self::SUCCESS;
    }
}
