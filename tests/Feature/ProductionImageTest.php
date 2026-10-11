<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * What the shipped image relies on in the repository. The image itself is
 * built and run by hand (see the task notes); these catch a file that has
 * gone missing or lost its place.
 */
class ProductionImageTest extends TestCase
{
    public function test_the_dockerfile_has_a_prod_target_built_in_stages(): void
    {
        $dockerfile = file_get_contents(base_path('Dockerfile'));

        foreach (['AS dev', 'AS base', 'AS vendor', 'AS assets', 'AS prod'] as $stage) {
            $this->assertStringContainsString($stage, $dockerfile);
        }

        // The last stage is the default target.
        $this->assertStringContainsString('AS prod', substr($dockerfile, strrpos($dockerfile, "\nFROM ")));
        $this->assertStringContainsString('VOLUME ["/app/data"]', $dockerfile);
        $this->assertStringContainsString('DB_DATABASE=/app/data/nugget.sqlite', $dockerfile);
        $this->assertStringContainsString('ENTRYPOINT ["/app/docker/entry.sh"]', $dockerfile);
    }

    public function test_the_entry_script_migrates_and_caches_and_drops_to_the_app_user(): void
    {
        $entry = file_get_contents(base_path('docker/entry.sh'));

        $this->assertTrue(is_executable(base_path('docker/entry.sh')));
        $this->assertTrue(is_executable(base_path('docker/artisan')));
        $this->assertStringContainsString('php artisan migrate --force', $entry);
        $this->assertStringContainsString('php artisan optimize', $entry);
        $this->assertStringContainsString('exec su-exec app "$@"', $entry);
        $this->assertStringContainsString('.app-key', $entry);
    }

    public function test_the_build_leaves_out_secrets_local_data_and_the_dev_servers_marker(): void
    {
        $ignored = file(base_path('.dockerignore'), FILE_IGNORE_NEW_LINES);

        foreach (['.env', '.env.*', 'data', '_notes', 'vendor', 'node_modules', 'public/hot', 'tests'] as $path) {
            $this->assertContains($path, $ignored);
        }
    }

    public function test_the_image_logs_to_the_data_directory(): void
    {
        $this->assertSame(base_path('data/logs/nugget.log'), config('logging.channels.data.path'));
        $this->assertSame('daily', config('logging.channels.data.driver'));
    }

    public function test_the_octane_worker_and_site_config_the_image_copies_are_there(): void
    {
        $this->assertFileExists(base_path('vendor/laravel/octane/src/Commands/stubs/frankenphp-worker.php'));
        $this->assertFileExists(base_path('docker/Caddyfile'));
        $this->assertFileExists(base_path('docker/php.ini'));
        $this->assertFileExists(base_path('docker/opcache.ini'));
    }
}
