# Connecting tourlast.com to Tourlast Sales Hub

This guide is for the tourlast.com developers. It covers everything the Sales Hub needs from tourlast.com so that each salesperson is credited for the providers they onboard.

The Sales Hub is already built and tested against sample data. Your work is limited to the tourlast.com side, plus a few settings in the Hub's `.env` file.

## How it fits together

```
Salesperson shares            Sales Hub logs the click       tourlast.com
sales.tourlast.com/r/CODE ──▶ and redirects to ────────────▶ /list-your-property?ref=CODE
                                                                   │
                                                  provider signs up│ (ref code stored)
                                                                   ▼
Sales Hub  ◀────── reads (read-only) every 10 minutes ────── provider record
  credits the salesperson on the Activation Date (status active)
```

- The Hub **only reads** from tourlast.com. It never writes to it.
- A provider counts as **onboarded** on its **Activation Date**, when its status first becomes **active** (live and bookable). This follows the incentive policy (Schedule 1). If it is later rejected, the credit is removed; if it only stops being live (**inactive**), the credit stays and the property drops out of the live counts.
- Codes look like `TL-JOHN-2847`. Treat them as case-insensitive; the Hub upper-cases them.

## Required changes on tourlast.com

### 1. Capture the referral code

On `https://www.tourlast.com/list-your-property`, read the `ref` query parameter and keep it for 60 days, so a provider who comes back later still counts.

```php
// Laravel example, e.g. in the List Your Property controller or a middleware
if ($ref = request()->query('ref')) {
    cookie()->queue('tl_ref', strtoupper(substr($ref, 0, 40)), 60 * 24 * 60); // 60 days
}
```

The Hub owns the code list. Pull `GET /api/v1/integrations/tourlast/ref-codes` with the shared token (step 3), cache it hourly, and only store a code it returns — a code the Hub does not know is unattributed, never invented.

### 2. Store it on the provider/property record

When the host account or property is created, save the code in a column, for example `ref_code VARCHAR(40) NULL`:

```php
$property->ref_code = request()->cookie('tl_ref');
```

Keep the first code a provider arrived with, so later visits through other links don't overwrite it.

### 3. Connect source apps to the Hub (API only)

Option A lets the Hub **read** source apps over their API. Option B lets a source app **push** into the Hub. There is no third option: the Hub only connects to its own database, `TOURLAST_SOURCE=database` is rejected, and `config/database.php` defines no `tourlast` connection.

#### Option A: read-only JSON endpoint

`GET /api/sales-hub/referrals?updated_since=<ISO-8601>&page=<n>`, protected with a bearer token. Return only providers that have a ref code:

```json
{
  "data": [
    {
      "property_id": "TL-00842",
      "account_id": "H-2031",
      "legal_name": "ABC Hospitality Ltd",
      "category": "stay",
      "inventory_count": 45,
      "ref_code": "TL-JOHN-2847",
      "property_name": "ABC Hotel",
      "property_type": "hotel",
      "location": "Nairobi, Kenya",
      "contact_name": "Jane Mwangi",
      "contact_phone": "+254700000000",
      "contact_email": "gm@abchotel.co.ke",
      "status": "approved",
      "submitted_at": "2026-09-20T09:41:00+03:00",
      "approved_at": "2026-09-24T10:12:00+03:00",
      "active_at": null,
      "inactive_at": null,
      "rejected_at": null,
      "updated_at": "2026-09-24T10:12:00+03:00",
      "is_deleted": false,
      "deleted_at": null
    }
  ],
  "next_page": 2
}
```

Set `next_page` to `null` on the last page. Then set the following in the Hub's `.env`:

```dotenv
TOURLAST_SOURCE=api
TOURLAST_API_URL=https://www.tourlast.com
TOURLAST_API_PATH=/api/sales-hub/referrals
# one secret, both directions: the same value every source app stores as TOURLAST_HUB_TOKEN
# (write it with: php artisan hub:generate-token)
TOURLAST_API_TOKEN=<token>
```

#### Option B: push to the Sales Hub API (no access to tourlast.com needed)

tourlast.com sends each provider record to the Hub whenever it changes:

```
POST https://sales.tourlast.com/api/v1/integrations/tourlast/providers
Authorization: Bearer <shared sync token>
Content-Type: application/json

{"providers": [ { ...same fields as the API item above... } ]}
```

- Get the token by running `php artisan hub:generate-token` on the Hub server. It writes a fresh random `TOURLAST_API_TOKEN=` into the Hub's `.env` and prints the value once; the same value goes into each source app's `.env` as `TOURLAST_HUB_TOKEN`. One secret serves both directions (Hub → app for the read feed, app → Hub for pushes), there is no user account behind it, and rotating it means running the command again and updating every app.
- Send up to 100 records per request; each gets its own `result` (`created`, `updated`, `unchanged`, `deleted`) or `error`. Re-sending is safe.
- **Deleted properties:** send the row one last time with `"is_deleted": true` (plus `"deleted_at": <ISO-8601>` if you have it — otherwise the Hub stamps it with the time it received the row). Only `property_id` is applied: the Hub archives the property, its lead and its registry record, keeps the credit it earned, and alerts management. A later row with `"is_deleted": false` puts everything back. The counters behind this live on the sync run as `records_deleted`.
- **Properties that stopped being live:** send the row with `"status": "inactive"` and `"inactive_at": <ISO-8601>` (the moment it stopped). The Hub dates the status change from `inactive_at` — falling back to `updated_at` when it is absent — keeps the credit the property earned, and sends management and the salesperson one **Property inactive** alert. `inactive_at` is optional for feeds that cannot store it.
- The same bearer reads `GET /api/v1/integrations/tourlast/ref-codes`, the live list of active referral codes (with `is_active`, `user_id` and `user_name`). Use it to validate `?ref=` and to see who a code belongs to; codes are edited in the Hub and this list is always the current truth.
- Set `TOURLAST_SOURCE=push` in the Hub's `.env`. The scheduled read sync is then skipped, because tourlast.com sends every change itself.

