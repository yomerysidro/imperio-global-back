<?php

namespace App\Console\Commands;

use App\Models\ScheduleCron;
use App\Services\Core\RangeQualificationService;
use Illuminate\Console\Command;

class RangeListBulk extends Command
{
    protected $signature = 'app:range-list-bulk';
    protected $description = 'Recalcula rangos e infinito usando exclusivamente reglas configuradas en BD';

    public function handle(): int
    {
        $log = ScheduleCron::create(['signature' => $this->signature]);
        try {
            $engine = app(RangeQualificationService::class);
            $result = ['range' => $engine->recalculateAll(), 'infinito' => $engine->distributeInfinity()];
            $log->update(['response' => json_encode($result), 'status' => 2]);
            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $log->update(['response' => $exception->getMessage(), 'status' => 3]);
            report($exception);
            return self::FAILURE;
        }
    }
}
