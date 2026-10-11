# Configuration

Settings go in a `.env` file beside `docker-compose-live.yml`. All are optional. After a change,
run `docker compose -f docker-compose-live.yml up -d` to recreate the containers.

| Setting | Default | What |
|---|---|---|
| `NUGGET_TAG` | `latest` | the version of the image to run, such as `1.0.0` |
| `NUGGET_PORT` | `8080` | the host port the site is published on |
| `APP_URL` | `http://localhost:8080` | the address people reach it at; used in emails |
| `TRUSTED_PROXIES` | none | the reverse proxy in front whose forwarded headers are believed: its address, a comma list, or `*` |
| `SESSION_SECURE_COOKIE` | off | `true` when the site is served over HTTPS |
| `SERVER_NAME` | `:80` | a host name turns on HTTPS from the container itself; `:80`, or none, is plain HTTP only |
| `NUGGET_TLS` | none | `internal` for a certificate signed by Caddy's own authority, when the name is not public |
| `NUGGET_TLS_CERT`, `NUGGET_TLS_KEY` | none | a certificate and key of your own, in place of the one Caddy gets |
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

For `TRUSTED_PROXIES`, `SERVER_NAME` and the certificate settings, see [HTTPS](installation.md#https).
