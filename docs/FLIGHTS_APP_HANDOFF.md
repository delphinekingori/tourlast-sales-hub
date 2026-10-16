# Handoff: connect the Flights app (toursflights) to the Tourlast Sales Hub

Give this to the developer (or agent) working on **toursflights**. It lists everything that app must expose so the Hub can keep its read-only copy of flight bookings for sales reporting, flight KPIs, and crediting the travel salesperson. The Hub side is already built and tested (sync command, push endpoint, webhook receiver, storage models, tests) — the work below is entirely on the toursflights side.

The stays/experiences equivalent of this document is `SOURCE_APPS_HANDOFF.md`. The full Hub-side reference for this contract is `FLIGHTS_INTEGRATION.md`.

## What the Hub is

`https://sales-hub.tourlast.com`. It **never writes** to toursflights. It either reads a bookings feed from you (Option A) or accepts bookings you send it (Option B). One booking = one row, matched on `external_id`. Re-sending is always safe; older `updated_at` values are ignored.

## Secrets (already configured on your side)

Both were generated on the Hub with `php artisan hub:generate-token` and are already set in toursflights' `.env`:

| toursflights `.env` key | Used for |
|---|---|
| `TOURLAST_HUB_TOKEN` | Bearer token on the Hub's pull (Option A) · the bearer you send to the Hub's push endpoint (Option B) |
| `TOURLAST_HUB_WEBHOOK_SECRET` | HMAC key when you sign instant updates (Option C) |

There is no user account, role or scope behind this token — possession of the secret is the whole credential. Treat both values like production passwords (out of git).

## Choose one of A or B, plus optional C

### Option A (recommended): read-only bookings endpoint

Expose, on toursflights:

```
GET {base}/bookings?updated_since=<ISO 8601>&page=<n>
Authorization: Bearer {TOURLAST_HUB_TOKEN}
Accept: application/json

200 {"data": [ {booking}, … ], "meta": {"current_page": 1, "last_page": 4}}
```

- `updated_since` filters on `flight_orders.updated_at >= updated_since`; omitting it means "everything" (the Hub uses that for the first full load).
- `meta.last_page` **or** a top-level `next_page` both work for pagination.
- Any non-2xx stops that sync run (nothing is deleted; it retries next run). One malformed booking is skipped and reported; the rest are applied.

### Option B: push to the Hub API

```
POST https://sales-hub.tourlast.com/api/v1/integrations/flights/bookings
Authorization: Bearer {TOURLAST_HUB_TOKEN}
Content-Type: application/json

{"bookings": [ {booking}, … ]}     // or a single {"booking": {…}}, max 500 per request
```

- Response: `{"received": n, "created": n, "updated": n, "unchanged": n, "failed": [{"external_id": …, "error": …}]}` — `422` only if every booking failed.
- After you start pushing, tell the Hub team to set `TOURLAST_SOURCE=push` (or `FLIGHTS_SOURCE=push` to override flights alone; the scheduled pull is then skipped).
- Push on every booking create/change; the booking record is the same shape as Option A.

### Option C (optional, alongside A or B): instant updates by webhook

```
POST https://sales-hub.tourlast.com/webhooks/flights
X-Tourlast-Signature: sha256=<hex HMAC-SHA256 of the raw body, keyed with TOURLAST_HUB_WEBHOOK_SECRET>
Content-Type: application/json

{"event_id": "<unique id>", "booking": { ...the same booking record... }}
```

```php
$body = json_encode(['event_id' => (string) Str::uuid(), 'booking' => $payload]);
Http::withBody($body, 'application/json')
    ->withHeaders(['X-Tourlast-Signature' => 'sha256='.hash_hmac('sha256', $body, config('services.hub.webhook_secret'))])
    ->post('https://sales-hub.tourlast.com/webhooks/flights');
```

- The `/flights` path tells the Hub which app sent the event; duplicate `event_id`s are ignored.
- The scheduled sync / push keeps running as a safety net regardless.

## The booking record (all options)

Snake_case keys. **Only `external_id`, `booked_at` and `booking_status` are required.** Dates are ISO 8601 with an offset. Amounts are **decimal numbers in `currency`** (not cents). Statuses are sent exactly as toursflights uses them; the Hub stores them lower-cased.

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

Flat keys also work for: `customer_name`, `customer_email`, `customer_phone`, `airline_code`, `airline_name`, `cancellation_status`, `cancelled_at`, `cancellation_reason`, `refund_status`, `refund_amount`, `refund_method`, `refund_requested_at`, `refund_completed_at`.

### Field mapping — what already exists in toursflights

