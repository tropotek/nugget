# Nugget

[![Docs](https://img.shields.io/badge/docs-tropotek.github.io%2Fnugget-b9770a)](https://tropotek.github.io/nugget/)

Records every asset transaction (crypto on exchanges, metals and anything else entered by hand),
shows what the holdings are worth now, and produces an Australian capital gains report for each
financial year. Built on Laravel, shipped as one Docker image (FrankenPHP + SQLite) with
everything it stores in a single `data/` directory. A rewrite of
[tkbtc](https://github.com/tropotek/tkbtc).

## Quick start

Copy [`docker-compose-live.yml`](docker-compose-live.yml) into an empty directory, then:

```sh
docker compose -f docker-compose-live.yml up -d
docker compose -f docker-compose-live.yml exec app artisan nugget:create-admin you@example.org
```

Open <http://localhost:8080>.

## Documentation

Full docs at **<https://tropotek.github.io/nugget/>**:

| Guide | For |
|---|---|
| [Installation](docs/installation.md) | Running Nugget with Docker Compose, HTTPS, upgrading |
| [Configuration](docs/configuration.md) | Environment variables and the settings in the site |
| [Backup and restore](docs/backup-restore.md) | Backing up the `data/` directory and restoring it |
| [The tax report](docs/tax-report.md) | How the capital gains report is calculated, and its limits |
| [API and Claude (MCP)](docs/api.md) | Tokens, the JSON endpoints and connecting an assistant |
| [Migrate from tkbtc](docs/migrate-from-tkbtc.md) | Importing a tkbtc site and its exchange history |
| [Development](docs/development.md) | Working on Nugget, running tests, releasing |

The tax report is a working paper, not tax advice.

## Licence

MIT. See [LICENSE](LICENSE).
