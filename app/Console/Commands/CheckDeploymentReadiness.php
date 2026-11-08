<?php

namespace App\Console\Commands;

use App\Services\Core\CorporateRootService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CheckDeploymentReadiness extends Command
{
    protected $signature = 'mlm:deployment-check {--production} {--database= : Required destination database}';
    protected $description = 'Read-only deployment preflight for the installed schema; never runs migrations';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->error('Se requiere MySQL.');
            return self::FAILURE;
        }
        DB::statement('SET TRANSACTION READ ONLY');
        DB::beginTransaction();
        try {
            $errors = [];
            $database = DB::selectOne('SELECT DATABASE() AS name')->name;
            if (!$this->option('database') || $database !== $this->option('database')) $errors[] = 'La base real no coincide con --database.';
            if (DB::getTablePrefix() !== 'ig_') $errors[] = 'Se requiere prefijo ig_.';
            if ($this->option('production')) {
                if (!app()->environment('production')) $errors[] = 'APP_ENV debe ser production.';
                if (config('app.debug')) $errors[] = 'APP_DEBUG debe ser false.';
                if (config('network_moves.local_testing_enabled')) $errors[] = 'La excepcion local debe estar deshabilitada.';
            }
            $schema = DB::getSchemaBuilder();
            $applied = $schema->hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
            $files = array_map(fn ($file) => pathinfo($file->getFilename(), PATHINFO_FILENAME), File::files(database_path('migrations')));
            $pending = array_values(array_diff($files, $applied)); sort($pending);
            // Pending history is informational: this deployment never runs migrations.
            foreach (['packs' => ['category'], 'sponsorship_points' => [
                'level1', 'level2', 'level3', 'level4', 'level5', 'level6', 'level7',
            ]] as $table => $columns) {
                if (!$schema->hasColumns($table, $columns)) $errors[] = 'Faltan columnas funcionales existentes en '.$table.'. No se modifico el esquema.';
            }
            foreach (['users', 'sponsor_relations', 'payment_orders', 'payment_logs', 'payment_order_points',
                'payment_product_orders', 'payment_product_order_points', 'manual_reactivations', 'network_move_audits'] as $table) {
                $engine = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [$database, DB::getTablePrefix().$table]);
                if (!$engine || strcasecmp($engine->engine, 'InnoDB') !== 0) $errors[] = $table.' debe existir y usar InnoDB.';
            }
            if ($schema->hasTable('users')) {
                if (DB::table('users')->select('uuid')->groupBy('uuid')->havingRaw('COUNT(*) > 1')->exists()) $errors[] = 'Existen UUID duplicados; requiere revision.';
            }
            if ($schema->hasTable('sponsor_relations')) {
                try { app(CorporateRootService::class)->user(); }
                catch (\Throwable $e) { $errors[] = $e->getMessage(); }
            }
            if ($schema->hasTable('network_move_audits')) {
                if (!$schema->hasColumns('network_move_audits', [
                    'id', 'operation_id', 'admin_user_id', 'user_code', 'old_sponsor_code',
                    'new_sponsor_code', 'descendant_count', 'total_affected',
                    'structure_before_hash', 'structure_after_hash', 'status', 'details',
                    'created_at', 'updated_at',
                ])) $errors[] = 'Faltan columnas requeridas en la auditoria existente.';
            }
            $this->line(json_encode(['database' => $database, 'prefix' => DB::getTablePrefix(),
                'root_uuid' => config('mlm.root_uuid'), 'pending_migrations' => $pending,
                'errors' => $errors, 'deployment_preflight_passed' => !$errors,
                'movement_certification' => 'NOT_GRANTED_BY_THIS_CHECK'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return $errors ? self::FAILURE : self::SUCCESS;
        } finally { DB::rollBack(); }
    }
}
