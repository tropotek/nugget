# CLAUDE.md

Guidance for Claude Code when working in this repository.

## Project overview

Nugget is a Laravel 13 app that records every asset transaction (crypto on exchanges, metals and other assets entered by hand), shows the current investment position, and produces an Australian capital gains report for each financial year. It is a rewrite of tkbtc (`~/Projects/Dev/tkbtc`). AUD is the only base currency.

The design and the task list live in `_notes/laravel-rewrite/` (gitignored). `README.md` there is the proposal and the task index; each task has its own file. Read the task file before starting a task, and tick it off there when it is done.

## Commands

There is no PHP with SQLite or Composer on the host. Until task 01 adds this repo's own Docker setup, run PHP through the wicket dev image:

```bash
docker run --rm -v "$PWD":/app -w /app wicket-dev:latest composer install
docker run --rm -v "$PWD":/app -w /app wicket-dev:latest php artisan migrate
docker run --rm -v "$PWD":/app -w /app wicket-dev:latest php artisan test
docker run --rm -v "$PWD":/app -w /app wicket-dev:latest vendor/bin/pint
```

`composer test` clears the config cache and runs the suite. Tests use PHPUnit (not Pest) against an in-memory SQLite database.

## Layout

- `app/`: application code, `App\` namespace.
- `data/`: runtime data, gitignored. The SQLite database is `data/nugget.sqlite` (WAL mode). In production this directory is the mounted volume.
- `database/migrations/`: schema.
- `tests/Feature`, `tests/Unit`: PHPUnit tests.
- `_notes/`: local planning docs, gitignored.
- `version.md`: the app version.

## Conventions

- Money and unit values are never floats. They are stored as fixed-scale strings and calculated with BCMath (task 20 adds the `Decimal` type).
- Holdings are derived from the transaction ledger, never stored.
- The parcel matcher and the tax calculator are written test-first.
- Real financial data (such as the BTC Markets CSV in tkbtc's `_notes/`) is never committed or copied into this repo. Tests use small anonymised fixtures.
- Run Pint before committing.
