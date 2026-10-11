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

    public function test_the_live_compose_file_runs_the_site_and_its_scheduler_from_one_data_directory(): void
    {
        $compose = file_get_contents(base_path('docker-compose-live.yml'));

        // Its own project name, so it can run beside the dev stack.
        $this->assertMatchesRegularExpression('/^name: nugget-live\b/m', $compose);
        $this->assertMatchesRegularExpression('/^  app:$/m', $compose);
        $this->assertMatchesRegularExpression('/^  scheduler:$/m', $compose);
        $this->assertStringContainsString('- ./data:/app/data', $compose);
        $this->assertStringContainsString('http://127.0.0.1:8080/up', $compose);

        // Only the site migrates; the scheduler waits for it.
        $scheduler = substr($compose, strpos($compose, "\n  scheduler:"));
        $this->assertStringContainsString('condition: service_healthy', $scheduler);
        $this->assertStringContainsString('NUGGET_MIGRATE: "0"', $scheduler);
        $this->assertStringContainsString('"schedule:work"', $scheduler);
        $this->assertStringContainsString('disable: true', $scheduler);
    }

    public function test_the_image_can_serve_https_with_a_certificate_of_the_operators(): void
    {
        $entry = file_get_contents(base_path('docker/entry.sh'));
        $caddyfile = file(base_path('docker/Caddyfile'), FILE_IGNORE_NEW_LINES);

        // The entry script writes the site and has the app trust it as a proxy.
        $this->assertStringContainsString('tls $NUGGET_TLS_CERT $NUGGET_TLS_KEY', $entry);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:${APP_PORT}', $entry);
        $this->assertStringContainsString('export NUGGET_TLS_SITE TRUSTED_PROXIES', $entry);

        // The Caddyfile has a place for it, on a line of its own.
        $this->assertContains('{$NUGGET_TLS_SITE}', $caddyfile);

        // A placeholder is filled in inside a comment too, where a value of several lines breaks the file.
        foreach ($caddyfile as $line) {
            if (str_starts_with(ltrim($line), '#')) {
                $this->assertStringNotContainsString('{$', $line);
            }
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
