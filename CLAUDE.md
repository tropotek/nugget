# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project overview

Nugget is a Laravel 13 app that records every asset transaction (crypto on exchanges, metals and other assets entered by hand), shows the current investment position, and produces an Australian capital gains report for each financial year. It is a rewrite of tkbtc (`~/Projects/Dev/tkbtc`). AUD is the only base currency.

The design and the task list live in `_notes/laravel-rewrite/` (gitignored). `README.md` there is the proposal and the task index; each task has its own file. Read the task file before starting a task, and tick it off there when it is done.

## Commands

There is no PHP with SQLite or Composer on the host. Everything runs in the dev containers (`docker-compose.yml`):

```bash
docker compose up -d                              # app, vite, scheduler, mailpit
docker compose exec app composer install
docker compose exec app php artisan migrate
docker compose exec app composer test
docker compose exec app vendor/bin/pint
docker compose build app                          # after changing the Dockerfile
```

| Service | Where | What |
|---|---|---|
| `app` | http://localhost:8778 | Octane on FrankenPHP with `--watch`, so PHP changes reload on save |
| `vite` | http://localhost:5778 | Vite dev server, hot reload |
| `scheduler` | — | `php artisan schedule:work` |
| `mailpit` | http://localhost:8779 | catches all outgoing mail |

The ports come from `APP_PORT`, `VITE_PORT` and `MAILPIT_PORT` in `.env`. Wicket's dev stack uses 8777 and 5777.

The containers load `.env` as real environment variables, so a change to `.env` needs `docker compose up -d` to recreate them, and `phpunit.xml` forces its test settings with `<server force="true">`.

`composer test` clears the config cache and runs the suite. Tests use PHPUnit (not Pest) against an in-memory SQLite database.

## Layout

- `app/`: application code, `App\` namespace.
- `data/`: runtime data, gitignored. The SQLite database is `data/nugget.sqlite` (WAL mode). In production this directory is the mounted volume.
- `database/migrations/`: schema.
- `Dockerfile`, `docker-compose.yml`, `docker/`: the dev image and containers. `docker/Caddyfile` is Octane's FrankenPHP site config.
- `resources/views/components/layouts/`: the page layouts. `app` is the signed-in shell (top bar, sidebar, page title, flash messages, footer) and `guest` the card used by the login pages; the rest are their parts.
- `/up` (`HealthController`, `App\Support\HealthStatus`): the health check the containers poll. 200 with the version when the database accepts a write, 503 otherwise.
- `config/navigation.php`: the sidebar menu, read by `App\Support\Navigation`.
- `resources/scss/`: `app.scss` is the entry (Bootstrap, the Minton default skin, then Nugget's styles) and `icons.scss` the icon fonts. `config/default/` and `custom/` are lifted from Minton (`~/Projects/Dev/Minton/Minton/resources/scss`).
- `resources/js/`: `app.js` is the entry. `minton/` holds the template's own scripts.
- `resources/fonts/`, `resources/images/`: icon fonts and images the SCSS refers to.
- `tests/Feature`, `tests/Unit`: PHPUnit tests.
- `_notes/`: local planning docs, gitignored.
- `version.md`: the app version.

## Conventions

- Money and unit values are never floats. They are stored as fixed-scale strings and calculated with BCMath (task 20 adds the `Decimal` type).
- Holdings are derived from the transaction ledger, never stored.
- The parcel matcher and the tax calculator are written test-first.
- Real financial data (such as the BTC Markets CSV in tkbtc's `_notes/`) is never committed or copied into this repo. Tests use small anonymised fixtures.
- A page is a Blade view wrapped in `<x-layouts.app title="…">` (or `<x-layouts.guest>`). The title is both the heading and the browser title; an `actions` slot puts buttons beside the heading.
- To add a page to the sidebar, add an entry to `config/navigation.php`. An entry shows only once its route exists, and only to users who pass its `can` ability.
- Flash a message with `->with('success' | 'error' | 'warning' | 'info', '…')`. The layouts show it.
- jQuery is imported only by the scripts in `resources/js/minton/` and is not a global. Nugget's own front-end code does not use it.
- Only the Minton partials Nugget uses are in the repo. To use another, copy it from Minton and import it in `app.scss`. In a copied partial, a `url()` path is relative to that partial's own file.
- Run Pint before committing.
