# Backup and restore

Everything Nugget keeps is in the `data/` directory beside `docker-compose-live.yml`:

| Path | What |
|---|---|
| `data/nugget.sqlite` | the database, with `nugget.sqlite-wal` and `nugget.sqlite-shm` beside it while the site runs |
| `data/private/.app-key` | the key that encrypts every exchange's API key and secret |
| `data/logs/` | daily logs, kept 30 days |

Back up the whole directory, not the database file alone. Without `.app-key` the exchange keys in
the database cannot be read and have to be entered again. If `APP_KEY` is set in `.env` instead,
keep that `.env` with the backup.

## Backup

Stop the site, archive `data/`, and start it again. Stopping first is what makes the copy whole:
the database is in WAL mode, so a copy of `nugget.sqlite` taken while the site runs can miss the
newest writes.

```sh
cd ~/Projects/Live/nugget
docker compose -f docker-compose-live.yml stop
mkdir -p backups
tar -czf backups/nugget-$(date +%F).tgz data
docker compose -f docker-compose-live.yml start
```

The site is down for a few seconds. Prices are fetched every 5 minutes, so nothing is missed that
the next run does not pick up.

A nightly host crontab entry (in a crontab, `%` is written `\%`):

```cron
15 2 * * *  cd ~/Projects/Live/nugget && docker compose -f docker-compose-live.yml stop && tar -czf backups/nugget-$(date +\%F).tgz data; docker compose -f docker-compose-live.yml start
```

Copy the archives off the machine. They hold your whole financial history and, encrypted, your
exchange keys: keep them somewhere private.

## Restore

On a fresh machine, or to replace everything:

```sh
cd ~/Projects/Live/nugget
docker compose -f docker-compose-live.yml down
mv data data.old                      # keep until the restore checks out
tar -xzf backups/nugget-2026-10-11.tgz
docker compose -f docker-compose-live.yml up -d
```

The site migrates the restored database on start, so an older backup works with a newer image. A
backup made by a newer image than the one running does not: build the newer image first.

Then check it:

- `curl http://localhost:8080/up` answers `{"version":"…","db":"ok"}`.
- Sign in and look at the dashboard totals.
- On **Exchanges**, press **Sync now**. If it fails with a key error, the `.app-key` is not
  the one the backup was made with.

## Before an upgrade

Take a backup first. A migration changes the database in place, and the only way back to the older
image is the backup made before it.
