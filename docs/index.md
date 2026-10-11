# Nugget

Nugget records every asset transaction (crypto on exchanges, metals and anything else entered by
hand), shows what the holdings are worth now, and produces an Australian capital gains report for
each financial year. It is built on Laravel and ships as one Docker image (FrankenPHP and SQLite),
with everything it stores in a single `data/` directory. AUD is the only currency.

## What it does

- **A ledger, not a balance.** Every buy, sale, transfer, reward and find is a transaction.
  Holdings and their cost base are worked out from them, never typed in.
- **Exchange sync.** Trades and balances are read from BTC Markets every 5 minutes, and you are
  told when the exchange and the ledger disagree.
- **Prices and value over time.** One price a day is kept for good, so the dashboard charts what
  the portfolio was worth on any day.
- **Capital gains report.** Parcels are matched oldest first or lowest gain first, or by hand for
  any one sale. The report is a page, a PDF and a CSV, with the 12-month discount and carried
  losses applied.
- **Read-only API and MCP server.** A program, or an assistant such as Claude, can read the
  holdings and the report with a token you can revoke.
- **Two containers of one image.** The site and its scheduler. Migrations run on start, and a
  backup is one directory.

## Documentation

| Guide | For |
|---|---|
| [Installation](installation.md) | Running Nugget with Docker Compose, HTTPS, upgrading |
| [Configuration](configuration.md) | Environment variables and the settings in the site |
| [Backup and restore](backup-restore.md) | Backing up the `data/` directory and restoring it |
| [The tax report](tax-report.md) | How the capital gains report is calculated, and its limits |
| [API and Claude (MCP)](api.md) | Tokens, the JSON endpoints and connecting an assistant |
| [Migrate from tkbtc](migrate-from-tkbtc.md) | Importing a tkbtc site and its exchange history |
| [Development](development.md) | Working on Nugget, running tests, releasing |

The tax report is a working paper for you and your accountant, not tax advice.
