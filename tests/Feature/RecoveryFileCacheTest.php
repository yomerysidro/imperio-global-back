<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\LoginController;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class RecoveryFileCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/imperio-recovery-'.bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->app->instance('env', 'production');
        config([
            'cache.default' => 'recovery_test',
            'cache.stores.recovery_test' => [
                'driver' => 'file', 'path' => $this->directory, 'lock_path' => $this->directory,
            ],
            'password_recovery.file_cache_shared' => true,
        ]);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->directory);
        parent::tearDown();
    }

    private function checkCache(): void
    {
        (new \ReflectionMethod(LoginController::class, 'requireRecoveryCache'))
            ->invoke(new LoginController());
    }

    public function test_file_cache_passes_production_checks(): void
    {
        $this->checkCache();
        $this->assertSame([], (new Filesystem())->allFiles($this->directory));
    }

    public function test_file_requires_explicit_shared_persistent_storage_confirmation(): void
    {
        config(['password_recovery.file_cache_shared' => false]);
        $this->expectException(\RuntimeException::class);
        $this->checkCache();
    }

    public function test_missing_cache_directory_is_rejected(): void
    {
        config(['cache.stores.recovery_test.path' => $this->directory.'/missing']);
        $this->expectException(\RuntimeException::class);
        $this->checkCache();
    }

    public function test_missing_lock_directory_is_rejected(): void
    {
        config(['cache.stores.recovery_test.lock_path' => $this->directory.'/missing']);
        $this->expectException(\RuntimeException::class);
        $this->checkCache();
    }

    public function test_array_is_rejected_in_production(): void
    {
        config(['cache.default' => 'array']);
        $this->expectException(\RuntimeException::class);
        $this->checkCache();
    }

    public function test_another_php_process_shares_data_and_lock_exclusion(): void
    {
        $cache = Cache::store();
        $this->assertTrue($cache->put('probe', 'shared', 30));
        $lock = $cache->lock('probe-lock', 30);
        $this->assertTrue($lock->get());
        $script = <<<'PHP'
require $argv[1];
$store = new Illuminate\Cache\FileStore(new Illuminate\Filesystem\Filesystem(), $argv[2]);
$cache = new Illuminate\Cache\Repository($store);
if ($cache->get('probe') !== 'shared') exit(10);
$lock = $cache->lock('probe-lock', 30);
$expected = $argv[3] === 'available';
if ($lock->get() !== $expected) exit(11);
if ($expected && (!$lock->release() || !$cache->forget('probe') || $cache->get('probe') !== null)) exit(12);
PHP;
        try {
            $process = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $this->directory, 'blocked']);
            $process->mustRun();
            $this->assertSame(0, $process->getExitCode());
            $this->assertTrue($lock->release());
            $process = new Process([PHP_BINARY, '-r', $script, base_path('vendor/autoload.php'), $this->directory, 'available']);
            $process->mustRun();
            $this->assertSame(0, $process->getExitCode());
            $this->assertNull($cache->get('probe'));
        } finally {
            $lock->release();
            $cache->forget('probe');
        }
    }
}
