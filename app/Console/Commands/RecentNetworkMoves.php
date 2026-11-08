<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RecentNetworkMoves extends Command
{
    protected $signature = 'mlm:recent-moves {--limit=50}';
    protected $description = 'Read-only listing of recent branch movement audit records';

    public function handle(): int
    {
        $connection = DB::connection();
        if (!$connection->getSchemaBuilder()->hasTable('network_move_audits')) {
            $this->error('Audit table unavailable through Laravel logical name.');
            return self::FAILURE;
        }
        $limit = max(1, min(200, (int) $this->option('limit')));
        $rows = DB::table('network_move_audits')->orderByDesc('created_at')->limit($limit)->get([
            'operation_id', 'user_code', 'old_sponsor_code', 'new_sponsor_code',
            'descendant_count', 'total_affected', 'status', 'admin_user_id', 'created_at',
        ]);
        $this->line(json_encode(['database' => $connection->getDatabaseName(), 'moves' => $rows],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
