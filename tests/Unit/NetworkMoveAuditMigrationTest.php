<?php

namespace Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class NetworkMoveAuditMigrationTest extends TestCase
{
    private function migration(): object
    {
        return require base_path('database/migrations/2026_11_06_000000_create_network_move_audits_table.php');
    }

    public function test_existing_prefixed_compatible_table_is_validated_and_never_recreated_or_dropped(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('getDatabaseName')->once()->andReturn('imperio_bd');
        $connection->shouldReceive('getTablePrefix')->once()->andReturn('ig_');
        $connection->shouldReceive('select')->twice()->withArgs(function ($sql, $bindings) {
            return str_contains($sql, 'information_schema.') && $bindings[1] === 'ig_network_move_audits';
        })->andReturn($this->columns(), $this->indexes());
        $connection->shouldReceive('selectOne')->once()->andReturn((object) ['ENGINE' => 'InnoDB']);
        Schema::shouldReceive('hasTable')->once()->with('network_move_audits')->andReturn(true);
        Schema::shouldReceive('getConnection')->once()->andReturn($connection);
        Schema::shouldNotReceive('create');
        Schema::shouldNotReceive('dropIfExists');

        $migration = $this->migration();
        $migration->up();
        $migration->down();
        $this->assertTrue(true);
    }

    public function test_missing_table_is_created_with_expected_columns_and_down_is_safe(): void
    {
        $blueprint = null;
        Schema::shouldReceive('hasTable')->once()->with('network_move_audits')->andReturn(false);
        Schema::shouldReceive('create')->once()->with('network_move_audits', Mockery::on(function ($callback) use (&$blueprint) {
            $blueprint = new Blueprint('network_move_audits');
            $callback($blueprint);
            return count($blueprint->getColumns()) === 15 && $blueprint->getTable() === 'network_move_audits';
        }));
        Schema::shouldNotReceive('dropIfExists');

        $migration = $this->migration();
        $migration->up();
        $migration->down();
        $this->assertCount(15, $blueprint->getColumns());
    }

    private function columns(): array
    {
        $definitions = [
            'id' => ['bigint unsigned', 'NO'], 'operation_id' => ['char(36)', 'NO'],
            'admin_user_id' => ['bigint unsigned', 'YES'], 'user_code' => ['varchar(100)', 'NO'],
            'old_sponsor_code' => ['varchar(100)', 'YES'], 'new_sponsor_code' => ['varchar(100)', 'NO'],
            'descendant_count' => ['int unsigned', 'NO'], 'total_affected' => ['int unsigned', 'NO'],
            'structure_before_hash' => ['char(64)', 'NO'], 'structure_after_hash' => ['char(64)', 'NO'],
            'status' => ['varchar(24)', 'NO'], 'details' => ['json', 'YES'],
            'failure_message' => ['text', 'YES'], 'created_at' => ['timestamp', 'YES'],
            'updated_at' => ['timestamp', 'YES'],
        ];
        $rows = [];
        foreach ($definitions as $name => [$type, $nullable]) {
            $rows[] = (object) ['COLUMN_NAME' => $name, 'COLUMN_TYPE' => $type, 'IS_NULLABLE' => $nullable,
                'COLUMN_DEFAULT' => match ($name) { 'descendant_count' => '0', 'total_affected' => '1', default => null },
                'EXTRA' => $name === 'id' ? 'auto_increment' : ''];
        }
        return $rows;
    }

    private function indexes(): array
    {
        $rows = [];
        foreach ([['PRIMARY', 'id', 0], ['op', 'operation_id', 1], ['admin', 'admin_user_id', 1],
            ['user', 'user_code', 1], ['status', 'status', 1]] as [$name, $column, $nonUnique]) {
            $rows[] = (object) ['INDEX_NAME' => $name, 'COLUMN_NAME' => $column,
                'NON_UNIQUE' => $nonUnique, 'SEQ_IN_INDEX' => 1];
        }
        return $rows;
    }
}
