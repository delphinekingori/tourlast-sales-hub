# Source apps handoff — Sales Hub sync

Everything the **source apps** (`tourlast-stays`, `experiences-v1`) must build for
the next sync release, written so any agent (or future me) can pick it up without
re-reading the conversation. This file is self-contained: token setup, the
field-by-field contract, curl for both endpoints, the status map, the error and
retry contract, and the open questions are all below.

**Contract reference:** [TOURLAST_INTEGRATION.md](TOURLAST_INTEGRATION.md)
(capture flow, payload fields, options A/B),
[API.md → tourlast.com integration](API.md#tourlastcom-integration) (endpoint
reference and errors), [HUB_SYNC_ADDITIONS.md](HUB_SYNC_ADDITIONS.md) (what the
Hub ships in this release and each app's checklist).

---

## 0. Ground rules

- **One shared token, both directions.** On the Hub, a Super Admin runs
  `php artisan hub:generate-token`, which writes `TOURLAST_API_TOKEN=` into the
  Hub `.env` and prints the value once. **The same value goes into each source
  app's `.env` as `TOURLAST_HUB_TOKEN`.** It authenticates both directions:
  - app → Hub: pushes to `POST /api/v1/integrations/tourlast/providers` and
    pulls from `GET /api/v1/integrations/tourlast/ref-codes`.
  - Hub → app: the Hub's read of your `GET /api/sales-hub/referrals` feed.
  - No user account, no Sanctum token, no scope — possession of the secret is
    the whole credential.
- **The Hub pulls a read-only feed:** `GET {base}/api/sales-hub/referrals?updated_since=ISO8601&page=N`
  with `Authorization: Bearer {TOURLAST_HUB_TOKEN}`, returning
  `{"data": [...], "next_page": int|null}` (50 rows/page). The Hub never
  writes to a source app.
- **`GET /api/v1/integrations/tourlast/sync-runs` and `POST .../sync` are
  Hub-internal** (Sanctum token + `integration:read` / `integration:push`, Hub
  admin). Source apps never call them.
- **The old `php artisan hub:create-integration-account`** (Sanctum integration
  user + printed token) is retired. Do not document or use it anymore.
- **Points are the Hub's business** — see [§9](#9-points-incentives-and-payouts-are-the-hubs-business).

---

## 1. Token setup, per environment

Each Hub environment (local, staging, production) has **its own token**. Never
reuse one environment's token in another.

1. On that Hub's server: `php artisan hub:generate-token`. It writes a fresh
   random `TOURLAST_API_TOKEN=` into the Hub `.env` and prints the value
   **once**.
2. Store it as a secret on the source app servers, and put the same value in
   each app's `.env` as `TOURLAST_HUB_TOKEN`. Restart queue workers and PHP-FPM
   so the new value is picked up.
3. Verify against the Hub:

   ```bash
   curl "https://sales.tourlast.com/api/v1/integrations/tourlast/ref-codes" \
     -H "Authorization: Bearer $TOURLAST_HUB_TOKEN" \
     -H "Accept: application/json"
   ```

4. **Rotation:** run `--show` first if you need the current value, then run the
   command plainly to replace it — every app must be updated with the new value
   or its calls start failing with `401`.
5. `503` means the Hub itself has no token configured yet: nothing the app can
   fix, tell the Hub admin to run the command.

---

## 2. What each app must build

1. **Capture `?ref=` on `/list-your-property`** and keep it for 60 days, so a
   provider who comes back later still counts:

   ```php
   // Laravel example, e.g. in the List Your Property controller or a middleware
   if ($ref = request()->query('ref')) {
       cookie()->queue('tl_ref', strtoupper(substr($ref, 0, 40)), 60 * 24 * 60); // 60 days
   }
   ```

2. **Validate the code against the Hub** (§5) — pull the list, cache it hourly,
   and only store a code it returns. Unknown code → unattributed, never
   invented, never guessed from an email address.
3. **Store `ref_code` on the property row you emit.** Keep the first code a
   provider arrived with; later visits through other links do not overwrite it.
4. **Emit every row** through the pull feed (§6), the push endpoint (§4), or
   both. Field contract is identical either way (§3).
5. **Emit `inactive_at`** (§3, §7): send `status: "inactive"` plus the moment the
   listing stopped being live, with `inactive_at: "<ISO8601>"`. The Hub falls
   back to `updated_at` when it is absent.
6. **Emit tombstones**: when a referred property is deleted, keep emitting its
   row with `is_deleted: true`, `deleted_at`, and `updated_at` bumped to the
   deletion time (incremental sync is driven by `updated_since` — a tombstone
   that does not look "changed" is never seen). Emit tombstones indefinitely;
   if one disappears from the feed the Hub cannot tell "still deleted" from
   "feed broken". On restore, emit the row again with `is_deleted: false` and a
   bumped `updated_at`.
7. **Send the account facts** the Hub needs for incentives: `account_id`,
   `legal_name`, `category`, `inventory_count`, `first_booking_at`
   ([TOURLAST_INTEGRATION.md → Account data for incentives](TOURLAST_INTEGRATION.md#account-data-for-incentives-schedule-1)).
   Send accurate facts only — the Hub does the arithmetic (§9).

---

## 3. Field reference

Same fields for the pull feed, the push endpoint and the webhook body.

| Field | Type | Required | Meaning | Example |
|---|---|---|---|---|
| `property_id` | string | yes | Your unique, stable property ID. The Hub matches on it — it never changes | `"TL-00842"` |
| `ref_code` | string | recommended | Referral code the provider arrived with, validated against §5 (case-insensitive; the Hub upper-cases). A null/unknown code never clears credit the Hub already has | `"TL-JOHN-2847"` |
| `property_name` | string | yes | Display name | `"ABC Hotel"` |
| `property_type` | string | yes | Mapped to Hub types with `type_map`; unknown values are kept as sent | `"hotel"` |
| `location` | string | no | Free text | `"Nairobi, Kenya"` |
| `contact_name`, `contact_phone`, `contact_email` | string | recommended | Link the signup to the salesperson's lead and feed duplicate checks | `"Jane Mwangi"` |
| `status` | string | yes | Source status, translated with `status_map` (§7) | `"active"` |
| `submitted_at` | ISO 8601 | as it happens | Sign-up time | `"2026-09-20T09:41:00+03:00"` |
| `approved_at` | ISO 8601 | as it happens | Admin approval time | `"2026-09-24T10:12:00+03:00"` |
| `active_at` | ISO 8601 | as it happens | **Activation Date** — the moment the property went live and bookable; decides the month and bonus week the Hub credits | `"2026-09-25T08:00:00+03:00"` |
| `inactive_at` | ISO 8601 | when inactive | Moment the property went inactive; the Hub records it as the status-history date for `Inactive`, falling back to `updated_at` when absent. Credit is **kept** | `"2026-09-28T14:05:00+03:00"` |
| `rejected_at` | ISO 8601 | as it happens | Rejection time — the only state that claws credit back | `null` |
| `is_deleted` | boolean | no (default false) | Tombstone: `true` soft-deletes the record in the Hub (and counts in `records_deleted`); `false` restores it | `false` |
| `deleted_at` | ISO 8601 | when deleted | When it was deleted in the app, paired with `is_deleted` | `null` |
| `updated_at` | ISO 8601 | recommended | Row version. Older than the last one the Hub saw → ignored, so out-of-order deliveries are safe. **Bump it on tombstones** | `"2026-09-29T08:00:00+03:00"` |
| `account_id` | string | for incentives | Host / legal business behind the property; every property of one entity shares it | `"H-2031"` |
| `legal_name` | string | for incentives | Registered name of that entity | `"ABC Hospitality Ltd"` |
| `category` | string | for incentives | `stay` or `experience` | `"stay"` |
| `inventory_count` | integer | for incentives | Live rooms/units (stays) or live bookable services (experiences) | `45` |
| `first_booking_at` | ISO 8601 | optional | First booking; fires the Hub's "First booking received" alert | `null` |

---

## 4. Push — `POST /api/v1/integrations/tourlast/providers`

**Auth:** the shared token. Send one record as `provider`, or up to 100 as
`providers`. Always send a full row (every required field), not just the field
that changed.

```bash
curl -X POST "https://sales.tourlast.com/api/v1/integrations/tourlast/providers" \
  -H "Authorization: Bearer $TOURLAST_HUB_TOKEN" \
  -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{
    "providers": [
      {
        "property_id": "TL-00842",
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
        "is_deleted": false,
        "deleted_at": null,
        "updated_at": "2026-09-24T10:12:00+03:00",
        "account_id": "H-2031",
        "legal_name": "ABC Hospitality Ltd",
        "category": "stay",
        "inventory_count": 45,
        "first_booking_at": null
      }
    ]
  }'
```

The two new row shapes:

```json
{
  "providers": [
    {
      "property_id": "TL-01109",
      "property_name": "Sunset Lodge",
      "property_type": "lodge",
      "status": "inactive",
      "active_at": "2026-06-02T09:00:00+03:00",
      "inactive_at": "2026-09-28T14:05:00+03:00",
      "updated_at": "2026-09-28T14:05:00+03:00"
    },
    {
      "property_id": "TL-00777",
      "property_name": "Old Camp",
      "property_type": "lodge",
      "status": "active",
      "is_deleted": true,
      "deleted_at": "2026-09-29T08:00:00+03:00",
      "updated_at": "2026-09-29T08:00:00+03:00"
    }
  ]
}
```

Response `200` (or `422` if every record failed):

```json
{
  "data": [
    { "index": 0, "property_id": "TL-00842", "result": "created" },
    { "index": 1, "property_id": null, "error": "A provider record is missing its property_id." }
  ],
  "meta": { "received": 2, "created": 1, "updated": 0, "unchanged": 0, "failed": 1 }
}
```

Each row gets its own `result` — `created`, `updated` or `unchanged` — or an
`error`. Everything (crediting, status history, Smart Alerts, deletion)
runs exactly as for the scheduled sync. Full reference:
[API.md → POST /integrations/tourlast/providers](API.md#post-integrationstourlastproviders).

---

## 5. Ref codes — `GET /api/v1/integrations/tourlast/ref-codes`

**Auth:** the shared token. The Hub owns the list; Super Admins with the
`manage-ref-codes` permission edit codes in **Admin → Users & Invites → Edit**
(team page edit slide-over), and this endpoint always returns the current truth.

```bash
curl "https://sales.tourlast.com/api/v1/integrations/tourlast/ref-codes" \
  -H "Authorization: Bearer $TOURLAST_HUB_TOKEN" \
  -H "Accept: application/json"
```

```json
{
  "data": [
    { "code": "TL-JOHN-2847", "is_active": true, "user_id": 12, "user_name": "John Doe", "updated_at": "2026-09-20T10:00:00+03:00" }
  ]
}
```

- Active codes only (inactive codes are not returned), sorted by `code`.
  Treat `code` as the key.
- **Cache it hourly — do not call it per request.**
- Use it to validate `?ref=` on `/list-your-property` and to stamp `ref_code`
  on the rows you send back. A code the Hub does not return is unknown →
  unattributed, never invented.

---

## 6. Read feed — `GET /api/sales-hub/referrals` (Option A)

Only if the Hub reads your app (instead of, or alongside, pushes):

```bash
curl "https://www.tourlast.com/api/sales-hub/referrals?updated_since=2026-09-29T09:00:00%2B03:00&page=1" \
  -H "Authorization: Bearer $TOURLAST_HUB_TOKEN" \
  -H "Accept: application/json"
```

```json
{
  "data": [
    {
      "property_id": "TL-00842",
      "ref_code": "TL-JOHN-2847",
      "property_name": "ABC Hotel",
      "property_type": "hotel",
      "status": "approved",
      "submitted_at": "2026-09-20T09:41:00+03:00",
      "approved_at": "2026-09-24T10:12:00+03:00",
      "active_at": null,
      "inactive_at": null,
      "is_deleted": false,
      "deleted_at": null,
      "updated_at": "2026-09-24T10:12:00+03:00"
    }
  ],
  "next_page": 2
}
```

- 50 rows per page; `next_page` is `null` on the last page.
- Return the same fields as §3, including `inactive_at` and the tombstone
  fields.
- The Hub pulls on a schedule (`TOURLAST_SYNC_EVERY_MINUTES`, default 10) and
  never writes to your app — it only reads this feed.
- If the Hub is set to `TOURLAST_SOURCE=push`, the scheduled read is skipped —
  confirm which mode your app is used in before relying on the feed.

---

## 7. Status map

`status` is translated in the Hub's `config/tourlast.php` → `status_map`.
Matching ignores case.

| Source values (examples) | Hub status | Hub label |
|---|---|---|
| `draft`, `pending`, `submitted` | `submitted` | Submitted |
| `in_review`, `under_review`, `review` | `under_review` | Under review |
| `approved`, `verified` | `approved` | Approved, going live |
| `active`, `live`, `published` | `active` | Live |
| `inactive`, `paused` | `inactive` | Inactive — **new in this release** |
| `rejected`, `declined`, `suspended`, `deleted` | `rejected` | Rejected |

- A status the map does not know falls back to `submitted`. If your app uses a
  value that is not listed, ask for it to be added rather than relying on the
  fallback.
- **Do not use a status of `deleted` for a tombstone.** As a status it maps to
  `rejected` — credit withdrawn. Deletion is the separate `is_deleted` flag,
  which keeps credit.
- Only `rejected` claws credit back. `inactive` keeps it, and so does
  deletion.

---

## 8. Attribution and credit rules

- The Hub is the **source of truth for `ref_code`.** A row with a null code
  never clears credit the Hub already has; send the code you validated (§5) or
  nothing.
- An admin's manual assignment on the Hub is never overwritten by later syncs.
- Credit lands on the **Activation Date** (`active_at`, first moment the
  status is `active`). `inactive_at` and `is_deleted` change what admins see —
  the credit stays, marked accordingly. Only `rejected` removes it.
- Deletion keeps credit: the Hub shows a **Deleted** badge, admins can restore,
  and a later row with `is_deleted: false` restores it too.

---

## 9. Points, incentives and payouts are the Hub's business

**Source apps must NOT reimplement points, incentives, payouts or any credit
arithmetic.** Send accurate facts only — `status`, the dates in §3, and the
account/inventory fields. The Hub decides who is credited, when, and how much
(`docs/SYSTEM_GUIDE.md`, and Schedule 1 in
[TOURLAST_INTEGRATION.md → Account data for incentives](TOURLAST_INTEGRATION.md#account-data-for-incentives-schedule-1)).

---

## 10. Errors and retries

| Status | Body | What to do |
|---|---|---|
| `401` | `{"message": "Missing or invalid shared token."}` | No bearer, or not exactly the Hub's `TOURLAST_API_TOKEN`. Check `TOURLAST_HUB_TOKEN` (§1) |
| `503` | `{"message": "This Hub has no shared sync token yet. Run php artisan hub:generate-token."}` | The Hub is not set up — tell the Hub admin; retry later |
| `422` | `{"message": "…", "errors": {…}}`, or the normal `data`/`meta` body | No `provider`/`providers`, more than 100 records, or every row failed (`meta.failed` = `meta.received`) |
| `429` | `{"message": "Too Many Attempts."}` | Rate limited — retry after the `Retry-After` header ([API.md → Errors](API.md#errors)) |
| `5xx` / network error | — | Nothing from that request was saved — resend the whole batch |

**Re-sending is safe:**

- The Hub matches on `property_id` — a resend updates the existing onboarding
  and never creates a duplicate (idempotent per `property_id`).
- Rows older than the last `updated_at` the Hub saw are ignored, so
  out-of-order or replayed deliveries are harmless.
- Per-row `error`s are logged in the response; they are usually a missing
  `property_id`. Fix and resend — the whole batch may be resent.
- Log `meta.failed` on every response; treat a non-zero value as a failure to
  retry.

---

## 11. Where each app stands today

| | tourlast-stays | experiences-v1 |
|---|---|---|
| Local path | `C:\mylaravel\tourlast-stays` | `C:\mylaravel\experiences-v1` |
| Remote | `dev-tourlast/hotelPMS_V1` | `dev-tourlast/experiences-v1` |
| Branch with feed work | `sales-hub` @ `b1a918f1` (**not pushed**) | `sales-hub` @ `bd07875` (pushed) |
| Current checkout | `sales-hub` + unrelated dirty files | `partnerconnect-experinces` + stalled merge (~12 conflicts), stash "hub feed wip" |
| Feed route | `GET /api/sales-hub/referrals`, `ValidateHubToken` | same (on `sales-hub` branch only) |
| Token env | `TOURLAST_HUB_TOKEN` | `TOURLAST_HUB_TOKEN` |

**Before any app work starts:** the experiences repo must finish/abort its
stale merge and check out `sales-hub`; the stays branch should be pushed.
Neither repo has been modified for any of §2 — this doc is the spec.

### tourlast-stays specifics

- The feed currently filters `hotels.hotel_status = 1`, so a deleted hotel
  simply vanishes. Include rows that were ever approved and are now deleted,
  flagged `is_deleted: true` (query: `hotel_status = 1 OR (deleted_at IS NOT
  NULL …)` — whatever the app's delete mechanism is; confirm whether stays
  soft- or hard-deletes hotels and adapt).
- Approved-but-not-live listings currently ship as `approved`; send
  `inactive` + `inactive_at` instead when `hotels.status` is not `Active`
  while `hotel_status = 1`.
- `ref_code` is currently guessed from `pending_registrations.referral_code`
  keyed by owner email, which breaks when the email differs. Store the code you
  validated from §5 on the property itself (a `hotels.ref_code` column would
  remove the join).
- `?ref=` is not captured yet: `resources/views/ListProperty.blade.php` builds
  `$signUpUrl = route('user.register')` with no `?ref=`, and
  `BusinessInfoController::showListProperty()` ignores the query string.

### experiences-v1 specifics

- No referral concept at all — `ref_code` is hardcoded `null`. A product
  decision is needed before §2 items 1–3 are built (see §13).
- Same rules for its listings/experiences table: `inactive` + `inactive_at`
  for the not-live state, tombstones for deletes.

### Tests to write in each app

- Valid code from the Hub resolves and lands on the feed row; an unknown code
  sends `null`, not a guess.
- An approved listing that goes not-live emits `inactive` + `inactive_at`;
  once live again it emits `active`.
- Feed returns a tombstone after delete, with a fresh `updated_at`; restore
  flips `is_deleted` back to `false`.

---

## 12. Suggested order of work

1. One shared token: copy the value printed by `hub:generate-token` into both
   apps' `.env` (per environment, §1), confirm the existing feed pull still
   works with it.
2. Ref-code endpoint consumption (§5) — unblocks correct attribution.
3. `inactive` + `inactive_at` (§2.5) — small, independent.
4. Tombstones (§2.6) — touches each app's delete path, test carefully.
5. Optional push (§4).

Each item is independent; do them as separate commits per app.

---

## 13. Open questions and deliberately out of scope

### Deliberately out of scope

- **Webhook path is optional.** `POST /webhooks/tourlast` with an
  `X-Tourlast-Signature` HMAC header works alongside push and the scheduled
  pull ([TOURLAST_INTEGRATION.md → Optional: instant updates](TOURLAST_INTEGRATION.md#4-optional-instant-updates-webhook)).
  Not required for this release.
- **Push is optional.** The scheduled pull alone is enough for correctness;
  push is a latency improvement (§4).
- **Hub-internal endpoints.** `GET .../sync-runs` and `POST .../sync` are
  Hub-admin only — never call them from a source app.
- **Points, incentives and payouts** — Hub-side only (§9).

### Open questions

- **stays delete mechanism:** does tourlast-stays soft- or hard-delete hotels?
  The tombstone query depends on it.
- **stays `ref_code` storage:** add a `hotels.ref_code` column, or keep the
  `pending_registrations` email join?
- **experiences referral decision:** there is no referral concept today — is
  `?ref=` capture wanted on experiences-v1 at all, and under what URL?
- **experiences not-live state:** which stored state should map to `inactive`
  (the provider row has no separate delist event — `approved_at` doubles as
  `active_at`)?
- **Which mode each app runs in:** pull feed only (Option A), push only
  (`TOURLAST_SOURCE=push` on the Hub), or both? Decided per app before §4/§6
  work starts.
- **Feed filter for tombstones:** the exact stays query once the delete
  mechanism is known (§11).
