<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CheckNetworkMoveAudit extends Command
{
    protected $signature = 'mlm:check-network-audit';
    protected $description = 'Read-only verification of the prefixed branch-move audit table';

    public function handle(): int
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        $prefix = $connection->getTablePrefix();
        $logical = 'network_move_audits';
        $physical = $prefix.$logical;
        $this->line('Connection: '.$connection->getName());
        $this->line('Database: '.$database);
        $this->line('Prefix: '.$prefix);
        $this->line('Physical table: '.$physical);

        if (!Schema::hasTable($logical)) {
            $this->error('Laravel does not resolve the logical audit table.');
            return self::FAILURE;
        }

        $expected = [
            'id' => 'bigint unsigned', 'operation_id' => 'char(36)',
            'admin_user_id' => 'bigint unsigned', 'user_code' => 'varchar(100)',
            'old_sponsor_code' => 'varchar(100)', 'new_sponsor_code' => 'varchar(100)',
            'descendant_count' => 'int unsigned', 'total_affected' => 'int unsigned',
            'structure_before_hash' => 'char(64)', 'structure_after_hash' => 'char(64)',
            'status' => 'varchar(24)', 'details' => 'json', 'failure_message' => 'text',
            'created_at' => 'timestamp', 'updated_at' => 'timestamp',
        ];
        $columns = $connection->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$database, $physical]
        );
        $actual = collect($columns)->mapWithKeys(fn ($column) => [$column->COLUMN_NAME => strtolower($column->COLUMN_TYPE)])->all();
        $columnErrors = [];
        foreach ($expected as $name => $type) {
            if (($actual[$name] ?? null) !== $type) $columnErrors[] = "$name expected $type, got ".($actual[$name] ?? 'missing');
        }
        $indexes = $connection->select(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $physical]
        );
        $indexGroups = collect($indexes)->groupBy('INDEX_NAME');
        $this->line('Indexes found: '.implode(', ', $indexGroups->keys()->all()));
        $requiredIndexes = [
            'PRIMARY' => ['columns' => ['id'], 'unique' => true],
            'operation_id' => ['columns' => ['operation_id'], 'unique' => false],
            'admin_user_id' => ['columns' => ['admin_user_id'], 'unique' => false],
            'user_code' => ['columns' => ['user_code'], 'unique' => false],
            'status' => ['columns' => ['status'], 'unique' => false],
        ];
        $indexErrors = [];
        foreach ($requiredIndexes as $label => $expectedIndex) {
            $found = $indexGroups->first(function ($entries) use ($expectedIndex) {
                $entries = $entries->sortBy('SEQ_IN_INDEX')->values();
                return $entries->pluck('COLUMN_NAME')->all() === $expectedIndex['columns']
                    && count(array_unique($entries->pluck('NON_UNIQUE')->all())) === 1
                    && (int) $entries->first()->NON_UNIQUE === (int) !$expectedIndex['unique'];
            });
            if (!$found) $indexErrors[] = $label.'('.implode(',', $expectedIndex['columns']).')';
        }
        $this->line('Columns: '.count($columns).'; required index definitions: '.(count($requiredIndexes) - count($indexErrors)).'/'.count($requiredIndexes));
        if ($columnErrors || $indexErrors) {
            foreach ($columnErrors as $error) $this->error($error);
            foreach ($indexErrors as $error) $this->error('Missing or incompatible index '.$error);
            return self::FAILURE;
        }
        $this->info('Audit schema matches the current migration; no data was read or changed.');
        return self::SUCCESS;
    }
}
