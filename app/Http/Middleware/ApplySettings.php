<?php

namespace App\Http\Middleware;

use App\Facades\Settings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the settings that Laravel reads from config into config, for this request.
 */
class ApplySettings
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['app.name' => Settings::get('site_name')]);

        return $next($request);
    }
}