Full reference, examples and errors: [API.md → tourlast.com integration](API.md#tourlastcom-integration).

### 4. Optional: instant updates (webhook)

The Hub can also receive updates the moment they happen. POST to `https://sales.tourlast.com/webhooks/tourlast` with:

- Header `X-Tourlast-Signature: sha256=<hex HMAC-SHA256 of the raw request body, keyed with the shared secret>`
- Body `{"event_id": "<unique id>", "provider": { ...same fields as the API item above... }}`

```php
$body = json_encode(['event_id' => (string) Str::uuid(), 'provider' => $payload]);
Http::withBody($body, 'application/json')
    ->withHeaders(['X-Tourlast-Signature' => 'sha256='.hash_hmac('sha256', $body, config('services.sales_hub.secret'))])
    ->post('https://sales.tourlast.com/webhooks/tourlast');
```

Send one on signup and on every status change. Duplicate `event_id`s are ignored. Set the same secret as `TOURLAST_WEBHOOK_SECRET` in the Hub. The scheduled sync keeps running as a safety net.

## Account data for incentives (Schedule 1)

Salespeople are paid points per **legal Account**, sized by verified rooms/units (stays) or bookable services (experiences). The Hub needs four more fields per property. Expose them in the same view, API or webhook:

| Hub field | Meaning | API / webhook payload key |
|---|---|---|
| `account_id` | The host / legal business the property belongs to. Every property of one legal entity must share it. | `account_id` |
| `legal_name` | Registered business name of that entity | `legal_name` |
| `category` | `stay` or `experience` (derived from the property type if missing) | `category` |
| `inventory_count` | Live rooms/units for a stay, or live bookable services for an experience | `inventory_count` |

- The **Activation Date** is `active_at`, the moment the property is live and bookable. Points are credited to the month and bonus week of that date, so `active_at` must be accurate.
- When `inventory_count` grows within 90 days of activation (a new branch, more rooms, more services), keep `updated_at` current. The Hub records the growth for a Sales Admin to verify, then awards expansion points.
- Until these fields exist, everything still works: each property becomes its own Account, and the Sales Admin enters the verified count when approving the Account.

## Status and type values

tourlast.com values are translated in `config/tourlast.php` (`status_map` and `type_map`). Matching ignores case. Add your exact values there if they differ.

| Hub status | Meaning | Example tourlast.com values |
|---|---|---|
| `submitted` | Signed up, nothing reviewed yet | draft, pending, submitted |
| `under_review` | Tourlast is checking it | in_review, review |
| `approved` | Accepted, not live yet | approved, verified |
| `active` | Live and bookable; **counts as onboarded** | active, live, published |
| `inactive` | Was live and stopped since; **keeps its credit**, no longer counted as live | inactive, paused |
| `rejected` | Declined or removed; credit withdrawn | rejected, declined, suspended, deleted |

Property types are the keys of `hub.property_types` in `config/hub.php` (hotel, resort, lodge, apartment, villa, guesthouse, cabin, beachfront, cottage, camper, restaurant, tour, travel_agency, experience, activity, transport, dmc, venue, other). Unknown types are stored as `other`.

## Testing the connection

1. Set the `.env` values, then run `php artisan config:clear`.
2. Run `php artisan hub:sync-tourlast --full`. It prints how many providers it read, created and updated, or the exact error.
3. Open **Admin → Integration** in the Hub (Super Admin only) to see the sync log and the latest status changes.
4. Visit `https://sales.tourlast.com/r/<a real code>`. You should land on List Your Property with `?ref=` attached. Complete a test signup, approve it and make it live in the tourlast.com admin, and within 10 minutes it appears under that salesperson's **My Onboardings** and counts on their **My Progress**.

While `TOURLAST_SOURCE=sandbox`, the Integration page has a simulator that creates sample signups and moves them through each status, using the same code path as real data.

## Where the code lives

| Purpose | File |
|---|---|
| Settings and value maps | `config/tourlast.php` |
| Source binding | `app/Providers/AppServiceProvider.php` |
| Sources | `app/Integrations/Tourlast/{Api,Sandbox,PushOnly}ProviderSource.php` |
| Field and value translation | `app/Integrations/Tourlast/ProviderRecordMapper.php` |
| Credit rules | `app/Actions/ApplyProviderRecord.php` |
| Sync run and log | `app/Actions/SyncOnboardings.php`, `php artisan hub:sync-tourlast` |
| Webhook receiver | `app/Http/Controllers/TourlastWebhookController.php` |
| Click tracking and redirect | `app/Http/Controllers/ReferralRedirectController.php` |
| Schedule | `routes/console.php` |
| Tests | `tests/Feature/OnboardingSyncTest.php`, `ProviderSourcesTest.php`, `WebhookAndClicksTest.php` |
