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
docker compose exec app php artisan migrate                # apply new migrations; keeps the data
docker compose exec app php artisan db:seed                # add any missing development data (local only)
docker compose exec app composer fresh                     # wipe the database, migrate and seed
docker compose exec app php artisan nugget:rematch         # rebuild parcels and disposals (--asset=ID, --year=2025)
docker compose exec app php artisan nugget:create-admin you@example.com   # create an admin, or promote a user
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
- `app/Ledger/`: the calculations on the ledger: the parcel matcher and the holdings query.
- `data/`: runtime data, gitignored. The SQLite database is `data/nugget.sqlite` (WAL mode). In production this directory is the mounted volume.
- `database/migrations/`: schema.
- `Dockerfile`, `docker-compose.yml`, `docker/`: the dev image and containers. `docker/Caddyfile` is Octane's FrankenPHP site config.
- `resources/views/components/layouts/`: the page layouts. `app` is the signed-in shell (top bar, sidebar, page title, flash messages, footer) and `guest` the card used by the login pages; the rest are their parts.
- `/up` (`HealthController`, `App\Support\HealthStatus`): the health check the containers poll. 200 with the version when the database accepts a write, 503 otherwise.
- `config/nugget.php`: Nugget's own settings.
- `config/navigation.php`: the sidebar menu, read by `App\Support\Navigation`.
- `resources/scss/`: `app.scss` is the entry (Bootstrap, the Minton default skin, then Nugget's styles) and `icons.scss` the icon fonts. `config/default/` and `custom/` are lifted from Minton (`~/Projects/Dev/Minton/Minton/resources/scss`).
- `resources/js/`: `app.js` is the entry. `minton/` holds the template's own scripts; the rest are Nugget's, one small script per form, plus htmx.
- `resources/fonts/`, `resources/images/`: icon fonts and images the SCSS refers to.
- `tests/Feature`, `tests/Unit`: PHPUnit tests.
- `_notes/`: local planning docs, gitignored.
- `version.md`: the app version.

## Conventions

- Money and unit values are never floats. They are `App\Support\Decimal` objects, made with `Decimal::of('1.5')` from a string or an integer; a float throws.
- A money or unit column is a `string` column cast with `App\Casts\DecimalCast` (`::units()` for 8 places, `::aud()` for 2, `::scale($n)` otherwise). Never a `decimal` column: SQLite hands those back as floats. Never `SUM` one in SQL; load the rows and use `Decimal::sum()`.
- Holdings are derived from the transaction ledger, never stored. Read them with `App\Ledger\Holdings` (`forUser`, `forAsset`), which gives an `App\Ledger\Holding` per asset: units, cost base and average cost. It does no authorising.
- An asset's symbol and unit decide which assets transfers join, so they are fixed once it has a transaction (`$asset->hasTransactions()`), and it can then be archived but not deleted. An archived asset is left off the lists; its ledger and its holding are unchanged.
- An asset's price source is one choice, `App\Enums\PricedBy` (exchange, feed or none), read with `$asset->pricedBy()`. A form saves only the chosen source's fields and nulls the others.
- A transaction's `units` are positive except on an adjustment; use `signedUnits()` for the change to a holding. Its `total` is the AUD cost with the fee for units coming in, and the AUD proceeds less the fee for units going out.
- An amount typed into a form is validated with `App\Rules\Amount` (a plain decimal string with a limit on its places) and turned into a `Decimal`. Any arithmetic on amounts in JavaScript is done in `BigInt`, never in floats, and only to preview what the server works out.
- Which amounts a transaction type carries is on `TransactionType` (`hasPrice()`, `hasFee()`, `hasTotal()`); the rest are saved as zero. `Transaction::totalOf()` is the total when none is given.
- A transaction from an exchange is locked (`$transaction->isLocked()`): only its notes change and it is never deleted by hand.
- A date and time typed into a form is in the user's timezone: parse it with the user's `timezone` and store it in UTC.
- Parcels and disposals are worked out from the transactions. Only `App\Ledger\ParcelMatcher` writes them; nothing else creates, edits or deletes one.
- Change the ledger through `App\Ledger\Ledger` (`record`, `amend`, `remove`, `pick`, `unpick`): it saves the change and rematches in one database transaction, and throws if the ledger cannot support the change. Code that saves a `Transaction` directly, such as a bulk import, must call `ParcelMatcher::rematch($asset)` itself afterwards. A rematch rebuilds the asset and the same user's other assets with its symbol and unit, which transfers join.
- A rematch gives every parcel a new id. Never store a parcel id outside the matcher.
- The stored parcels show what is left now. What a sale could use at its own moment comes from `ParcelMatcher::openBefore($sale)`, which replays the ledger up to the sale and rolls back: the parcels it returns are not rows, so read them and never save them. `App\Ledger\ParcelChoice::forSale()` turns them into the lines of the parcel picker.
- The parcel matcher and the tax calculator are written test-first.
- Real financial data (such as the BTC Markets CSV in tkbtc's `_notes/`) is never committed or copied into this repo. Tests use small anonymised fixtures.
- A page is a Blade view wrapped in `<x-layouts.app title="…">` (or `<x-layouts.guest>`). The title is both the heading and the browser title; an `actions` slot puts buttons beside the heading.
- To add a page to the sidebar, add an entry to `config/navigation.php`. An entry shows only once its route exists, and only to users who pass its `can` ability.
- Flash a message with `->with('success' | 'error' | 'warning' | 'info', '…')`. The layouts show it.
- A list that is filtered, sorted or paged swaps by htmx: its table is a `_table` partial inside an element with an id and `hx-boost`, and the controller returns the partial alone when `HX-Target` is that id (see `transactions/_table.blade.php`). It must still work as plain links and a GET form.
- Search a user's transactions with `App\Ledger\TransactionSearch` (`fromInput()` reads a query string and passes over what it does not understand; `query()` is the builder). A string money or unit column is ordered in SQL by `CAST(… AS REAL)`, for the order only.
- jQuery is imported only by the scripts in `resources/js/minton/` and is not a global. Nugget's own front-end code does not use it.
- Only the Minton partials Nugget uses are in the repo. To use another, copy it from Minton and import it in `app.scss`. In a copied partial, a `url()` path is relative to that partial's own file.
- Every page needs a signed-in user: put its route inside the `auth` group in `routes/web.php`. The login and password reset routes are in `routes/auth.php`. There is no registration.
- Login and password recovery are throttled per email and IP address together, with the limits in `config/nugget.php`. Recovery and reset answer the same whether or not the email has an account; keep it that way when changing them.
- Dates are stored in UTC and shown in the reader's timezone. In a view use `<x-date :value="…" />` (it takes a `format`); elsewhere use `App\Support\LocalTime::of($date)`. Never format a stored date directly.
- Emails are stored in lower case and SQLite compares them exactly, so lower-case an email before looking a user up by it.
- `laravel/breeze` is a dev dependency used only as a source of stubs to copy from `vendor/laravel/breeze/stubs/default`. Never run `breeze:install`: it would replace the layout and the Vite setup with Tailwind.
- A user's `role` (`App\Enums\Role`: admin or member) and `active` flag are not mass assignable; set them with `forceFill`. Check for an admin with `$user->isAdmin()` or the `admin` Gate ability.
- Users are deactivated, never deleted. An inactive user cannot sign in or reset a password, and is told nothing that an unknown email would not be.
- A model that belongs to a user implements `App\Models\Contracts\Owned`. That gives it `App\Policies\OwnerPolicy` with no policy class of its own: the owner only, with no admin bypass. Authorise every read and write of one with `Gate::authorize`.
- An admin-editable setting is read with `Settings::get('key')` (`App\Facades\Settings`). To add one, add it to `definitions()` in `App\Support\Settings` with its default, type and rules, and a field to `resources/views/settings/edit.blade.php`. An unknown key throws.
- A financial year is named by the year it ends in: 2025 is 2024–25. Use `App\Support\FinancialYear` for its dates, which depend on the user's timezone.
- `APP_ENV` is `local` in development, `testing` under PHPUnit and `production` when live. Check it with `app()->environment(…)`, never by reading `.env`.
- `Database\Seeders\DatabaseSeeder` holds the development data. It only adds what is missing and does nothing in production. When a task adds a table, add realistic made-up rows for it there, owned by the seeded users.
- Run Pint before committing.
