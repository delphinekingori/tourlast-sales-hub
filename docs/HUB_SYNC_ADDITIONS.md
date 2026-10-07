# Hub sync additions

What the Hub ships for the tourlast.com sync — this release first, then the earlier feed-shaping batch — plus what each source app still owes.

Apps: `tourlast-stays` (Laravel 10), `experiences-v1` (Laravel 12).
Hub: `tourlast-sales-hub`.
Source-app spec: [SOURCE_APPS_HANDOFF.md](SOURCE_APPS_HANDOFF.md).

## This release — status

| What | State |
|---|---|
| **One shared token, both directions.** `php artisan hub:generate-token` writes `TOURLAST_API_TOKEN=` into the Hub `.env` and prints it once; each source app stores the same value as `TOURLAST_HUB_TOKEN`. It authenticates app → Hub pushes and Hub → app reads. No user account, no Sanctum token, no scope. | **Live now** |
| **`GET /api/v1/integrations/tourlast/ref-codes`** — active codes only, `{ code, is_active, user_id, user_name, updated_at }`, sorted by `code`. Edited in **Admin → Users & Invites → Edit** (team page edit slide-over) by Super Admins with the `manage-ref-codes` permission. | **Live now** |
| **`POST /api/v1/integrations/tourlast/providers`** on the shared token — up to 100 rows, per-row `result` (`created`/`updated`/`unchanged`) or `error`. | **Live now** |
| Error contract for both: `401 {"message": "Missing or invalid shared token."}`; `503` when the Hub has no token yet (`"This Hub has no shared sync token yet. Run php artisan hub:generate-token."`). | **Live now** |
| `GET .../sync-runs` and `POST .../sync` stay Hub-admin (Sanctum + `integration:read` / `integration:push`) — source apps never call them. | Unchanged |
| **`inactive_at`** — the moment a property went inactive (falls back to `updated_at`), recorded as the status-history date for `OnboardingStatus::Inactive`. Credit is **kept**; only `Rejected` claws back. | **Ships in this release** |
| **`is_deleted` / `deleted_at`** — the Hub soft-deletes the onboarding and its linked lead/engagement, counts it in `records_deleted` on the sync run, and leaves points/credit untouched (a "Property X deleted" marker). Admins see a **Deleted** badge and can restore; a later `is_deleted: false` row restores it. | **Ships in this release** |

**Before release, verify in code:** the `inactive` case and label exist in `app/Enums/OnboardingStatus.php`, the matching entry exists in `config/tourlast.php` → `status_map`, sync runs expose `records_deleted`, and the admin **Deleted** badge + restore path are merged. (None of these were present in the repo at the time of writing — they land with this release.)

### Hub rules the release relies on

- `ref_code`: the Hub is the source of truth. A null/unknown code never clears credit the Hub already has; an admin's manual assignment is never overwritten.
- Credit lands on the Activation Date (`active_at`). `inactive` keeps it, deletion keeps it (marked), only `rejected` removes it.
- Deletion is soft and restorable — by a Hub admin or by the app sending `is_deleted: false` again.

## What the source apps owe

### Both apps

