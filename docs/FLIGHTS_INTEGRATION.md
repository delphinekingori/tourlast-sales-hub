# Connecting Flights Super Admin (toursflights) to the Sales Hub

This guide is for the Flights Super Admin developer (`toursflights`). It lists everything that app must expose so the Sales Hub can keep its read-only copy of flight bookings for sales reporting and travel salesperson credit.

The Sales Hub side is already built and tested (sync command, push endpoint, storage models, tests). The work is limited to the Flights side, plus a few settings in the Hub's `.env`.

Related guides: `docs/API.md → Connecting Flights Super Admin and M-Pesa`, `docs/SYSTEM_GUIDE.md §8.6`. The stays/experiences equivalent is `docs/TOURLAST_INTEGRATION.md`.

## How it fits together

```
Flight booked in Flights Super Admin (flight_orders)
        │
        │  Option A: Hub pulls every 10 min      GET {base}/bookings?updated_since=…&page=N
        │  Option B: Flights pushes on change    POST {HUB}/api/v1/integrations/flights/bookings
        ▼
Sales Hub keeps a read-only copy (flight_bookings) ──▶ sales reporting, flight KPIs,
                                                        and crediting the travel salesperson
```

- The Hub **never writes** to Flights Super Admin. It only reads (Option A) or accepts what you send (Option B).
- One booking = one row, matched on `external_id`. Re-sending is always safe; older `updated_at` values are ignored.
- Schedule: the Hub syncs every 10 minutes and does a full re-read on `php artisan travel:sync-flights --full`. After no successful sync for 30 minutes the Hub shows "Flight data synchronization delayed" and alerts Travel managers.

## Choose one option

### Option A: read-only bookings endpoint (recommended — same shape as stays/experiences)

Expose:

```
GET {FLIGHTS_API_URL}/hub/bookings?updated_since=<ISO 8601>&page=<n>
Authorization: Bearer {FLIGHTS_API_TOKEN}
Accept: application/json

200 {"data": [ {booking}, … ], "meta": {"current_page": 1, "last_page": 4}}
```

- `updated_since` filters on the booking's last change (`flight_orders.updated_at >= updated_since`); omit it (or ignore it) means "everything" — the Hub uses that for the full read.
- `meta.last_page` or a top-level `next_page` both work for pagination; the Hub follows pages until the last one.
- Any non-2xx stops that sync run (nothing is deleted; it retries next run). A single malformed booking is skipped and reported, the rest are applied.

### Option B: push to the Sales Hub API

```
POST https://sales-hub.tourlast.com/api/v1/integrations/flights/bookings
Authorization: Bearer <the shared sync token>
Content-Type: application/json

{"bookings": [ {booking}, … ]}     // or a single {"booking": {…}}, max 500 per request
```

- The token is the **one shared sync token every integration uses** (the same secret as the stays/experiences connection): run `php artisan hub:generate-token` **on the Hub** — it prints `TOURLAST_API_TOKEN` once — and store the same value on your side (the stays and experiences apps call it `TOURLAST_HUB_TOKEN`). There is no user, role or scope behind it.
- Response: `{"received": n, "created": n, "updated": n, "unchanged": n, "failed": [{"external_id": …, "error": …}]}` — `422` only if every booking failed.
- Then set `TOURLAST_SOURCE=push` in the Hub `.env` (or `FLIGHTS_SOURCE=push` to override flights alone; the scheduled pull is skipped).

### Option C (optional, alongside A or B): instant updates by webhook

The Hub can receive a booking the moment it changes. POST to `https://sales-hub.tourlast.com/webhooks/flights` with:

- Header `X-Tourlast-Signature: sha256=<hex HMAC-SHA256 of the raw request body, keyed with the shared webhook secret>` (`TOURLAST_WEBHOOK_SECRET` on the Hub, `TOURLAST_HUB_WEBHOOK_SECRET` on your side — the same secret every source app signs with)
- Body `{"event_id": "<unique id>", "booking": { ...the same booking record as above... }}`

```php
$body = json_encode(['event_id' => (string) Str::uuid(), 'booking' => $payload]);
Http::withBody($body, 'application/json')
    ->withHeaders(['X-Tourlast-Signature' => 'sha256='.hash_hmac('sha256', $body, config('services.sales_hub.webhook_secret'))])
    ->post('https://sales-hub.tourlast.com/webhooks/flights');
```

- The `{source}` path segment identifies the sender (`flights` receives bookings; `tourlast`, `stays` and `experiences` receive providers), so event ids from different apps never collide.
- Duplicate `event_id`s are ignored (per source). The scheduled sync / push endpoint keeps running as a safety net.

## The booking record (both options)

Snake_case keys. **Only `external_id`, `booked_at` and `booking_status` are required** — everything else may be omitted or null. Dates are ISO 8601 with an offset. Amounts are **decimal numbers in `currency`** (not cents). Statuses are sent exactly as Flights uses them; the Hub stores them lower-cased and never invents its own.

