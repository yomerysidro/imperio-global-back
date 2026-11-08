<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('network_move_audits')) {
            $this->assertCompatible();
            return;
        }

        Schema::create('network_move_audits', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->index();
            $table->unsignedBigInteger('admin_user_id')->nullable()->index();
            $table->string('user_code', 100)->index();
            $table->string('old_sponsor_code', 100)->nullable();
            $table->string('new_sponsor_code', 100);
            $table->unsignedInteger('descendant_count')->default(0);
            $table->unsignedInteger('total_affected')->default(1);
            $table->char('structure_before_hash', 64);
            $table->char('structure_after_hash', 64);
            $table->string('status', 24)->index();
            $table->json('details')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();
            $table->engine = 'InnoDB';
        });
    }

    public function down(): void
    {
        // Intentionally irreversible: the table may have been provisioned
        // manually before this migration was recorded. Never risk deleting it.
    }

    private function assertCompatible(): void
    {
        $table = 'network_move_audits';
        $expectedColumns = [
            'id' => ['bigint unsigned', false],
            'operation_id' => ['char(36)', false],
            'admin_user_id' => ['bigint unsigned', true],
            'user_code' => ['varchar(100)', false],
            'old_sponsor_code' => ['varchar(100)', true],
            'new_sponsor_code' => ['varchar(100)', false],
            'descendant_count' => ['int unsigned', false],
            'total_affected' => ['int unsigned', false],
            'structure_before_hash' => ['char(64)', false],
            'structure_after_hash' => ['char(64)', false],
            'status' => ['varchar(24)', false],
            'details' => ['json', true],
            'failure_message' => ['text', true],
            'created_at' => ['timestamp', true],
            'updated_at' => ['timestamp', true],
        ];
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        $physicalTable = $connection->getTablePrefix().$table;
        $columns = $connection->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $physicalTable]
        );
        $actualColumns = collect($columns)->keyBy('COLUMN_NAME');
        $errors = [];
        foreach ($expectedColumns as $name => [$type, $nullable]) {
            $actual = $actualColumns->get($name);
            if (!$actual || strtolower($actual->COLUMN_TYPE) !== $type
                || ($actual->IS_NULLABLE === 'YES') !== $nullable) {
                $errors[] = $name;
            }
        }
        $id = $actualColumns->get('id');
        if ($id && !str_contains(strtolower($id->EXTRA), 'auto_increment')) $errors[] = 'id:auto_increment';
        foreach (['descendant_count' => '0', 'total_affected' => '1'] as $name => $default) {
            $column = $actualColumns->get($name);
            if ($column && (string) $column->COLUMN_DEFAULT !== $default) $errors[] = $name.':default';
        }

        $engine = $connection->selectOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $physicalTable]
        );
        if (!$engine || strcasecmp((string) $engine->ENGINE, 'InnoDB') !== 0) $errors[] = 'engine:InnoDB';

        $indexes = $connection->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, $physicalTable]
        );
        $groups = collect($indexes)->groupBy('INDEX_NAME');
        $requiredIndexes = [
            ['id', true], ['operation_id', false], ['admin_user_id', false],
            ['user_code', false], ['status', false],
        ];
        foreach ($requiredIndexes as [$column, $unique]) {
            $found = $groups->contains(function ($entries) use ($column, $unique) {
                $entries = $entries->sortBy('SEQ_IN_INDEX')->values();
                return $entries->pluck('COLUMN_NAME')->all() === [$column]
                    && (int) $entries->first()->NON_UNIQUE === (int) !$unique;
            });
            if (!$found) $errors[] = 'index:'.$column;
        }

        if ($errors) {
            throw new \RuntimeException(
                'La tabla network_move_audits existente no es compatible ('.implode(', ', $errors).'). No se modificó.'
            );
        }
    }
};
