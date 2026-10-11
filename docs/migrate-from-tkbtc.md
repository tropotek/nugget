# Migrate from tkbtc

`nugget:import-tkbtc` reads a tkbtc database and brings its users, exchanges, assets, balance log
and prices into Nugget. It reads tkbtc and never writes to it, and it is safe to run again: what is
already imported is passed over.

Run it against a new Nugget site, before the scheduler has synced anything.

## 1. Point Nugget at the tkbtc database

Put the connection in the `.env` beside `docker-compose-live.yml`, then start the site only:

```sh
TKBTC_DB_HOST=192.168.0.10
TKBTC_DB_PORT=3306
TKBTC_DB_DATABASE=tkbtc
TKBTC_DB_USERNAME=…
TKBTC_DB_PASSWORD=…
```

```sh
docker compose -f docker-compose-live.yml up -d app
```

The scheduler stays down until the ledger is in.

## 2. Import

```sh
docker compose -f docker-compose-live.yml exec app artisan nugget:import-tkbtc --dry-run
docker compose -f docker-compose-live.yml exec app artisan nugget:import-tkbtc
```

The dry run reports what is in tkbtc and changes nothing. The import is one database transaction:
if it fails, nothing is changed.

| tkbtc | Nugget |
|---|---|
| Users | Users, with the same password where Nugget can check it (bcrypt). Otherwise they use "Forgot your password?" |
| Exchanges | Exchanges, with the key and secret encrypted again. One Nugget has no driver for is not imported |
| Markets and assets | Assets |
| Balance log | Buys and sells flagged **estimated**, costed at the nearest price |
| Ticks | One price a day for each imported asset |

A user who is inactive in tkbtc and owns nothing there is not imported. The import ends by saying
whether the units held agree with tkbtc for each asset.

## 3. Replace the estimates with the exchange's own history

tkbtc recorded balances, not trades, so what the import makes are estimates. For BTC Markets,
download your transaction report from the exchange as a CSV and pipe it in. The number after
`backfill` is the exchange's id, which is in the address of its page in Nugget (1 on a new site):

```sh
docker compose -f docker-compose-live.yml exec -T app artisan nugget:backfill 1 --csv=php://stdin --dry-run < report.csv
docker compose -f docker-compose-live.yml exec -T app artisan nugget:backfill 1 --csv=php://stdin < report.csv
```

It removes the estimates for the assets the report covers and stores the report's own buys, sells,
deposits and withdrawals. A deposit of a coin has no cost in the report: it is stored at $0 and
flagged estimated, for you to put the real cost on.

The report is your financial history. Pipe it in as above and do not keep copies of it around.

## 4. Sync, check and start the scheduler

```sh
docker compose -f docker-compose-live.yml exec app artisan nugget:sync-exchanges
docker compose -f docker-compose-live.yml exec app artisan nugget:fetch-prices
```

Sign in and check, before anything else:

- each asset's units match the exchange and tkbtc,
- there is no balance mismatch under the bell on the top bar,
- the dashboard total is tkbtc's, give or take the price movement since its last tick.

Then start everything, take the `TKBTC_DB_*` lines out of `.env`, and make a
[backup](backup-restore.md):

```sh
docker compose -f docker-compose-live.yml up -d
```

Keep tkbtc running until you are satisfied. Nothing above changes it.
