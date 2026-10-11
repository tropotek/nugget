# Nugget

Tracks investment transactions across exchanges, metals and other assets, shows the current position, and produces an Australian capital gains report for each financial year.

Built on Laravel 13 and SQLite. A rewrite of [tkbtc](https://github.com/tropotek/tkbtc).

## Install

Requires Docker. There is no registry: the image is built from this repository.

```bash
git clone <this repository> nugget-src && cd nugget-src
docker build -t nugget .                      # the shipped image, about 2 minutes

mkdir -p ~/Projects/Live/nugget && cp docker-compose-live.yml ~/Projects/Live/nugget/
cd ~/Projects/Live/nugget
docker compose -f docker-compose-live.yml up -d
docker compose -f docker-compose-live.yml exec app artisan nugget:create-admin you@example.org
```

Then sign in at http://localhost:8080. There is no registration: the admin adds any other accounts
under **Users**.

It starts two containers of the one image: `app`, the site, and `scheduler`, which fetches prices
and syncs the exchanges every 5 minutes. On its first start the site makes the database, runs the
migrations and generates its key. Everything it keeps is in `data/` beside the compose file; see
[Backup and restore](docs/backup-restore.md).

To bring a tkbtc site over instead of starting empty, put the `TKBTC_DB_*` settings in `.env` and
run `docker compose -f docker-compose-live.yml exec app artisan nugget:import-tkbtc` (`--dry-run`
first).

## Configure

Settings go in a `.env` file beside `docker-compose-live.yml`. All are optional. After a change,
run `docker compose -f docker-compose-live.yml up -d` to recreate the containers.

| Setting | Default | What |
|---|---|---|
| `NUGGET_PORT` | `8080` | the host port the site is published on |
| `APP_URL` | `http://localhost:8080` | the address people reach it at; used in emails |
| `TRUSTED_PROXIES` | none | the reverse proxy in front whose forwarded headers are believed: its address, a comma list, or `*` |
| `SESSION_SECURE_COOKIE` | off | `true` when the site is served over HTTPS |
| `NUGGET_TLS_CERT`, `NUGGET_TLS_KEY` | none | a certificate and its key, for HTTPS from the container itself |
| `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS` | mail is written to the log | SMTP, for password reset emails |
| `APP_KEY` | generated into `data/private/.app-key` | the key that encrypts sessions and exchange keys |
| `NUGGET_LOGIN_MAX_ATTEMPTS`, `NUGGET_LOGIN_LOCKOUT_MINUTES` | `5`, `15` | failed logins before an email is locked out from an address, and for how long |
| `NUGGET_PRICE_INTRADAY_HOURS` | `48` | how long the 5-minute prices are kept; each day's last price is kept for good |
| `NUGGET_SYNC_BALANCE_TOLERANCE` | `0.000001` | how many units an exchange's balance and the ledger may differ by before you are told |
| `NUGGET_NOTIFICATIONS_KEEP_READ_DAYS` | `30` | how long a read notification is kept |
| `NUGGET_API_PER_MINUTE` | `60` | requests a minute for one API token |
| `LOG_LEVEL`, `LOG_DAILY_DAYS` | `info`, `30` | what is logged to `data/logs/`, and for how many days |
| `TKBTC_DB_HOST`, `TKBTC_DB_PORT`, `TKBTC_DB_DATABASE`, `TKBTC_DB_USERNAME`, `TKBTC_DB_PASSWORD` | none | the tkbtc database the import reads |

The rest is set in the site itself. An admin sets the site name, the parcel matching method and
the first financial year reported under **Settings**. Each user sets their timezone on **Profile**,
which decides the day a sale falls on and so its financial year.

### HTTPS

Port 8080 is plain HTTP, which is enough on a private network. For HTTPS there are two ways, and
both want `APP_URL` set to the `https://` address and `SESSION_SECURE_COOKIE=true`.

**A reverse proxy in front** (Caddy, nginx) that ends TLS and sends to `NUGGET_PORT`. Tell Nugget
to believe it, or its links and redirects come out as `http://`:

```bash
TRUSTED_PROXIES=192.168.0.20        # the proxy's address, a comma list, or * for any
```

A Caddy site for it is two lines:

```
nugget.example.org {
    reverse_proxy 192.168.0.7:8080
}
```

**A certificate of your own**, served by the container. Put the certificate (with its chain) and
the key in `certs/` beside the compose file, readable by uid 1000, uncomment the two lines marked
`own certificate` in `docker-compose-live.yml`, and set:

```bash
NUGGET_TLS_CERT=/certs/fullchain.pem
NUGGET_TLS_KEY=/certs/privkey.pem
NUGGET_TLS_PORT=443                 # the host port; 443 is the default
```

The container then answers HTTPS on its port 8443 as well as HTTP on 8080. It reads the files when
it starts, so restart it after renewing the certificate. It stops with a message if either file is
missing or unreadable.

## Upgrade

Take a [backup](docs/backup-restore.md) first: a migration changes the database in place.

```bash
cd nugget-src && git pull
docker build -t nugget .
cd ~/Projects/Live/nugget
docker compose -f docker-compose-live.yml up -d --force-recreate
```

The site runs any new migrations as it starts, and the scheduler waits for it. `curl
http://localhost:8080/up` answers with the version once it is up.

## The tax report

[How the tax report is calculated](docs/tax-report.md) sets out the rules it follows and what it
leaves out. It is a working paper, not tax advice.

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

## API and Claude (MCP)

Nugget has a read-only JSON API and an MCP server over the same figures, so that a program, or an assistant such as Claude, can read your holdings and your tax report. Neither can change anything, and neither ever returns an exchange key or secret.

### 1. Make a token

Sign in, open **Profile**, and under **API tokens** give a token a name (what it is for, such as "Claude") and press **Make a token**. Copy it from the box that appears: it is shown once. Revoke it from the same list and it stops working at once.

### 2. Use the API

Every endpoint is a GET under `/api/v1` and takes the token as a bearer token:

```bash
curl -H "Authorization: Bearer YOUR-TOKEN" -H "Accept: application/json" https://nugget.example.com/api/v1/holdings
```

| Endpoint | Returns |
|---|---|
| `/api/v1/me` | whose token it is, and their timezone |
| `/api/v1/holdings` | each asset held, with units, cost base, price, value and unrealised gain, and the portfolio totals |
| `/api/v1/assets/{id}/parcels` | an asset's open parcels |
| `/api/v1/transactions` | the ledger, paged. Filters: `asset`, `type`, `source`, `from`, `to`, `estimated`; paging: `page`, `per_page` |
| `/api/v1/tax/years` | the financial years: every open one, and the closed ones with disposals |
| `/api/v1/tax/{year}` | a year's capital gains report. The year is the one it ends in: `2025` is 2024–25 |
| `/api/v1/issues` | balance mismatches, and open parcels with no cost base |

Amounts are decimal strings, never JSON numbers. Dates are ISO 8601 in your own timezone. A token may make 60 requests a minute.

### 3. Connect Claude

Register the MCP server with Claude Code, with the same token:

```bash
claude mcp add --transport http nugget https://nugget.example.com/mcp --header "Authorization: Bearer YOUR-TOKEN"
```

Use your own address in place of `nugget.example.com` (in development, `http://localhost:8778/mcp`). Add `--scope user` to have it in every project. `claude mcp list` shows whether it connected, and `claude mcp remove nugget` takes it away.

Claude then has six tools, one for each endpoint above: `holdings`, `parcels`, `transactions`, `tax_years`, `tax_report` and `issues`. Ask it something like "what is my net capital gain for 2024–25, and does any of it rest on estimated costs?".

The tax report is a working paper, not tax advice. It takes in crypto only unless you choose other asset classes on the tax report page.