```json
{
  "external_id": "FL-104233",
  "booking_reference": "TLF10423",
  "pnr": "X7K2QP",
  "customer": {"name": "Jane Doe", "email": "jane@example.com", "phone": "0712345678"},
  "airline": {"code": "KQ", "name": "Kenya Airways"},
  "origin": "NBO", "destination": "MBA",
  "trip_type": "one_way",
  "cabin": "economy",
  "departure_at": "2026-10-20T07:15:00+03:00",
  "arrival_at": "2026-10-20T08:15:00+03:00",
  "return_at": null,
  "passengers": [{"name": "Jane Doe", "type": "adult", "ticket_number": "7062345678901"}],
  "segments": [
    {"flight_number": "KQ602", "airline": "KQ", "origin": "NBO", "destination": "MBA",
     "departure_at": "2026-10-20T07:15:00+03:00", "arrival_at": "2026-10-20T08:15:00+03:00", "cabin": "economy"}
  ],
  "currency": "KES",
  "fare_amount": 9500.00,
  "total_amount": 11200.00,
  "markup_amount": 700.00,
  "booking_status": "confirmed",
  "payment_status": "paid",
  "cancellation": {"status": "cancelled", "cancelled_at": "2026-10-10T09:00:00+03:00", "reason": "Customer request"},
  "refund": {"status": "pending", "amount": 8000.00, "method": "mpesa",
             "requested_at": "2026-10-10T09:00:00+03:00", "completed_at": null},
  "booked_at": "2026-10-07T10:03:00+03:00",
  "agent_reference": "aisha@tourlast.com",
  "promo_code": "AMINA10",
  "updated_at": "2026-10-07T10:05:00+03:00"
}
```

Flat keys also work: `customer_name`, `customer_email`, `customer_phone`, `airline_code`, `airline_name`, `cancellation_status`, `cancelled_at`, `cancellation_reason`, `refund_status`, `refund_amount`, `refund_method`, `refund_requested_at`, `refund_completed_at`.

### Field mapping — what you already have in `toursflights`

| Hub field | Source in `toursflights` |
|---|---|
| `external_id` (required, stable, ≤80 chars) | `flight_orders.id` — decide the format once and never change it (e.g. `"FL-{id}"`). It is also what `{id}` becomes in the Hub's "Open in Flights Admin" link. |
| `booking_reference` / `pnr` | `flight_orders.booking_reference` (the provider PNR) |
| `booking_status` (required) | `flight_orders.status` — send as-is; the Hub lower-cases it (≤30 chars) |
| `booked_at` (required) | `flight_orders.created_at` |
| `updated_at` | `flight_orders.updated_at` — required in practice: it is the out-of-order guard and the `updated_since` filter |
| `customer.*` | `contact_name`, `contact_email`; phone from the first passenger (`provider_payload.passengers[0]`) |
| `origin`, `destination`, `departure_at`, `arrival_at`, `return_at`, `trip_type`, `cabin` | `provider_payload.slices[0]` (first slice = outbound; `return_at` from the return slice when present) |
| `segments[]` | `provider_payload.slices[].segments[]` — map `flight_number`, `marketing_carrier` → `airline`, `origin`, `destination`, `departure_at`, `arrival_at`, `cabin_class` → `cabin` |
| `passengers[].name` / `type` / `ticket_number` | `first_name + " " + last_name`; `type` (adult/child/infant); `ticket_numbers` JSON (by index) |
| `currency` | `flight_orders.currency` |
| `fare_amount`, `total_amount`, `markup_amount` | `flight_orders` amounts are **integer cents** → divide by 100 (`cost_amount` is not sent; `markup_amount` is your margin) |
| `payment_status` | `metadata.last_payment_status` (e.g. `succeeded` → send as `paid`, or send verbatim) |
| `cancellation{}` | newest row in `flight_cancellations` → `status`, `confirmed_at` → `cancelled_at` |
| `refund{}` | `flight_cancellations.refund_amount` (cents → decimal), `refund_currency`, `payment_refund_status`, `confirmed_at` |
| `agent_reference` | **does not exist yet — see below** |
| `promo_code` | **does not exist yet — see below** |

### What does not exist in `toursflights` today (the actual asks)

