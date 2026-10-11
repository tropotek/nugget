# Installation

Requires Docker with the Compose plugin. Copy
[`docker-compose-live.yml`](https://github.com/tropotek/nugget/blob/main/docker-compose-live.yml)
into a directory of its own and start it:

```bash
mkdir nugget && cd nugget
curl -fsSLO https://raw.githubusercontent.com/tropotek/nugget/main/docker-compose-live.yml
docker compose -f docker-compose-live.yml up -d
docker compose -f docker-compose-live.yml exec app artisan nugget:create-admin you@example.org
```

Then sign in at http://localhost:8080. There is no registration: the admin adds any other accounts
under **Users**.

It starts two containers of the one image: `app`, the site, and `scheduler`, which fetches prices
and syncs the exchanges every 5 minutes. On its first start the site makes the database, runs the
migrations and generates its key. Everything it keeps is in `data/` beside the compose file; see
[Backup and restore](backup-restore.md).

To bring a tkbtc site over instead of starting empty, see [Migrate from tkbtc](migrate-from-tkbtc.md).

Settings go in a `.env` file beside the compose file: see [Configuration](configuration.md).

## HTTPS

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
    reverse_proxy nugget-host:8080
}
```

**From the container itself.** Uncomment the two lines marked `https` in
`docker-compose-live.yml`, which publish ports 443 and 80, and give the site its name:

```bash
SERVER_NAME=nugget.example.org      # a host name turns HTTPS on; ":80", or none, is plain HTTP only
```

Caddy, which is in the image, then gets a certificate for that name and renews it, with nothing
else to set. The name has to be public and ports 80 and 443 have to reach the container, since
that is how the certificate authority checks it. Port 80 otherwise only redirects to HTTPS. The
certificates are kept in `data/caddy/`, so an upgrade does not ask for them again.

Where that cannot work, say which certificate to use instead:

```bash
NUGGET_TLS=internal                 # signed by Caddy's own authority, for a name that is not public
```

```bash
NUGGET_TLS_CERT=/certs/fullchain.pem    # or one of your own: put the files in certs/ beside the
NUGGET_TLS_KEY=/certs/privkey.pem       # compose file, readable by uid 1000, and uncomment its mount
```

A browser trusts an `internal` certificate only once Caddy's root, `data/caddy/pki/authorities/local/root.crt`,
is installed on it. A certificate of your own is read when the container starts, so restart it
after a renewal.

## Upgrade

Take a [backup](backup-restore.md) first: a migration changes the database in place.

```bash
docker compose -f docker-compose-live.yml pull
docker compose -f docker-compose-live.yml up -d
```

Versions are listed on the [package page](https://github.com/tropotek/nugget/pkgs/container/nugget).
Set `NUGGET_TAG` to stay on one.

The site runs any new migrations as it starts, and the scheduler waits for it. `curl
http://localhost:8080/up` answers with the version once it is up.
