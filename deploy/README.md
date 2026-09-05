# Installing on the shared server

`learn.edadras.com` runs on a box that already hosts other sites. These scripts
are written for that, not for an empty machine: everything they create is
namespaced to zaban, and they stop rather than guess whenever something already
exists.

## What was found on the server from outside

Checked before writing any of this, over HTTPS — SSH is not reachable from the
session that wrote it, so this is what could be established remotely:

| | |
|---|---|
| Edge web server | **Caddy** — it answers on 80/443 and issues the 308 to HTTPS |
| Behind it | nginx/1.27.5 serves at least the apex site |
| `learn.edadras.com` DNS | already resolves to 185.231.111.184 |
| TLS for that name | **not yet issued** — the handshake fails with an internal error, i.e. Caddy has no site block for it |
| Apex site | redirects to `/fa`, so the estate is already multilingual |

Two things follow from this, and they are why the installer looks the way it
does. There is **no certbot step** — Caddy obtains and renews certificates
itself, on first request, which is also why DNS already pointing here matters.
And the site is added as **its own file under `/etc/caddy/conf.d/`** rather than
by editing the main Caddyfile, so no other site's configuration is touched.

## Run it

```bash
# 1. Look. Changes nothing.
bash preflight.sh

# 2. Only if the report says "No blockers".
sudo DB_PASS='choose-a-strong-one' bash install.sh
```

`preflight.sh` reports rather than assumes: which web server is running, what
Caddy already serves, whether the PHP on the box meets the 8.3 floor and has
every required extension, whether `zaban` is free as a database name, which
ports are taken, whether `/var/www/zaban` exists, and whether DNS points here.
Anything it marks **BLOCK** will make `install.sh` refuse.

## What install.sh will and will not do

**Will:** install only missing packages; clone to `/var/www/zaban`; create the
`zaban` database and user; write `.env`; migrate and seed; cache config; add one
Caddy site file; install the seven queue workers as `zaban.conf` under
supervisor; verify the API answers.

**Will not:** change the system PHP version — other sites are using it, so a
swap would break them; edit another site's Caddy or supervisor config; restart
anything beyond a Caddy reload and a supervisor update; write into a database it
did not create.

Re-running updates the checkout and re-runs migrations. It does not start over,
and it leaves an existing `.env` alone.

## Afterwards, by hand

1. `ANTHROPIC_API_KEY` into `backend/.env`, then `php artisan config:cache`.
   Nothing AI-backed works until this is set — marking, the tutor, handwriting
   recognition.
2. Import the course content — see `docs/DEPLOYMENT.md` §5.
3. Create an admin user.

## Sizing

The repository carries roughly 900 MB of book audio and images before anything
is generated, and generated media grows from there. Preflight blocks below 15 GB
free.
