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
- A provider counts as **onboarded** on its **Activation Date**, when its status first becomes **active** (live and bookable). This follows the incentive policy (Schedule 1). If it is later rejected, the credit is removed.
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

### 2. Store it on the provider/property record

When the host account or property is created, save the code in a column, for example `ref_code VARCHAR(40) NULL`:

```php
$property->ref_code = request()->cookie('tl_ref');
```

Keep the first code a provider arrived with, so later visits through other links don't overwrite it.

### 3. Give the Hub read access (choose one)

#### Option A: read-only database user (simplest)

Create a MySQL user with SELECT rights on the provider/property table only:

```sql
CREATE USER 'sales_hub_readonly'@'<sales-hub-server-ip>' IDENTIFIED BY '<strong password>';
GRANT SELECT ON tourlast.properties TO 'sales_hub_readonly'@'<sales-hub-server-ip>';
```

Then set these in the Hub's `.env` and map your column names:

```dotenv
TOURLAST_SOURCE=database
TOURLAST_DB_HOST=...
TOURLAST_DB_DATABASE=tourlast
TOURLAST_DB_USERNAME=sales_hub_readonly
TOURLAST_DB_PASSWORD=...
TOURLAST_DB_TABLE=properties

# Map only what differs from the defaults in config/tourlast.php
TOURLAST_COL_PROPERTY_ID=id
TOURLAST_COL_REF_CODE=ref_code
TOURLAST_COL_NAME=name
TOURLAST_COL_TYPE=property_type
TOURLAST_COL_LOCATION=city
TOURLAST_COL_STATUS=status
TOURLAST_COL_SUBMITTED_AT=created_at
TOURLAST_COL_APPROVED_AT=approved_at
TOURLAST_COL_ACTIVE_AT=published_at
TOURLAST_COL_UPDATED_AT=updated_at
```

If contact details live in another table, create a read-only **view** that joins them, and point `TOURLAST_DB_TABLE` at the view.

`updated_at` must change whenever the status changes; the Hub uses it to read only recent changes. A full re-check also runs every night.

#### Option B: read-only JSON endpoint

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
      "rejected_at": null,
      "updated_at": "2026-09-24T10:12:00+03:00"
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
TOURLAST_API_TOKEN=<token>
```

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

| Hub field | Meaning | `.env` column setting (database source) |
|---|---|---|
| `account_id` | The host / legal business the property belongs to. Every property of one legal entity must share it. | `TOURLAST_COL_ACCOUNT_ID` |
| `legal_name` | Registered business name of that entity | `TOURLAST_COL_LEGAL_NAME` |
| `category` | `stay` or `experience` (derived from the property type if missing) | `TOURLAST_COL_CATEGORY` |
| `inventory_count` | Live rooms/units for a stay, or live bookable services for an experience | `TOURLAST_COL_INVENTORY_COUNT` |

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
| Read-only DB connection | `config/database.php` (`tourlast` connection) |
| Sources | `app/Integrations/Tourlast/{Database,Api,Sandbox}ProviderSource.php` |
| Field and value translation | `app/Integrations/Tourlast/ProviderRecordMapper.php` |
| Credit rules | `app/Actions/ApplyProviderRecord.php` |
| Sync run and log | `app/Actions/SyncOnboardings.php`, `php artisan hub:sync-tourlast` |
| Webhook receiver | `app/Http/Controllers/TourlastWebhookController.php` |
| Click tracking and redirect | `app/Http/Controllers/ReferralRedirectController.php` |
| Schedule | `routes/console.php` |
| Tests | `tests/Feature/OnboardingSyncTest.php`, `ProviderSourcesTest.php`, `WebhookAndClicksTest.php` |
