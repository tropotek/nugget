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

    public function test_the_image_serves_https_itself_when_its_server_name_is_a_host_name(): void
    {
        $entry = file_get_contents(base_path('docker/entry.sh'));
        $caddyfile = file(base_path('docker/Caddyfile'), FILE_IGNORE_NEW_LINES);

        // ":80", or no name, is plain HTTP only. A host name gets a site of its own, which the app trusts as a proxy.
        $this->assertStringContainsString("'' | :*)", $entry);
        $this->assertStringContainsString('NUGGET_TLS_SITE="$SERVER_NAME {', $entry);
        $this->assertStringContainsString('reverse_proxy 127.0.0.1:${APP_PORT}', $entry);
        $this->assertStringContainsString('TRUSTED_PROXIES="${TRUSTED_PROXIES:+$TRUSTED_PROXIES,}127.0.0.1"', $entry);

        // Caddy's own certificate unless one is named, and what it gets is kept in the data directory.
        $this->assertStringContainsString('tls="${NUGGET_TLS:+tls $NUGGET_TLS}"', $entry);
        $this->assertStringContainsString('tls="tls $NUGGET_TLS_CERT $NUGGET_TLS_KEY"', $entry);
        $this->assertStringContainsString('XDG_DATA_HOME="$DATA"', $entry);

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
