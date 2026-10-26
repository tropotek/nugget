<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page and its htmx partials share a URL, so the cache keeps them apart by
 * the htmx headers, and a partial is never stored. Without it a browser that
 * restores its tabs from the cache shows the last partial as the whole page.
 */
class VaryOnHtmx
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->setVary(['HX-Request', 'HX-Target'], false);

        if ($request->hasHeader('HX-Request')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
