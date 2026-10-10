<?php

namespace App\Support;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * The sidebar menu, built from config/navigation.php for the current request.
 */
class Navigation
{
    /**
     * The headings and links to show, in order.
     *
     * A link is left out when its route is not defined or its ability is not allowed,
     * and a heading is left out when no link follows it.
     *
     * @return list<array{title: string}|array{label: string, icon: string, url: string, active: bool}>
     */
    public function items(): array
    {
        $items = [];
        $heading = null;

        foreach (config('navigation', []) as $entry) {
            if (isset($entry['title'])) {
                $heading = ['title' => $entry['title']];

                continue;
            }

            if (! Route::has($entry['route']) || (isset($entry['can']) && ! Gate::check($entry['can']))) {
                continue;
            }

            if ($heading !== null) {
                $items[] = $heading;
                $heading = null;
            }

            $items[] = [
                'label' => $entry['label'],
                'icon' => $entry['icon'],
                'url' => route($entry['route']),
                'active' => request()->routeIs(...($entry['active'] ?? [$this->group($entry['route'])])),
            ];
        }

        return $items;
    }

    /**
     * The pattern for a route and its siblings: "assets.index" gives "assets.*", "dashboard" stays as it is.
     */
    private function group(string $route): string
    {
        return str_contains($route, '.') ? Str::beforeLast($route, '.').'.*' : $route;
    }
}
