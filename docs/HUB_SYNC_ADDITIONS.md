# Hub sync additions

What changed so the Hub reads properties the way each source app actually stores them, plus the gaps that are deliberately still open.

Apps: `tourlast-stays` (Laravel 10), `experiences-v1` (Laravel 12).
Hub: `tourlast-sales-hub`.

## What the Hub receives now

| Behaviour | Before | After |
|---|---|---|
| Which rows are sent | Every row in the table | Only rows the app's admin has approved |
| `property_type` | Derived, fell back to `other` for unknown values | The value stored on the row |
| `approved_at` | Null for all 83 stays (read from `stays.approved_at`, never set) | Set for all 87 records, from the app's own approval stamp |
| `active_at` | Same as `approved_at`, so also null | Set only when the listing is live (`status = Active` / provider approved) |

Verified after the change: 83 stays + 4 experiences, `approved_at` null = 0, `active_at` null = 0, all `status = active`.

## Additions

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

- **No delete/tombstone**: the feed is create and update only. A property deleted in a source app stays in the Hub until someone removes it by hand.
- **No push/webhooks from either app**: the Hub only learns of changes on the scheduled pull (`TOURLAST_SYNC_EVERY_MINUTES`, default 10).
- **Archived listings**: stays now filters on `hotel_status = 1`, but `hotels.status = inactive` with `hotel_status = 1` still ships as `approved` rather than being hidden.
