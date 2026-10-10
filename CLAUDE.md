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
docker compose exec app php artisan nugget:fetch-prices    # fetch and store every active asset's price now (--asset=ID)
docker compose exec app php artisan nugget:prune-prices    # delete intraday prices older than 48 hours
docker compose exec app php artisan nugget:sync-exchanges  # sync every active exchange now (--exchange=ID, --all-trades)
docker compose exec app php artisan nugget:prune-notifications   # delete read notifications older than 30 days
docker compose exec app composer test
docker compose exec app vendor/bin/pint
docker compose build app                          # after changing the Dockerfile
```

| Service | Where | What |
|---|---|---|
| `app` | http://localhost:8778 | Octane on FrankenPHP with `--watch`, so PHP changes reload on save |
| `vite` | http://localhost:5778 | Vite dev server, hot reload |
| `scheduler` | — | `php artisan schedule:work`: fetches prices from the real exchange and feed and syncs every active exchange every 5 minutes, and prunes prices and read notifications nightly |
| `mailpit` | http://localhost:8779 | catches all outgoing mail |

The ports come from `APP_PORT`, `VITE_PORT` and `MAILPIT_PORT` in `.env`. Wicket's dev stack uses 8777 and 5777.

The containers load `.env` as real environment variables, so a change to `.env` needs `docker compose up -d` to recreate them, and `phpunit.xml` forces its test settings with `<server force="true">`.

`composer test` clears the config cache and runs the suite. `phpunit.xml` raises `memory_limit` to 512M for it, because dompdf loads a whole font on top of what the suite holds. Tests use PHPUnit (not Pest) against an in-memory SQLite database.

## Layout

- `app/`: application code, `App\` namespace.
- `app/Ledger/`: the calculations on the ledger: the parcel matcher, the holdings query, valuations and the value series.
- `app/Api/`: what each API endpoint and MCP tool returns, as plain arrays.
- `app/Tax/`: the capital gains report: the calculator, a section's totals and the year's summary.
- `app/Prices/`: where prices are read from (an exchange's ticker, the Swissquote feed).
- `app/Exchanges/`: Nugget's own exchange drivers and the registry that makes them.
- `tests/Fixtures/`: recorded API responses. `tests/Fakes/`: stand-ins for outside services.
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

- Money and unit values are never floats. They are `App\Support\Decimal` objects, made with `Decimal::of('1.5')` from a string or an integer; a float throws. The one way in for a float is `Decimal::fromFloat($value, $scale)`, for a number an outside API hands over as one (the Swissquote feed's JSON does).
- A money or unit column is a `string` column cast with `App\Casts\DecimalCast` (`::units()` for 8 places, `::aud()` for 2, `::scale($n)` otherwise). Never a `decimal` column: SQLite hands those back as floats. Never `SUM` one in SQL; load the rows and use `Decimal::sum()`.
- Show a unit price or a cost per unit with `$decimal->formatPrice()`, which keeps the places a price under a dollar needs. Show an AUD total with `format()`.
- Holdings are derived from the transaction ledger, never stored. Read them with `App\Ledger\Holdings` (`forUser`, `forAsset`), which gives an `App\Ledger\Holding` per asset: units, cost base and average cost. It does no authorising.
- Value holdings with `App\Ledger\Valuations` (`forUser`, `forAsset`), which gives an `App\Ledger\Valuation` per asset: the holding at its latest price, valued at the bid. Its value, gain and percentage are null when the asset has no price. Total them with `PortfolioValue::of()`, which counts only assets with `in_total` and leaves an unpriced holding out of both the value and the cost base.
- Value over time comes from `App\Ledger\ValueSeries` (`daily`, `intraday`), for one asset or a user's `in_total` assets. It uses the units held at each point, read from the transactions, never today's units.
- A chart's range is an `App\Enums\ChartRange` (48h from the intraday prices; 1m, 1y and all from the day prices). A chart is any element with `data-chart` and a `data-points` attribute of `[time, amount]` pairs, drawn by `resources/js/chart.js` with ApexCharts, which is loaded only on a page that has one. A moment's time is `LocalTime::asUtcMs()`, and a day row's is its own timestamp. `App\Prices\PriceChart` and `App\Ledger\ValueChart` make the points.
- Show a gain or loss with `<x-gain :value="…" :percent="…" />`.
- A small line of amounts over time is `<x-sparkline :amounts="…" label="…" />`: an inline SVG with no script. `App\Support\Sparkline` is the one place a `Decimal` becomes a float, to place the line.
- An asset's symbol and unit decide which assets transfers join, so they are fixed once it has a transaction (`$asset->hasTransactions()`), and it can then be archived but not deleted. An archived asset is left off the lists; its ledger and its holding are unchanged.
- An asset's price source is one choice, `App\Enums\PricedBy` (exchange, feed or none), read with `$asset->pricedBy()`. A form saves only the chosen source's fields and nulls the others.
- A transaction's `units` are positive except on an adjustment; use `signedUnits()` for the change to a holding. Its `total` is the AUD cost with the fee for units coming in, and the AUD proceeds less the fee for units going out.
- An amount typed into a form is validated with `App\Rules\Amount` (a plain decimal string with a limit on its places) and turned into a `Decimal`. Any arithmetic on amounts in JavaScript is done in `BigInt`, never in floats, and only to preview what the server works out.
- Which amounts a transaction type carries is on `TransactionType` (`hasPrice()`, `hasFee()`, `hasTotal()`); the rest are saved as zero. `Transaction::totalOf()` is the total when none is given.
- A transaction from an exchange is locked (`$transaction->isLocked()`): only its notes change and it is never deleted by hand.
- A date and time typed into a form is in the user's timezone: parse it with the user's `timezone` and store it in UTC.
- A price (`App\Models\Price`) is the AUD bid and ask for one unit of an asset. An intraday row's `at` is the moment it was fetched. A day row is one per day in the owner's timezone and its `at` is that date at 00:00:00, made with `Price::day()`: a date, not a moment, so read it with `->at->toDateString()` and never through `LocalTime` or `<x-date>`.
- Store a quote with `App\Prices\PriceRecorder::record($asset, $quote, $at)`, which writes the intraday row and replaces the day row together. Nothing else writes a price.
- Scheduled commands are listed in `routes/console.php`, each `withoutOverlapping`. A command that works through many assets or exchanges catches each one's failure, logs it and goes on.
- An asset's latest price is its newest day row (`Price::latestPerAsset()`), not its newest intraday row: intraday rows are pruned.
- Read an asset's current price with `App\Prices\PriceSources::quote($asset)`: an `App\Prices\Quote` (bid and ask) or null. A price source never throws: it logs a warning and returns null.
- Each exchange has Nugget's own driver in `App\Exchanges\Drivers`, written against that exchange's API with Laravel's HTTP client. There is no ccxt and no other exchange library. Get an exchange's driver with `App\Exchanges\ExchangeDrivers::for($exchange)`.
- What an exchange can do is said by the interfaces in `App\Exchanges\Contracts` that its driver implements (`QuotesPrices`, `ListsMarkets`, `ChecksConnection`, `ReportsBalances` and `ReportsTrades` so far). Add each new one to `ExchangeDrivers::CAPABILITIES` with a label, which the exchange page lists. Check with `instanceof` before a call: an exchange that lacks one is a normal case, not an error.
- To support another exchange, write its driver and add it to `ExchangeDrivers::DRIVERS`. To use a new kind of call, add an interface to `Contracts` and implement it on the drivers that can.
- Read an exchange's markets through `App\Exchanges\Markets` (`for`, `inAud`, `prices`), not from the driver: it caches the list by driver for a day and never throws. An empty list means it is not known, so nothing is refused because of it. Cache plain arrays, not objects: `cache.serializable_classes` is off.
- An exchange's key and secret are never put on a page or in the session, in whole or in part. Its form fields are empty on an edit and a blank one keeps what is saved. Its driver is fixed once it is added, and it is deleted only when no asset is priced by it.
- A private call to BTC Markets goes through `BtcMarkets::signed()`. Say why a call to an exchange failed with `App\Exchanges\Failure::reason($e)`, which never includes the request.
- Sync an exchange with `App\Exchanges\Sync\ExchangeSync::run($exchange)`, which runs the steps in `App\Exchanges\Sync` and never throws. It returns a `SyncOutcome` (`Synced`, `Failed`, or `Busy` when another sync of that exchange holds the lock) and writes the exchange's `last_synced_at`, `last_error` and `last_sync_note` (the steps its driver could not do). A step takes the exchange and its driver, returns a note when it is skipped and throws when it fails.
- What an exchange said the account held at its last sync is in `exchange_balances` (`$exchange->balances`), AUD included, with no row for a symbol not held. Only `SyncBalances` writes them. They are the exchange's figures: a sync never changes the ledger to match.
- An exchange's trades are stored by `App\Exchanges\Sync\SyncTrades`: a buy or a sell with source `exchange` and the exchange's trade id as `external_id`, on the first-added asset of that exchange with the trade's market. `exchanges.trade_cursor` is the last trade read. A trade on a market with no asset, or not priced in AUD, is passed over and noted, never guessed at.
- The assets held on an exchange are `$exchange->tradedAssets()` (by market id; the first added where two share a market). The sync stores trades on them and compares their holdings with the exchange's balances. An asset that only shares a market for its price is not on the exchange.
- Where an exchange's balance and the ledger differ by more than `nugget.sync.balance_tolerance`, the owner has one `App\Notifications\BalanceMismatch` database notification per asset, kept in step by `App\Exchanges\Sync\ReconcileBalances` and deleted when they agree. Nothing settles it but a transaction the owner saves; `BalanceMismatch::links()` gives the filled-in forms.
- A notification to a user is a database notification that implements `App\Notifications\Contracts\Presentable`: `present($data)` gives its title, text, url and icon from the stored data alone, since it is shown long after it is sent. Read a user's with `App\Support\NotificationFeed`. The top bar's bell and the browser notification both come from that, so a new type needs nothing else.
- In a Blade view use `@php … @endphp` blocks, not the inline `@php(…)` form: mixing the two in one file breaks its compilation.
- A market id is the exchange's own (`BTC-AUD` on BTC Markets). A feed symbol is Swissquote's (`XAU/AUD`).
- No test touches the network, and `Tests\TestCase` makes a call that is not faked throw: fake every outside call with `Http::fake()` and a recorded response from `tests/Fixtures/`. Record a new fixture from the real API, and only from public or made-up data.
- Parcels and disposals are worked out from the transactions. Only `App\Ledger\ParcelMatcher` writes them; nothing else creates, edits or deletes one.
- Change the ledger through `App\Ledger\Ledger` (`record`, `amend`, `remove`, `pick`, `unpick`): it saves the change and rematches in one database transaction, and throws if the ledger cannot support the change. Code that saves a `Transaction` directly, such as a bulk import, must call `ParcelMatcher::rematch($asset)` itself afterwards. A rematch rebuilds the asset and the same user's other assets with its symbol and unit, which transfers join.
- A rematch gives every parcel a new id. Never store a parcel id outside the matcher.
- The stored parcels show what is left now. What a sale could use at its own moment comes from `ParcelMatcher::openBefore($sale)`, which replays the ledger up to the sale and rolls back: the parcels it returns are not rows, so read them and never save them. `App\Ledger\ParcelChoice::forSale()` turns them into the lines of the parcel picker.
- A user's capital gains for a year come from `App\Tax\CgtCalculator::forYear($user, $year, $carriedForward)`: an `App\Tax\CgtReport` with the disposals by `App\Enums\TaxSection` (crypto, other) and the `CgtSummary`. Every screen, file and API that shows the report reads this and works nothing out itself. What the user chose for a year is in `App\Models\TaxYear`: the losses carried forward and the asset classes the report takes in (crypto only unless chosen: `nugget.tax.default_asset_classes`). `forYear()` uses both when it is given neither, and a class left out is out of the sections and the summary alike. The arithmetic is in `CgtSummary::of()`, which has no database.
- A PDF is made with dompdf from a Blade view of plain HTML with its own stylesheet (`App\Tax\TaxReportPdf`, `tax-report/pdf.blade.php`): no remote files, no script. Pass the user to `LocalTime::of()` there rather than relying on who is signed in.
- A CSV download is streamed with `response()->streamDownload()` from a generator of rows (`App\Tax\TaxReportCsv`): dates as `Y-m-d` in the user's timezone, amounts as plain numbers, a byte order mark first, and any text the user typed that starts like a formula given a leading apostrophe.
- The parcel matcher and the tax calculator are written test-first.
- Real financial data (such as the BTC Markets CSV in tkbtc's `_notes/`) is never committed or copied into this repo. Tests use small anonymised fixtures.
- A page is a Blade view wrapped in `<x-layouts.app title="…">` (or `<x-layouts.guest>`). The title is both the heading and the browser title; an `actions` slot puts buttons beside the heading.
- To add a page to the sidebar, add an entry to `config/navigation.php`. An entry shows only once its route exists, and only to users who pass its `can` ability.
- Flash a message with `->with('success' | 'error' | 'warning' | 'info', '…')`. The layouts show it.
- A list that is filtered, sorted or paged swaps by htmx: its table is a `_table` partial inside an element with an id and `hx-boost`, and the controller returns the partial alone when `HX-Target` is that id (see `transactions/_table.blade.php`). It must still work as plain links and a GET form.
- A part of a page that keeps itself fresh carries `hx-get`, `hx-trigger="every 60s"` and `hx-swap="outerHTML"` on its own element, and its controller returns the partial alone for that `HX-Target` (see `dashboard/_totals.blade.php`).
- Search a user's transactions with `App\Ledger\TransactionSearch` (`fromInput()` reads a query string and passes over what it does not understand; `query()` is the builder). A string money or unit column is ordered in SQL by `CAST(… AS REAL)`, for the order only.
- The TradingView page (`resources/js/tradingview.js`) is the only place a script is loaded from another site. Everything else is bundled by Vite.
- jQuery is imported only by the scripts in `resources/js/minton/` and is not a global. Nugget's own front-end code does not use it.
- Only the Minton partials Nugget uses are in the repo. To use another, copy it from Minton and import it in `app.scss`. In a copied partial, a `url()` path is relative to that partial's own file.
- The JSON API is `routes/api.php`: the `/api/v1` group, GET routes only, reached with a Sanctum personal access token that has the `read` ability and never with the web session. Put an endpoint inside that group. Its data is worked out by a class in `App\Api` that returns a plain array for one user, built on the code the screens use; the controller only wraps it in `data`. Write values with `App\Api\Format`: amounts as decimal strings, never JSON numbers, and dates as ISO 8601 in the user's timezone. No response holds an exchange key or secret. Errors are JSON. Tokens are made and revoked on the profile page and shown once.
- Every page needs a signed-in user: put its route inside the `auth` group in `routes/web.php`. The login and password reset routes are in `routes/auth.php`. There is no registration.
- Login and password recovery are throttled per email and IP address together, with the limits in `config/nugget.php`. Recovery and reset answer the same whether or not the email has an account; keep it that way when changing them.
- Dates are stored in UTC and shown in the reader's timezone. In a view use `<x-date :value="…" />` (it takes a `format`); elsewhere use `App\Support\LocalTime::of($date)`. Never format a stored date directly.
- Emails are stored in lower case and SQLite compares them exactly, so lower-case an email before looking a user up by it.
- `laravel/breeze` is a dev dependency used only as a source of stubs to copy from `vendor/laravel/breeze/stubs/default`. Never run `breeze:install`: it would replace the layout and the Vite setup with Tailwind.
- A user's `role` (`App\Enums\Role`: admin or member) and `active` flag are not mass assignable; set them with `forceFill`. Check for an admin with `$user->isAdmin()` or the `admin` Gate ability.
- Users are deactivated, never deleted. An inactive user cannot sign in or reset a password, and is told nothing that an unknown email would not be.
- A model that belongs to a user implements `App\Models\Contracts\Owned`. That gives it `App\Policies\OwnerPolicy` with no policy class of its own: the owner only, with no admin bypass. Authorise every read and write of one with `Gate::authorize`.
- An admin-editable setting is read with `Settings::get('key')` (`App\Facades\Settings`). To add one, add it to `definitions()` in `App\Support\Settings` with its default, type and rules, and a field to `resources/views/settings/edit.blade.php`. An unknown key throws.
- A financial year is named by the year it ends in: 2025 is 2024–25. Use `App\Support\FinancialYear` for its dates, which depend on the user's timezone. The years in which a user has disposals come from `App\Ledger\ReportYears` (`forUser`, `open`): a year is open, and has a tax report, when it is the `first_report_year` setting or later.
- `APP_ENV` is `local` in development, `testing` under PHPUnit and `production` when live. Check it with `app()->environment(…)`, never by reading `.env`.
- `Database\Seeders\DatabaseSeeder` holds the development data. It only adds what is missing and does nothing in production. When a task adds a table, add realistic made-up rows for it there, owned by the seeded users.
- Run Pint before committing.
