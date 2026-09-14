# Meta Lead Ads → ManagerOX

How Instant Form leads reach the CRM automatically, and what has to be set up
on Meta's side to switch it on.

## How it works

```
Someone submits an Instant Form
        ↓
Meta POSTs a webhook to  /api/webhooks/meta/leads
        ↓  (carries only ids — never the answers)
Signature checked against META_APP_SECRET, then 200 returned immediately
        ↓
ImportMetaLead job reads the answers from the Graph API
        ↓
Lead saved with source = "meta", deduped on leadgen_id
```

Two details drive the design:

- **The webhook carries no answers.** It gives a `leadgen_id`; the form data is
  a separate Graph API call. That call needs a Page access token.
- **Meta retries until it gets a 200**, for hours. So the endpoint answers
  immediately and does the Graph call on the queue, and every import is
  idempotent — `external_id` is uniquely indexed, so a redelivered lead updates
  nothing and creates nothing.

## What you set up on Meta

You need a Facebook **Page** that runs the lead ads, and a Meta **app**.

### 1. Create the app

At [developers.facebook.com](https://developers.facebook.com) → My Apps →
Create App → type **Business**.

From **App Settings → Basic**, copy the **App Secret** → `META_APP_SECRET`.

### 2. Get a Page access token

Add **Facebook Login for Business**, then use the
[Graph API Explorer](https://developers.facebook.com/tools/explorer) to grant:

| Permission | Why |
| --- | --- |
| `leads_retrieval` | read the submitted answers |
| `pages_show_list` | list the Pages you manage |
| `pages_read_engagement` | read Page data |
| `pages_manage_metadata` | subscribe the Page to the webhook |

Generate a **Page** access token (not a User token), exchange it for a
long-lived one, and set it as `META_PAGE_TOKEN`. Short-lived tokens expire in
about an hour and imports will start failing silently apart from the log line.

### 3. Point the webhook at this API

Add the **Webhooks** product → subscribe to the **Page** object → tick the
**`leadgen`** field.

| Field | Value |
| --- | --- |
| Callback URL | `https://api.managerox.com/api/webhooks/meta/leads` |
| Verify token | any random string — set the same value as `META_VERIFY_TOKEN` |

Meta immediately sends a GET to that URL and expects the challenge echoed back.
The API handles that; if it fails, the verify token does not match or the URL is
not publicly reachable.

Finally subscribe your Page to the app, so its leads are actually sent.

### 4. App Review

`leads_retrieval` needs App Review before the integration works for anyone other
than people listed as developers/testers on the app. Until then you can test
with your own account.

## Environment

```dotenv
META_APP_SECRET=...      # App Settings → Basic
META_VERIFY_TOKEN=...    # any random string; must match the webhook config
META_PAGE_TOKEN=...      # long-lived PAGE token
META_GRAPH_VERSION=v21.0
```

**The queue must be running**, or leads sit unprocessed:

```bash
php artisan queue:work
```

## Testing without Meta

The CRM side can be exercised before App Review:

```bash
php artisan meta:simulate-lead
php artisan meta:simulate-lead --name="Usman Tariq" --question=interested_in --answer="Commercial Plot"
```

Each creates a lead exactly as a real Instant Form would — `source = meta`, an
`external_id`, and the answers in `payload`. They appear in the CRM's Leads
screen with a **META** badge.

To test the real webhook locally, Meta needs a public URL. Expose
`http://localhost:8000` with ngrok or `expose`, and use that host in the webhook
config.

## Field mapping

Instant Forms let advertisers write their own questions, so names are only
partly predictable.

| CRM field | Meta field names tried, in order |
| --- | --- |
| `name` | `full_name`, `name`, `first_name` |
| `email` | `email`, `work_email` |
| `phone` | `phone_number`, `phone`, `work_phone_number` |
| `detail` | the first unmapped answer, e.g. *"Which Property Type: Villa"* |

**Every answer is also stored verbatim in `payload`**, so changing a form never
silently loses data. A lead whose form asked nothing recognisable is still
imported, named "Meta lead" — dropping it would lose a real enquiry.

## Security

The endpoint is public by necessity — Meta's servers have no session. Its
authenticity check is the `X-Hub-Signature-256` header, an HMAC of the raw body
keyed with the app secret, compared using `hash_equals` so the comparison cannot
be timed.

If `META_APP_SECRET` is unset the endpoint returns 500 rather than accepting
anything. A missing secret must never mean "trust everyone".
