# Deploying without a custom domain, on free tiers

The architecture originally assumed `app.managerox.com` and `api.managerox.com`.
Without a shared parent domain the session cookie is cross-site and browsers
drop it, so auth silently 401s everywhere.

**The fix is the CRM proxying the API**, which is already configured. The
browser only ever talks to the CRM's own origin; Next forwards `/api/*` and
`/sanctum/*` to this API server-side. The cookie stays first-party and CORS
stops applying. The API's real address is never exposed to the browser.

```
browser ──► managerox-app.vercel.app ──► your-api-host  (server to server)
            (all cookies live here)
```

## What goes where

| Piece | Host | Free? |
| --- | --- | --- |
| Marketing site | Vercel | yes |
| CRM | Vercel | yes |
| This API | see below | yes, with caveats |
| Postgres | Neon or Supabase | yes, and does not expire |

**Do not use the app host's bundled free database** if it expires after a trial
— a dedicated free Postgres provider is steadier. Verify current terms before
committing; free tiers change often.

### Picking an API host

It must run PHP 8.3+ as a long-lived process. Render, Fly.io and Koyeb all
have free or near-free tiers that can. Two things matter more than the brand:

- **Sleeping.** Free instances usually sleep when idle and wake on request.
  That is survivable here: Meta retries a failed webhook for hours, so a lead
  delayed by a cold start still arrives. It does make the CRM's first load slow.
- **Second processes.** Free tiers rarely let you run a queue worker alongside
  the web process. See below — you do not need one.

## The queue, without a worker

Meta lead imports are queued so the webhook can answer fast. With no worker
process, **nothing imports** and leads pile up in the `jobs` table invisibly.

On a free tier, run them inline instead:

```dotenv
QUEUE_CONNECTION=sync
```

The import then happens during the webhook request. The Graph call takes about a
second, and Meta allows roughly twenty before it treats the delivery as failed —
so this is comfortably safe, and it is the right trade until you have a worker.

Switch back to `database` and run `php artisan queue:work` the moment you can
run a second process.

## Environment

On the **API host**:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=                      # php artisan key:generate --show
APP_URL=https://your-api-host.example.com

DB_CONNECTION=pgsql           # from Neon/Supabase
DB_HOST=...
DB_PORT=5432
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

SESSION_DRIVER=database
# The CRM proxies, so the browser's origin IS the CRM. Sanctum must recognise
# it or every request is treated as stateless and 401s.
SANCTUM_STATEFUL_DOMAINS=managerox-app.vercel.app
SESSION_DOMAIN=null
CORS_ALLOWED_ORIGINS=https://managerox-app.vercel.app

QUEUE_CONNECTION=sync         # until a worker process is available

META_APP_SECRET=...
META_VERIFY_TOKEN=...
META_PAGE_TOKEN=...
```

On **Vercel**, for the CRM:

```
API_ORIGIN = https://your-api-host.example.com
```

That one is server-side deliberately — it has no `NEXT_PUBLIC_` prefix, so it is
never sent to the browser.

## Build and start commands

```bash
# build
composer install --no-dev --optimize-autoloader && php artisan config:cache && php artisan route:cache

# release / start
php artisan migrate --force && php artisan serve --host 0.0.0.0 --port $PORT
```

`--force` is required: `migrate` refuses to run unprompted in production
otherwise, and there is no terminal to confirm at.

`php artisan serve` is fine to begin with. Move to nginx + php-fpm when traffic
justifies it.

Seed once, if you want the demo data:

```bash
php artisan db:seed --force
```

## Meta webhook

Point the callback at the **API host directly**, not the CRM:

```
https://your-api-host.example.com/api/webhooks/meta/leads
```

Meta is server-to-server with no cookies, so it is unaffected by any of the
domain juggling. It only needs the API publicly reachable.

## Checks after deploying

```bash
curl https://your-api-host.example.com/up                    # 200
curl https://managerox-app.vercel.app/api/dashboard          # 401 — proxy works, correctly unauthenticated
```

Then sign in to the CRM. In the browser's network tab **every request should go
to the Vercel domain**; if you see the API host there, the proxy is bypassed and
auth will fail once the two are on unrelated domains.

## Moving to a real domain later

Buying any domain removes all of this. Point `app.` and `api.` at the two hosts,
set `SESSION_DOMAIN=.yourdomain.com`, and the proxy becomes optional — though
there is no harm in keeping it.
