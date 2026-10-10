# Nugget

Tracks investment transactions across exchanges, metals and other assets, shows the current position, and produces an Australian capital gains report for each financial year.

Built on Laravel 13 and SQLite. A rewrite of [tkbtc](https://github.com/tropotek/tkbtc).

Status: in development.

## Development

Requires Docker. PHP 8.4, Composer and Node are in the dev image.

```bash
cp .env.example .env
docker compose up -d                                  # builds the image, installs PHP and Node packages
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
docker compose exec app php artisan nugget:create-admin you@example.com   # the first admin
docker compose exec app composer test                 # run the test suite
```

- App: http://localhost:8778
- Vite dev server: http://localhost:5778
- Mailpit (all outgoing mail): http://localhost:8779

The ports are set in `.env`.
