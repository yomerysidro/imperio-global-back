<?php

// Runs transactional tests only against a provisioned and migrated disposable schema.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$schema = getenv('TEST_MYSQL_DATABASE') ?: '';
if (!preg_match('/^imperio_mlm_test_[a-z0-9_]+$/i', $schema) || strcasecmp($schema, 'imperiog') === 0) {
    fwrite(STDERR, "Set TEST_MYSQL_DATABASE to a manually created disposable schema named imperio_mlm_test_*; refusing writes.\n");
    exit(2);
}
$config = config('database.connections.mysql');
foreach (['host', 'port', 'username', 'password'] as $key) {
    $override = getenv('TEST_MYSQL_'.strtoupper($key));
    if ($override !== false) $config[$key] = $override;
}
try {
    $pdo = new PDO('mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$schema.';charset=utf8mb4',
        $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $active = $pdo->query('SELECT DATABASE()')->fetchColumn();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot verify isolated MySQL database: ".$e->getMessage()."\n");
    exit(2);
}
echo "Verified active test database before PHPUnit: {$active}\n";
if ($active !== $schema || strcasecmp($active, 'imperiog') === 0) {
    fwrite(STDERR, "Active database mismatch or production database; refusing writes.\n");
    exit(2);
}

foreach (['HOST' => $config['host'], 'PORT' => $config['port'], 'USERNAME' => $config['username'], 'PASSWORD' => $config['password']] as $key => $value) {
    putenv('TEST_MYSQL_'.$key.'='.$value);
}
putenv('DB_URL=');
putenv('DB_CONNECTION=mysql');
putenv('DB_DATABASE='.$schema);
putenv('DB_HOST='.$config['host']);
putenv('DB_PORT='.$config['port']);
putenv('DB_USERNAME='.$config['username']);
putenv('DB_PASSWORD='.$config['password']);
putenv('APP_ENV=testing');
putenv('APP_CONFIG_CACHE='.dirname(__DIR__).'/bootstrap/cache/isolated-test-no-config.php');

if (in_array('--migrate', $argv, true)) {
    $migration = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../artisan').' migrate --force --no-interaction';
    passthru($migration, $migrationStatus);
    if ($migrationStatus !== 0) exit($migrationStatus);
}

$command = escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/../vendor/phpunit/phpunit/phpunit').' --testsuite Unit,Feature';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--filter=')) $command .= ' --filter '.escapeshellarg(substr($argument, 9));
}
passthru($command, $status);
exit($status);
