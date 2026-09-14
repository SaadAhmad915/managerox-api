# ManagerOX API

Laravel backend for the ManagerOX CRM, intended for **api.managerox.com**.
Consumed by the CRM at [managerox-app](https://github.com/SaadAhmad915/managerox-app).

## Running locally

Needs **PHP >= 8.3** and **Composer**. On Windows the least painful route is
[Laravel Herd](https://herd.laravel.com) — one installer, bundles both.

```bash
composer setup     # installs, writes .env, creates the SQLite file, migrates, seeds
php artisan serve  # http://localhost:8000
```

`composer setup` is idempotent — safe to re-run.

The database is **SQLite**, a single file at `database/database.sqlite`. It is
gitignored (you should never commit a database), so a fresh clone has no such
file and `php artisan migrate` fails with *"Database file ... does not exist"* —
which is why `composer setup` creates it first. Swap `DB_CONNECTION` in `.env`
for MySQL or Postgres in production.

### Serve it on localhost, not a Herd `.test` domain

Use `php artisan serve`. If you park this project in Herd's directory it is
served at `managerox-api.test`, and cookie auth against a frontend on
`localhost:3000` **will silently fail**: `.test` and `localhost` are different
sites, so the `SameSite=Lax` session cookie is never sent. `localhost:8000` and
`localhost:3000` differ only by port, which keeps them same-site and makes the
cookie work. Herd is still doing the work here — it supplies PHP and Composer.

### Seeded logins

All seeded users share the password `password`.

| Email | Role |
| --- | --- |
| `ali@managerox.com` | Sales Manager |
| `sara@managerox.com` | Sales Executive |
| `bilal@managerox.com` | Sales Executive |
| `ayesha@managerox.com` | Sales Executive |

The seed reproduces the figures the dashboard was designed against — the funnel
lands on 1250 / 640 / 320 / 210 / 180 and attainment on 92 / 78 / 65 / 58 % —
but every number the API reports is derived from the rows, not hardcoded.

## Endpoints

| Method | Path | Auth | Purpose |
| --- | --- | --- | --- |
| GET | `/sanctum/csrf-cookie` | — | Call once before the first POST |
| POST | `/api/login` | — | Sign in, throttled to 6/min |
| POST | `/api/logout` | session | Sign out |
| GET | `/api/me` | session | Current user |
| GET | `/api/dashboard` | session | Everything the dashboard renders |
| GET | `/api/leads` | session | Paginated; `?stage=`, `?search=`, `?perPage=` |
| POST | `/api/leads` | session | Create |
| GET | `/api/leads/{id}` | session | Read |
| PATCH | `/api/leads/{id}` | session | Update |
| DELETE | `/api/leads/{id}` | session | Delete |
| GET | `/api/webhooks/meta/leads` | signature | Meta subscription handshake |
| POST | `/api/webhooks/meta/leads` | signature | Meta Instant Form lead received |

`GET /api/dashboard` returns exactly the `DashboardData` shape in the frontend's
`app/lib/types.ts`. **Those two files are one contract** — renaming a key here is
a breaking change there.

## How auth works, and the part that trips people

Sanctum **SPA cookie** mode, not bearer tokens.

`app.managerox.com` and `api.managerox.com` are different *origins* (so CORS
applies) but the same *site* (so a `SameSite=Lax` cookie on `.managerox.com` is
still sent). Three things must line up or you get silent 401s:

1. `SANCTUM_STATEFUL_DOMAINS` must list the frontend host. Sanctum decides
   whether a request is stateful by matching its `Origin` header against this.
   **If the origin is not listed there is no session at all** — the request is
   treated as a token request and falls through to 401.
2. `CORS_ALLOWED_ORIGINS` must list the frontend origin explicitly. A wildcard
   with credentials is invalid per the CORS spec and browsers reject it, which
   is why `config/cors.php` reads an explicit list and sets
   `supports_credentials => true`.
3. The frontend must send `credentials: 'include'` on every request, and call
   `/sanctum/csrf-cookie` once before its first POST.

In production set `SESSION_DOMAIN=.managerox.com` so the cookie is shared across
the subdomains. Locally leave it `null`.

## Meta Lead Ads

Instant Form leads arrive automatically: Meta posts a signed webhook, the lead
is read from the Graph API on the queue, and it lands in the CRM with
`source = meta`, deduped on Meta's `leadgen_id`.

Setup, field mapping and security are in **[docs/meta-lead-ads.md](docs/meta-lead-ads.md)**.
Try it without a Meta app at all:

```bash
php artisan meta:simulate-lead
```

Remember `php artisan queue:work` — real webhooks queue their Graph call, so
without a worker nothing imports.

## Deploying

Without a shared parent domain the CRM and API are cross-site, so the session
cookie is dropped and everything 401s. The CRM proxies this API instead, keeping
the cookie first-party — see **[docs/deploying-free.md](docs/deploying-free.md)**
for the full setup on free tiers, including running Meta imports without a queue
worker.

## Known gaps

- **Active Deals trend is approximate.** A true month-over-month figure needs
  stage-change history, which is not recorded yet. It currently approximates by
  lead creation date — see the note in `DashboardController`.
- No registration, password reset, or roles/permissions yet.
- Meta integration has tests; the rest of the API does not yet.
- `leads_retrieval` needs Meta App Review before it works for non-developers.
