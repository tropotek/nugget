<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Pages render without a Vite build or dev server.
        $this->withoutVite();

        // No test touches the network: an outside call that is not faked throws.
        Http::preventStrayRequests();
    }
}