1. **A bookings feed endpoint (Option A) or an outbound push (Option B).** The existing `GET /api/v1/admin/flights/bookings` is close but does not match the contract: different path, no `updated_since` filter, and it returns `id`/`status`/`created_at`/`slices[]`/cents instead of the Hub's keys. Either add a Hub-shaped `/bookings` endpoint (a thin serializer over `FlightOrderAdminController`'s query is enough), or return the Hub shape from a new route.
2. **`agent_reference` — the selling travel salesperson's Hub email** (e.g. `aisha@tourlast.com`). Without it the booking still syncs, but **nobody is credited for the sale**. This is the one field that needs new data capture: record who sold/entered the booking and send it as `agent_reference`.
3. **`promo_code`** — the influencer code used on the booking, if the flights flow ever has one. Optional; without it no influencer commission is earned on flights.
4. **A machine-to-machine token.** The Hub now accepts the **one shared sync token** every integration uses (from `php artisan hub:generate-token` on the Hub). Store that value as your feed token and as the bearer for the push endpoint; keep it out of git. The Hub reads it as `TOURLAST_API_TOKEN` (`FLIGHTS_API_TOKEN` only overrides it when flights must use a separate secret).
5. **Confirm the refund status grouping** (Hub `docs/SYSTEM_GUIDE.md §8.6`): open = `pending`, `requested`, `processing`, `in_progress`, `submitted`; done = `completed`, `refunded`, `paid`, `processed`. Map `flight_cancellations.status` / `payment_refund_status` into these words or tell us your exact values — the Hub displays whatever you send.
6. **Decide `trip_type` values** — the Hub reads `one_way | return | multi_city`; send your own words if different and we will add them to the map.

## Money and status rules

- Flights stores cents; the Hub wants decimals in `currency` (`11200.00`, not `1120000`). Round to 2 places.
- Status values observed in Flights (`pending`, `pending_payment`, `awaiting_payment`, `confirmed`, `ticketed`, `cancelled`, `voided`, `refunded`, `failed`) are all fine as-is — the Hub stores them verbatim (lower-cased) and renders its own labels.
- Bookings are matched on (`external_id`); an incoming row older than the stored `updated_at` is ignored, so out-of-order delivery is safe.

## Hub `.env` settings (we set these once your side is ready)

```dotenv
# Option A (pull): the source mode follows TOURLAST_SOURCE=api (the shared switch).
# FLIGHTS_SOURCE=api is only an override when flights must differ.
FLIGHTS_API_URL=<flights base, e.g. https://flights.tourlast.com/api/hub>
# The one shared sync token every integration uses (hub:generate-token).
# FLIGHTS_API_TOKEN is optional — it only overrides the shared token.
FLIGHTS_API_TOKEN=<the shared sync token, same value as TOURLAST_API_TOKEN>
FLIGHTS_API_TIMEOUT=20

# Option B (push): TOURLAST_SOURCE=push (FLIGHTS_SOURCE=push overrides flights alone) — the same shared token authenticates the push.

FLIGHTS_ADMIN_BOOKING_URL="https://admin.flights.tourlast.com/bookings/{id}"
FLIGHTS_STALE_AFTER_MINUTES=30
```

`{id}` in `FLIGHTS_ADMIN_BOOKING_URL` is replaced with the URL-encoded `external_id`, so the format you choose in step 1 must produce a working Flights Admin URL.

## Verification

```bash
# Option A — the Hub's exact call:
curl -H "Authorization: Bearer $FLIGHTS_API_TOKEN" -H "Accept: application/json" \
  "$FLIGHTS_API_URL/bookings?page=1"
# expect 200 {"data": [...], "meta": {"current_page": 1, "last_page": N}}

curl -H "Authorization: Bearer $FLIGHTS_API_TOKEN" -H "Accept: application/json" \
  "$FLIGHTS_API_URL/bookings?updated_since=2026-10-07T08:00:00%2B03:00&page=1"
# expect only bookings changed since that time
```

Then on the Hub: `php artisan travel:sync-flights --full` (first load), then `php artisan travel:sync-flights` every run should print `Flights synced from api: N seen, …`. In push mode, the response counts `created/updated/unchanged/failed`.

For the webhook (Option C): sign a test booking body with the webhook secret and POST it to `$APP_URL/webhooks/flights`; expect `{"result": "created"}` (then `"duplicate"` on replay).

A good smoke test: book a test flight in Flights, confirm it appears in the Hub's Flights page with the right salesperson credit and a working "Open in Flights Admin" link.

## Summary checklist for the Flights developer

- [ ] Bookings feed (`GET /bookings` + `updated_since` + `page` + bearer) **or** push job to `POST /api/v1/integrations/flights/bookings`
- [ ] Serializer emitting the Hub record keys (table above), cents → decimals, `slices` → `segments`
- [ ] Stable `external_id` format (≤80 chars)
- [ ] `agent_reference` captured and sent (sales credit depends on it)
- [ ] `promo_code` sent if/when the flights flow has influencer codes
- [ ] Refund/cancellation status words confirmed against §8.6 grouping
- [ ] Shared sync token in place (from `hub:generate-token`); Hub `.env` flipped to `api` or `push`
- [ ] Webhook (optional): `POST /webhooks/flights` signed with the shared webhook secret
- [ ] Full backfill run, then the 10-minute schedule takes over
