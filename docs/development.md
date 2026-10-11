# Development

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

The scheduler, which fetches prices and syncs the exchanges every 5 minutes, is not started in
development unless asked for: `docker compose --profile scheduler up -d`.

## Release

`bin/release v1.2.3` checks the code style and runs the tests, tags the commit and offers to push
the tag. The tag starts the one GitHub workflow, which builds the image and publishes it to
`ghcr.io/tropotek/nugget` as `1.2.3` and `latest`. The workflow runs no tests of its own. To build
the image yourself instead: `docker build -t nugget .`, and set `NUGGET_IMAGE=nugget`.

## These docs

The pages in `docs/` are built with MkDocs and published to GitHub Pages by the `docs` workflow
when a change to them reaches `main`. To read them as they will be published:

```sh
docker run --rm -p 8000:8000 -v "$PWD":/docs squidfunk/mkdocs-material
```

The page on [how the tax report is calculated](tax-report.md) states what the parcel matcher and
the tax calculator do. Change it when they change.
