<?php

namespace Tests\Feature;

use App\Support\HealthStatus;
use Illuminate\Support\Facades\File;
use PDO;
use Tests\TestCase;

class HealthTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null) {
            chmod($this->dir, 0755);
            File::deleteDirectory($this->dir);
        }

        parent::tearDown();
    }

    public function test_up_is_ok_when_the_database_is_writable(): void
    {
        $this->get('/up')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'version' => trim(file_get_contents(base_path('version.md'))),
                'db' => 'ok',
            ]);
    }

    public function test_up_is_503_when_the_database_cannot_be_opened(): void
    {
        $this->useDatabase('/nonexistent/dir/nugget.sqlite');

        $this->get('/up')->assertStatus(503)->assertJsonPath('db', 'error');
    }

    public function test_up_is_503_when_the_database_is_read_only(): void
    {
        $this->dir = sys_get_temp_dir().'/nugget-health-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        $file = $this->dir.'/nugget.sqlite';
        (new PDO('sqlite:'.$file))->exec('create table t (id integer)');
        chmod($file, 0444);
        chmod($this->dir, 0555);

        $this->useDatabase($file);

        $this->get('/up')->assertStatus(503)->assertJsonPath('db', 'error');
    }

    public function test_up_still_reports_the_version_when_the_database_is_down(): void
    {
        $this->useDatabase('/nonexistent/dir/nugget.sqlite');

        $this->get('/up')->assertJsonPath('version', trim(file_get_contents(base_path('version.md'))));
    }

    public function test_up_starts_no_session(): void
    {
        $this->get('/up')->assertCookieMissing(config('session.cookie'));
    }

    public function test_the_version_is_unknown_when_the_file_is_missing(): void
    {
        $this->assertSame('unknown', (new HealthStatus(base_path('nope.md')))->version());
    }

    private function useDatabase(string $path): void
    {
        config([
            'database.connections.probe' => ['driver' => 'sqlite', 'database' => $path, 'prefix' => ''],
            'database.default' => 'probe',
        ]);
        app('db')->purge();
    }
}
