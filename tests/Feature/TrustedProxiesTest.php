<?php

namespace Tests\Feature;

use App\Support\TrustedProxies;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Behind a reverse proxy that ends TLS, the site believes the proxy's
 * forwarded headers only when the proxy is one it was told to trust.
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_seen', fn (Request $request) => [
            'secure' => $request->isSecure(),
            'ip' => $request->ip(),
            'url' => url('/login'),
        ]);
    }

    protected function tearDown(): void
    {
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function test_forwarded_headers_are_ignored_when_no_proxy_is_trusted(): void
    {
        $this->trust(null);

        $response = $this->throughProxy()->assertJson(['secure' => false, 'ip' => '127.0.0.1']);

        $this->assertStringStartsWith('http://', $response->json('url'));
        $this->assertStringNotContainsString('nugget.example.org', $response->json('url'));
    }

    public function test_any_proxy_is_trusted_with_a_star(): void
    {
        $this->trust('*');

        $this->throughProxy()->assertExactJson(['secure' => true, 'ip' => '203.0.113.9', 'url' => 'https://nugget.example.org/login']);
    }

    public function test_a_listed_proxy_is_trusted(): void
    {
        $this->trust('192.168.0.20, 127.0.0.1');

        $this->throughProxy()->assertJson(['secure' => true, 'ip' => '203.0.113.9']);
    }

    public function test_a_proxy_that_is_not_listed_is_not_trusted(): void
    {
        $this->trust('192.168.0.20');

        $this->throughProxy()->assertJson(['secure' => false, 'ip' => '127.0.0.1']);
    }

    private function trust(?string $proxies): void
    {
        config(['nugget.trusted_proxies' => $proxies]);

        TrustedProxies::apply();
    }

    /**
     * A request that arrives from 127.0.0.1 saying it was forwarded from an https one.
     */
    private function throughProxy()
    {
        return $this->getJson('/_seen', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-For' => '203.0.113.9',
            'X-Forwarded-Host' => 'nugget.example.org',
            'X-Forwarded-Port' => '443',
        ]);
    }
}
