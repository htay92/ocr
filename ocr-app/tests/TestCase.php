<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Refuse to run against anything but the in-memory test database. A cached
     * config (php artisan config:cache / optimize) or env vars injected by Docker
     * outrank phpunit.xml, and RefreshDatabase would then wipe the dev database.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $config = $app['config'];
        $connection = $config->get('database.default');
        $database = $config->get("database.connections.{$connection}.database");
        if ($app->configurationIsCached() || $connection !== 'sqlite' || $database !== ':memory:') {
            fwrite(STDERR, "\nTests stopped: they would use the database [{$connection}: {$database}], not the in-memory test database.\n"
                .($app->configurationIsCached() ? "The config is cached. Run: php artisan config:clear\n" : "Check phpunit.xml and that compose injects no env vars.\n"));
            exit(1);
        }

        return $app;
    }
}
