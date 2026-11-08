<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        if (in_array(\Illuminate\Foundation\Testing\RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $connection = $app['config']->get('database.default');
            $database = $app['config']->get('database.connections.'.$connection.'.database');
            if ($connection !== 'mysql' || !preg_match('/^imperio_mlm_test_[a-z0-9_]+$/i', (string) $database)
                || strcasecmp((string) $database, 'imperiog') === 0) {
                throw new \RuntimeException('Pruebas destructivas bloqueadas: use php tests/run-isolated-mysql.php.');
            }
        }
        return $app;
    }
}