- [ ] `TOURLAST_HUB_TOKEN` set from `hub:generate-token`, per environment ([handoff §1](SOURCE_APPS_HANDOFF.md#1-token-setup-per-environment)).
- [ ] `?ref=` captured on `/list-your-property` and kept for 60 days ([handoff §2](SOURCE_APPS_HANDOFF.md#2-what-each-app-must-build)).
- [ ] Ref-code list pulled from the Hub hourly, `?ref=` validated against it, unknown code stored as `null`.
- [ ] `ref_code` stored on the property row and sent on every row.
- [ ] Rows emitted with the full field contract — pull feed and/or push ([handoff §3](SOURCE_APPS_HANDOFF.md#3-field-reference)).
- [ ] `status: "inactive"` + `inactive_at` emitted whenever a listing stops being live.
- [ ] `is_deleted: true` / `deleted_at` tombstones emitted with a bumped `updated_at`, indefinitely; restore flips the flag back to `false`.
- [ ] Account facts sent: `account_id`, `legal_name`, `category`, `inventory_count`, `first_booking_at`.
- [ ] **No** points, incentives or payout logic added in the app — facts only.

### tourlast-stays

- [ ] Capture `?ref=` — `resources/views/ListProperty.blade.php` builds the sign-up URL without it and `BusinessInfoController::showListProperty()` ignores the query string.
- [ ] Replace the `pending_registrations` email-join `ref_code` guesswork with the validated, stored code.
- [ ] Relax the feed's `->where('hotel_status', 1)` filter so tombstones ship (deleted-but-ever-approved rows).
- [ ] Ship approved-but-not-live listings as `inactive` + `inactive_at` instead of `approved`.
- [ ] Confirm whether hotels are soft- or hard-deleted (the tombstone query depends on it).

### experiences-v1

- [ ] Product decision first: there is no referral concept today (`ref_code` hardcoded `null`) — see [handoff §13](SOURCE_APPS_HANDOFF.md#13-open-questions-and-deliberately-out-of-scope).
- [ ] Decide which stored state maps to `inactive` (no separate delist event on the provider row).
- [ ] Tombstones for its listings/experiences table.
- [ ] Repo hygiene before feature work: finish/abort the stalled merge and check out `sales-hub`.

## Earlier batch — what the Hub receives now

The earlier feed-shaping round (before this release): what changed in the rows the Hub reads.

| Behaviour | Before | After |
|---|---|---|
| Which rows are sent | Every row in the table | Only rows the app's admin has approved |
| `property_type` | Derived, fell back to `other` for unknown values | The value stored on the row |
| `approved_at` | Null for all 83 stays (read from `stays.approved_at`, never set) | Set for all 87 records, from the app's own approval stamp |
| `active_at` | Same as `approved_at`, so also null | Set only when the listing is live (`status = Active` / provider approved) |

Verified after the change: 83 stays + 4 experiences, `approved_at` null = 0, `active_at` null = 0, all `status = active`.

## Earlier batch — additions by app

### tourlast-stays

1. **New column `hotels.approved_at`**
   `database/migrations/2026_09_29_170410_add_approved_at_to_hotels_table.php`
   - Adds `hotels.approved_at TIMESTAMP NULL`.
   - Backfills every hotel already approved (`hotel_status = 1`) from the linked stay's `approved_at`, or `hotels.updated_at` when there is no stay row.
   - Why: `hotels.hotel_status` only records *that* an admin approved, not *when*, and `stays.approved_at` was empty on all 67 stays. The Hub credits onboarding points to the month and bonus week of `active_at`, so it needs the timestamp.

2. **Approval stamps it**
   - `app/Http/Controllers/Admin/HotelController.php` -> `hotelapprovalStatus()`: sets `approved_at` to `now()` on first approval, keeps it on re-approval, clears it when unapproved.
   - `app/Http/Controllers/Admin/StayController.php` -> `toggleApproval()`: same, alongside the existing `stays.approved_at`.

3. **Feed filters on approval**
   `app/Http/Controllers/Hub/HubController.php`
   - `->where('hotel_status', 1)` so only admin-approved listings are sent.
   - `approved_at` = `hotels.approved_at`, falling back to `stays.approved_at`.
   - `active_at` = the approval time only while `hotels.status = 'Active'`.
   - `property_type` = `hotels.stay_type` as stored, falling back to `stays.stay_type`, then `hotel` when the type is still empty.

### experiences-v1

1. **Feed filters on approval**
   `app/Http/Controllers/Hub/HubController.php`
   - `->whereNotNull('approved_at')` so only approved providers are sent (`approved_at` is set by `Admin/ProviderController.php`).

2. **Dates already come from the app record**
   - `approved_at` and `active_at` are both `experience_providers.approved_at`. No column was needed: a provider becomes active the moment it is approved, and there is no separate go-live event to store.

3. **Property type**
   - `property_type` stays `experience`. The provider row has no type column, and `experiences.category` is a marketing category (Safari Tours, Wildlife, Water Sports, ...), not a property type. `category` in the payload remains `experience`.

### tourlast-sales-hub

1. **`config/tourlast.php` -> `type_map`**
   Added the stay types the apps actually store, so they stop collapsing to `other`:
   `lodge`, `chalet`, `farm_stay`, `treehouse`, `boat`, `houseboat`.

2. **`config/hub.php` -> `property_types`**
   Added the matching Hub types with labels: Chalet, Farm stay, Treehouse, Boat / Houseboat.
   (`lodge` already existed but was missing from `type_map`, so it still fell through to `other`.)

3. **API only** (earlier in this batch): `config/database.php` no longer defines a `tourlast` connection, `TOURLAST_SOURCE=database` is rejected, and the `TOURLAST_DB_*` / `TOURLAST_COL_*` variables were removed from `.env` and `.env.example`. The Hub reaches source apps over HTTP only.

## Held, not implemented

Deliberately left out of this round. Ordered by what they block.

### 1. `ref_code` is null on every record

Nothing can be credited to a salesperson without it.

- **Status:** the Hub-side fix is live — `GET /integrations/tourlast/ref-codes` (this release) plus the credit rules above. The **app-side** capture / validate / stamp work remains owed and is in the checklist.
- **stays**: the save path already exists (`app/Services/HotelRegistrationService.php` reads `$validated['ref']`), but the code never arrives. `resources/views/ListProperty.blade.php` builds `$signUpUrl = route('user.register')` with no `?ref=`, and `BusinessInfoController::showListProperty()` ignores the incoming query string. Fix: capture `?ref=` into the 60-day cookie and append it to the sign-up and sign-in links.
- **stays, second problem**: the feed can only recover a code by joining `pending_registrations` on the owner's email, which breaks when the email differs. A `hotels.ref_code` column would remove the join.
- **experiences**: no referral concept at all, `ref_code` is hardcoded `null`. Needs a product decision before anything is built.

### 3. `first_booking_at` is null on 82 of 87

The Hub's first-booking alert and bonus cannot fire for stays. The feed already runs `withMin('bookings', 'created_at')`, so the missing part is data: these hotels have no booking rows in the app.

### 4. `contact_name` / `contact_phone` null on all 83 stays

The feed reads `owner_name`, `personInCharge`, `contractNameperson`, `mobile_no`, `contractMobile_no`. Every one is empty for these hotels, so the Hub's contact card and call tasks have nothing to show. Experiences are complete.

### 6. `rejected_at` null on all 87

Neither app reports a rejection, so the Hub cannot show when credit was withdrawn. stays can derive it from `hotel_status = 2`; experiences has no rejection state on the provider row.

### 8. `legal_name` falls back to `hotel_name` in stays

There is no reliable legal-entity name in the data (`hotel_legal_name` and `businesslegalname` are empty), so the Hub shows the marketing name. Experiences sends `business_name`, which is correct.

## Also not in scope

- **Delete/tombstones — now in this release, but owed by the apps.** The Hub-side handling ships with this release (see the status table); until an app emits `is_deleted` / `deleted_at`, a property deleted in a source app still stays in the Hub.
- **No push or webhook from either app yet**: the Hub only learns of changes on the scheduled pull (`TOURLAST_SYNC_EVERY_MINUTES`, default 10). The push endpoint is live and optional; the signed webhook path is optional too — see [SOURCE_APPS_HANDOFF.md §13](SOURCE_APPS_HANDOFF.md#13-open-questions-and-deliberately-out-of-scope).
- **Archived listings**: stays now filters on `hotel_status = 1`, but `hotels.status = inactive` with `hotel_status = 1` still ships as `approved` rather than `inactive` until stays emits the new status and `inactive_at`.
