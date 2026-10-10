# Nugget

Tracks investment transactions across exchanges, metals and other assets, shows the current position, and produces an Australian capital gains report for each financial year.

Built on Laravel 13 and SQLite. A rewrite of [tkbtc](https://github.com/tropotek/tkbtc).

Status: in development.

## Development

Requires PHP 8.4 with `pdo_sqlite` and `bcmath`, Composer and Node.

```bash
composer setup    # install, create .env, generate key, create and migrate data/nugget.sqlite, build assets
composer test     # run the test suite
```
