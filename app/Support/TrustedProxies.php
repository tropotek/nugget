<?php

namespace App\Support;

use Illuminate\Http\Middleware\TrustProxies;

/**
 * Which reverse proxies the site believes when they say a request was
 * https, and whose it was. `nugget.trusted_proxies` is `*` for any, or a
 * comma list of addresses and ranges. None is trusted until it is set, so a
 * request cannot pass itself off as another's by sending the headers itself.
 */
class TrustedProxies
{
    public static function apply(): void
    {
        TrustProxies::at(self::listed((string) config('nugget.trusted_proxies')));
    }

    /**
     * @return '*'|list<string>
     */
    private static function listed(string $proxies): string|array
    {
        $listed = array_values(array_filter(array_map('trim', explode(',', $proxies)), fn (string $proxy) => $proxy !== ''));

        return in_array('*', $listed, true) ? '*' : $listed;
    }
}
