<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A page and its htmx partial share a URL, so the browser must not serve one
 * for the other, as it would when it restores tabs from its cache.
 */
class HtmxCacheTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    public function test_a_full_page_varies_on_the_htmx_headers(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertOk();
        $vary = $response->baseResponse->getVary();
        $this->assertContains('HX-Request', $vary);
        $this->assertContains('HX-Target', $vary);
        $this->assertStringNotContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_a_partial_is_never_stored(): void
    {
        $response = $this->withHeaders(['HX-Request' => 'true', 'HX-Target' => 'dashboard-totals'])
            ->get(route('dashboard'));

        $response->assertOk();
        $this->assertContains('HX-Request', $response->baseResponse->getVary());
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}