| Hub field | Source in toursflights |
|---|---|
| `external_id` (required, stable, ≤80 chars) | `flight_orders.id` — pick a format once and never change it (e.g. `"FL-{id}"`). It is also what `{id}` becomes in the Hub's "Open in Flights Admin" link, so it must produce a working admin URL. |
| `booking_reference` / `pnr` | `flight_orders.booking_reference` (provider PNR) |
| `booking_status` (required) | `flight_orders.status` — send as-is (≤30 chars) |
| `booked_at` (required) | `flight_orders.created_at` |
| `updated_at` | `flight_orders.updated_at` — required in practice: it is the out-of-order guard and the `updated_since` filter |
| `customer.*` | `contact_name`, `contact_email`; phone from the first passenger (`provider_payload.passengers[0]`) |
| `origin`, `destination`, `departure_at`, `arrival_at`, `return_at`, `trip_type`, `cabin` | `provider_payload.slices[0]` (first slice = outbound; `return_at` from the return slice when present) |
| `segments[]` | `provider_payload.slices[].segments[]` — map `flight_number`, `marketing_carrier` → `airline`, `origin`, `destination`, `departure_at`, `arrival_at`, `cabin_class` → `cabin` |
| `passengers[].name` / `type` / `ticket_number` | `first_name + " " + last_name`; `type` (adult/child/infant); `ticket_numbers` JSON (by index) |
| `currency` | `flight_orders.currency` |
| `fare_amount`, `total_amount`, `markup_amount` | `flight_orders` amounts are **integer cents** → divide by 100 (`markup_amount` is your margin; `cost_amount` is not sent) |
| `payment_status` | `metadata.last_payment_status` (e.g. `succeeded` → send as `paid`, or send verbatim) |
| `cancellation{}` | newest row in `flight_cancellations` → `status`, `confirmed_at` → `cancelled_at` |
| `refund{}` | `flight_cancellations.refund_amount` (cents → decimal), `refund_currency`, `payment_refund_status`, `confirmed_at` |
| `agent_reference` | **does not exist yet — see the build list** |
| `promo_code` | **does not exist yet — see the build list** |

## Build list (the actual tasks)

1. **Bookings feed (Option A) or outbound push (Option B).** The existing `GET /api/v1/admin/flights/bookings` is close but does not match the contract: different path, no `updated_since` filter, and it returns `id`/`status`/`created_at`/`slices[]`/cents instead of the Hub's keys. Either add a Hub-shaped `/bookings` endpoint (a thin serializer over the existing admin query is enough) or a job that posts the Hub shape to the push endpoint.
2. **Serializer** emitting the record above: cents → decimals (÷100, 2 places), `slices` → `segments`, snake_case Hub keys, ISO 8601 dates with offset.
3. **Stable `external_id` format** (≤80 chars) that also works in `FLIGHTS_ADMIN_BOOKING_URL` (default `https://admin.flights.tourlast.com/bookings/{id}`).
4. **`agent_reference` — the selling travel salesperson's Hub email** (e.g. `aisha@tourlast.com`). Record who sold/entered each booking and send it. Without it the booking still syncs, but **nobody is credited for the sale**.
5. **`promo_code`** — the influencer code used on the booking, if the flights flow ever has one. Optional; without it no influencer commission is earned on flights.
6. **Confirm the refund status grouping:** open = `pending`, `requested`, `processing`, `in_progress`, `submitted`; done = `completed`, `refunded`, `paid`, `processed`. Map `flight_cancellations.status` / `payment_refund_status` into these words, or tell the Hub team your exact values.
7. **Decide `trip_type` values** — the Hub reads `one_way | return | multi_city`; send your own words if different and the Hub team will add them to the map.

## Money and status rules

- toursflights stores cents; the Hub wants decimals in `currency` (`11200.00`, not `1120000`). Round to 2 places.
- Status values (`pending`, `pending_payment`, `awaiting_payment`, `confirmed`, `ticketed`, `cancelled`, `voided`, `refunded`, `failed`) are all fine as-is — the Hub stores them verbatim (lower-cased) and renders its own labels.
- Bookings are matched on `external_id`; an incoming row older than the stored `updated_at` is ignored, so out-of-order delivery is safe.

## Verify the connection

```bash
# Option A — the Hub's exact call:
curl -H "Authorization: Bearer $TOURLAST_HUB_TOKEN" -H "Accept: application/json" \
  "{BASE}/bookings?page=1"
# expect 200 {"data": [...], "meta": {"current_page": 1, "last_page": N}}

curl -H "Authorization: Bearer $TOURLAST_HUB_TOKEN" -H "Accept: application/json" \
  "{BASE}/bookings?updated_since=2026-10-07T08:00:00%2B03:00&page=1"
# expect only bookings changed since that time

# Option B — push one booking:
curl -X POST "https://sales-hub.tourlast.com/api/v1/integrations/flights/bookings" \
  -H "Authorization: Bearer $TOURLAST_HUB_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"booking": {"external_id": "FL-1", "booked_at": "2026-10-07T10:03:00+03:00", "booking_status": "confirmed"}}'
# expect {"received":1,"created":1,...}

# Option C — webhook:
# sign a booking body with TOURLAST_HUB_WEBHOOK_SECRET and POST to .../webhooks/flights
# expect {"result": "created"} (then "duplicate" on replay)
```

Then on the Hub: `php artisan travel:sync-flights --full` (first load); every scheduled run prints `Flights synced from api: N seen, …`. Smoke test: book a test flight in toursflights and confirm it appears on the Hub's Flights page with the right salesperson credit and a working **Open in Flights Admin** link.

## Checklist

- [ ] Bookings feed (`GET /bookings` + `updated_since` + `page` + bearer) **or** push job to `POST /api/v1/integrations/flights/bookings` using `TOURLAST_HUB_TOKEN`
- [ ] Serializer: Hub record keys, cents → decimals, `slices` → `segments`
- [ ] Stable `external_id` format (≤80 chars) that builds a working admin URL
- [ ] `agent_reference` captured and sent (sales credit depends on it)
- [ ] `promo_code` sent if/when the flights flow has influencer codes
- [ ] Refund/cancellation status words confirmed against the Hub's grouping
- [ ] Webhook (optional): `POST /webhooks/flights` signed with `TOURLAST_HUB_WEBHOOK_SECRET`
- [ ] Full backfill run; then the Hub's 10-minute schedule takes over
