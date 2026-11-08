<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class BackupLocalDatabase extends Command
{
    protected $signature = 'mlm:backup-local {--output=}';
    protected $description = 'Create and verify a full local MySQL backup before MLM changes';

    public function handle(): int
    {
        $connection = app('db')->connection();
        $config = $connection->getConfig();
        if ($connection->getDriverName() !== 'mysql'
            || !in_array(strtolower((string) $config['host']), ['127.0.0.1', 'localhost', '::1'], true)
            || $connection->getDatabaseName() !== 'imperiog') {
            $this->error('Refusing backup: active connection is not the authorized local imperiog database.');
            return self::FAILURE;
        }
        $binary = 'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe';
        if (!is_file($binary)) {
            $this->error('mysqldump was not found at the configured MySQL 8 installation path.');
            return self::FAILURE;
        }
        $directory = storage_path('app/private/backups');
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            $this->error('Could not create private backup directory.');
            return self::FAILURE;
        }
        $path = $this->option('output') ?: $directory.'/imperiog_'.now()->format('Ymd_His').'.sql';
        if (!str_starts_with(realpath(dirname($path)) ?: dirname($path), realpath($directory)) || file_exists($path)) {
            $this->error('Backup path must be a new file inside storage/app/private/backups.');
            return self::FAILURE;
        }
        $optionFile = $directory.'/.mysql-client-'.bin2hex(random_bytes(8)).'.cnf';
        $escape = fn ($value) => str_replace(["\\", '"', "\n", "\r"], ["\\\\", '\\"', '\\n', '\\r'], (string) $value);
        file_put_contents($optionFile, "[client]\nhost=\"".$escape($config['host'])."\"\nport=".(int) $config['port']
            ."\nuser=\"".$escape($config['username'])."\"\npassword=\"".$escape($config['password'])."\"\n", LOCK_EX);
        @chmod($optionFile, 0600);
        try {
            $process = new Process([$binary, '--defaults-extra-file='.$optionFile, '--no-tablespaces', '--single-transaction', '--quick',
                '--routines', '--events', '--triggers', '--hex-blob', '--default-character-set=utf8mb4',
                '--databases', $connection->getDatabaseName()], base_path());
            $process->setTimeout(1800);
            $process->run(function ($type, $buffer) use ($path) {
                if ($type === Process::OUT) file_put_contents($path, $buffer, FILE_APPEND | LOCK_EX);
                else $this->error(trim($buffer));
            });
            if (!$process->isSuccessful() || !is_file($path) || filesize($path) < 1024) {
                @unlink($path);
                $this->error('mysqldump failed or produced an unexpectedly small backup.');
                return self::FAILURE;
            }
            $contents = file_get_contents($path);
            if (!str_contains($contents, 'CREATE TABLE') || !str_contains($contents, 'INSERT INTO')) {
                @unlink($path);
                $this->error('Backup verification failed: schema/data markers are missing.');
                return self::FAILURE;
            }
            $this->info('Verified local backup: '.$path);
            $this->line('Bytes: '.filesize($path));
            $this->line('SHA-256: '.hash_file('sha256', $path));
            return self::SUCCESS;
        } finally {
            @unlink($optionFile);
        }
    }
}
