# Tourlast Sales Hub — Technical & AI Readiness Audit

**Audit date:** 30 October 2026
**Scope:** read-only audit of `tourlast-sales-hub` (this repository), plus the Stays app only where it feeds the Hub.
**Method:** code read and traced, local database queried read-only (row counts and aggregates), Laravel AI SDK official docs read, `composer show laravel/ai` run (metadata only, nothing installed).
**Rules followed:** no application code changed, no packages installed, no migrations run, no records changed, no `.env` or config touched, no secrets reproduced.

Legend used throughout: **[V]** verified in code or data during this audit · **[U]** unverified or assumed · **[L]** true of the local database only (see 5.1).

---

## 0. The most important finding: the brief and the system do not match

The audit brief describes the Hub as a place where *marketers use referral codes across Tourlast products so that bookings, sales and other transactions are attributed to them, with commissions*.

**That is not what the Hub does [V].** What the code actually implements:

| Brief assumes | What exists |
|---|---|
| Referral codes attribute **bookings and revenue** to marketers | `TL-NAME-0000` codes attribute **provider signups** (a hotel, lodge, experience operator signing up on Stays or Experiences). The credit is "this property was onboarded by this salesperson". No booking amount from Stays or Experiences ever enters the Hub. |
| Commissions vary by product | The salesperson pay model is **points per onboarded partner account** (Schedule 1: inventory bands, retainer, weekly and monthly bonuses), not a percentage of revenue. Revenue commission exists only for **influencers** in Travel Sales, a separate concept. |
| Cross-product reporting of bookings | Booking data exists **only** for Travel Sales: package bookings (created inside the Hub) and flight bookings (synced from the Flights app). |
| "Marketers" | The user group is **salespeople** (`salesperson`, `sales-manager`, `sales-admin`), who recruit providers. Travel staff are a separate role (`travel-salesperson`). Influencers are external people with their own codes, not users. |

This matters more than any other point in the report. Several of the AI capabilities in the brief (revenue by marketer, cancellation rate per marketer, booking value per marketer, product conversion by booking) **cannot be built on the Hub's current data** for Stays and Experiences, because that data is not in the Hub. Section 5 and Section 6 are written against what is really there.

---

## 1. Executive summary

The Hub is a mature, well-tested internal system for **acquiring and managing providers** and for **paying the people who acquire them**. It also contains a **Travel Sales** workspace for selling flights, tour packages and experiences.

- **Provider acquisition:** salespeople share a tracked link (`/r/TL-JOHN-4586`). The Hub counts the click, forwards the provider to the Stays or Experiences signup with `?ref=<code>`, and later reads the provider record back (nightly full sync, 10-minute incremental sync, or an instant signed webhook). The signup's referral code credits a salesperson. [V]
- **Sales management:** leads, activities, follow-ups, calendar, a Property Engagement Registry (every property ever contacted), targets, transfers, suspensions, announcements and Smart Alerts. [V]
- **Pay:** Schedule 1 points per partner account, monthly statements, claims with a manager, HR and finance chain. [V]
- **Travel Sales:** providers and contracts, packages with two-level approval, departures, package bookings, M-Pesa payments and refunds, influencer codes with commissions, and read-only flight bookings. [V]
- **API:** Sanctum v1 API with token scopes, plus integration feeds for Stays, Experiences and Flights. [V]
- **Maturity:** high for the property side and Travel Sales (81 test files, actions-based architecture, audit log, row locking on money paths). Weakest areas for analytics: no revenue from Stays or Experiences, no referral channel data beyond product, and the local data is too thin to judge production [L].
- **AI today:** none. No AI package, config, route or class exists. [V]

**Bottom line for AI:** the Hub holds clean, structured, permission-checked **performance metrics** (onboardings, points, clicks, targets, travel sales). That is enough for a safe, useful first AI feature that **writes about numbers the Hub already computes**. It is *not* enough for the revenue and booking analytics the brief imagines. Laravel AI SDK is compatible and good for tool-based agents, but the best first feature does not need an agent.

---

## 2. Application architecture

### 2.1 Stack [V]

| Item | Finding | Source |
|---|---|---|
| PHP | `^8.3` required; CLI on this machine runs 8.5.12 | `composer.json`, `php -v` |
| Laravel | 13.33.0 (`laravel/framework ^13.17`) | `php artisan --version` |
| Frontend | Livewire 4.4, Alpine.js, Tailwind CSS 4, Vite 8, Laravel Echo + Pusher client, `qrcode` | `composer.json`, `package.json` |
| Auth | Session login (invitation-only, no public registration); Sanctum tokens for the API | `routes/web.php`, `routes/api.php` |
| Permissions | `spatie/laravel-permission ^8.3`; roles and permissions defined as enums and seeded | `app/Enums/Role.php`, `app/Enums/Permission.php` |
| Exports | `maatwebsite/excel ^4`, `barryvdh/laravel-dompdf` | `composer.json` |
| Realtime | Pusher / Echo for notifications | `routes/channels.php` |
| Database | MySQL (local DB `tourlast_sales_hub`); 71 migrations | `.env` (name only), `database/migrations` |
| Queue | `QUEUE_CONNECTION=database` locally; only four classes are queued (below) | `.env`, grep `ShouldQueue` |
| Tests | 81 test files under `tests/Feature` and `tests/Unit` | `tests/` |
| Existing AI | **None** | grep for openai, anthropic, laravel/ai, prism, gemini, llm, embedding across `app`, `config`, `routes`, `composer.json`, `resources/views` returned nothing |

### 2.2 Code layout [V]

- `app/Actions` (90 files): the business logic. One class per operation (`ApplyProviderRecord`, `AssignOnboarding`, `IssueReferralCode`, `Travel/Bookings/CreatePackageBooking`, `Travel/Payments/SettlePayment`, …). Livewire pages and API controllers call these, so the web app and API share behaviour.
- `app/Livewire` (75 pages): the whole web UI.
- `app/Http/Controllers` (44): API v1, the webhook receiver, the referral redirect, downloads and exports.
- `app/Support` (44): read-side helpers: `SalesMetrics`, `HubOverview`, `Travel/TravelSalesMetrics`, `Travel/TravelReport`, `Travel/TravelDashboardData`, filters, access rules (`Travel/TravelAccess`, `Travel/InfluencerAccess`, `Travel/PaymentAccess`), `Audit`.
- `app/Incentives` (8): pay calculation (`Policy`, `Calculator`, `AccountPoints`, `MonthlyEarnings`, `Statements`, `Claims`).
- `app/Integrations`: `Tourlast/*` (Stays and Experiences provider sources and record mapper), `Flights/*`, `Mpesa/*`.
- `app/Console/Commands` (20) scheduled from `routes/console.php`.
- `app/Models` (64), `app/Enums` (45), `app/Policies` (2: `UserPolicy`, `PropertyEngagementPolicy`).
- `app/Events` (3) and `app/Listeners` (2): `FlightBookingSynced` and `PackageBookingChanged` drive influencer commission lines.
- Empty directories: `app/Jobs`, `app/Services`, `app/Observers` contain no files. [V]

### 2.3 Queues and schedule [V]

- Queued classes: `Mail/BookingTicketMail`, `Mail/InvitationMail`, `Mail/ManagerDailyAlert`, `Notifications/SmartAlert`. Nothing else is queued; there are no jobs.
- Webhooks are processed **synchronously** inside the HTTP request (`TourlastWebhookController`).
- Schedule (`routes/console.php`): `hub:sync-tourlast` every N minutes (default 10) and `--full` nightly at 02:00; `hub:generate-statements` monthly on the 1st; `hub:reinstate-suspensions` 06:00; `hub:process-reengagements` 06:45; `hub:send-daily-alerts` 07:00; `hub:send-meeting-reminders` every 5 minutes; `hub:send-manager-alerts` weekdays 07:30; `travel:sync-flights` every 10 minutes and nightly full; `travel:mpesa-reconcile` every 5 minutes; `travel:refunds-flag-stuck` every 10 minutes; `travel:expire-holds` hourly; `travel:contract-alerts` 07:10; `travel:pretrip-reminders` 08:00.

### 2.4 Modules and how they talk

```
Salesperson ──▶ /r/{code}[/experiences]  ReferralRedirectController
                    │ logs ReferralClick, redirects with ?ref=CODE
                    ▼
        Stays / Experiences signup (separate apps)
                    │ provider record carries ref_code
   ┌────────────────┼──────────────────────────────┐
   │ pull (10 min + nightly)  │ push (signed webhook, instant)
   ▼                          ▼
 ApiProviderSource ──▶ ProviderRecordMapper ──▶ ApplyProviderRecord (single writer)
                                                    │
                      Onboarding ◀─ credit rules ───┤──▶ PartnerAccount / PointEntry (AccountPoints)
                                                    │──▶ Lead link, Registry sync, Smart Alerts

Flights app ──(pull or push)──▶ ApplyFlightRecord ──▶ FlightBooking ──▶ FlightBookingSynced
Travel staff ──▶ CreatePackageBooking ──▶ PackageBooking ──▶ PackageBookingChanged
                                  both ──▶ SyncInfluencerCommission ──▶ InfluencerCommission
M-Pesa Daraja callbacks ──▶ ReceiveDarajaCallback ──▶ TravelPayment / TravelRefund
```

Both feed paths (pull and webhook) end in the same action, `ApplyProviderRecord`, so behaviour is identical. [V]

---

## 3. Business functionality (traced)

### 3.1 Marketer (salesperson) management [V]

- **Registration:** invitation-only (`SendInvitation`, `AcceptInvitation`; link valid 7 days). First Super Admin via `hub:create-super-admin`.
- **Roles that earn referrals:** `Role::earnsReferrals()` is true for `sales-admin`, `sales-manager`, `salesperson` only. `IssueReferralCode` returns `null` for every other role.
- **Code per user:** `IssueReferralCode` creates one code and returns the existing active code if there is one. The schema allows several (`User::referralCodes()` is `hasMany`), but `User::referralCode()` is `hasOne(...)->where('is_active', true)->latestOfMany()`, so the system treats one active code as the norm. Multiple codes per user are possible in the data model but **not produced by any code path I found**. [V]
- **Account status:** active, suspended (optional end date, auto-reinstated at 06:00), fired. A fired owner's code stops crediting and stops counting clicks (`ReferralCode::ownerIsFired`, `ReferralRedirectController`). [V]
- **Monitoring:** `Livewire/Team/Performance`, `Livewire/Dashboard` (also served at `/team/performance/{user}`), `Support/SalesMetrics`, activity recency (`inactive_after_days`, default 3). [V]

### 3.2 Referral management [V]

- **Generation:** `app/Actions/IssueReferralCode.php`: `{prefix}-{FIRSTNAME}-{4 digits}`, name letters only, **at most 10 letters**, 4 digits 1000–9999, regenerated until unique. Longest possible code with prefix `TL` is 18 characters.
- **Validation of a click:** unknown code or fired owner → redirected to the product signup **without** `ref`, no click logged. Bot-like user agents are not counted (`looksLikeBot`).
- **Scope:** one global code, product chosen by link suffix: `/r/CODE` → Stays, `/r/CODE/experiences` → Experiences (`Enums/ReferralTarget`). Codes are not product-specific; the *link* is.
- **Expiry:** salesperson codes have **no expiry or usage limit** [V]. Influencer codes do (`starts_on`, `ends_on`, `max_bookings`).
- **Attribution:** `ApplyProviderRecord` credits the code's owner when the provider record carries `ref_code`. Rules in its docblock: the Hub's own code wins; an admin's manual assignment (`attribution = 'manual'`) is never overwritten; a code the Hub does not know, or whose owner is fired, credits nothing new but never removes credit already given; a property with no credit yet and an unknown code stays unattributed.
- **Override:** yes. `AssignOnboarding` assigns an unattributed onboarding by hand, writes an `attribution_changes` row (from, to, who, reason) and marks it manual. Only admins (API scope `onboardings:write`).
- **Duplicates:** one onboarding per `tourlast_property_id` (find-or-create under a row lock); out-of-order updates are ignored by comparing `source_updated_at`; deletions archive instead of delete and a later live row restores. Duplicate properties and prospects are detected by name, phone and website keys (`Support/MatchKeys`, used by `Support/PropertyDuplicateCheck`, `Support/DuplicateEngagementFinder` and `Support/Travel/ProviderDuplicateFinder`); the API exposes a duplicate check (`Api/V1/DuplicateCheckController`, not read in detail **[U]**). [V]

### 3.3 Product integrations [V unless stated]

| Product | Mechanism | Data exchanged | Failure behaviour |
|---|---|---|---|
| **Stays** (tourlast.com, `hotels`/`apartments`) | Pull: `GET /api/sales-hub/referrals` via `Integrations/Tourlast/ApiProviderSource`, shared bearer token. Push: Stays posts a signed (`X-Tourlast-Signature` HMAC-SHA256) webhook to `/webhooks/tourlast`. | Provider record: property id, account id, names, type, location, contact, status, timestamps, `ref_code`, `inventory_count`, `first_booking_at`, `is_deleted`. **No booking or revenue amounts.** | Each run logged in `sync_runs`; a failed app is reported (`ReportsSourceFailures`); webhook duplicates are ignored for 7 days by event id. |
| **Experiences** | Same contract, path `/webhooks/experiences`. | Same shape. | Same. |
| **Flights** | Pull `Integrations/Flights/ApiFlightSource` or push (`PushFlightSource`), applied by `ApplyFlightRecord`. | One flight booking per record: PNR, route, passengers, fares, markup, status, refund fields, `agent_reference` (email), `promo_code`. | `flight_sync_runs`; sync error stored on the booking (`sync_status`, `sync_error`). |
| **M-Pesa Daraja** | STK push, paybill, B2C refunds; callbacks `ReceiveDarajaCallback`. | Payments and refunds for package bookings only. | Reconcile every 5 minutes; stuck refunds flagged every 10 minutes. |
| **Registry / Leads** | Internal. | Link provider records to leads (email or phone match) and registry entries. | n/a |

- `TOURLAST_SOURCE` selects `api`, `push` or `sandbox` (`AppServiceProvider`). `database` is rejected.
- **How the referral code reaches the Hub:** the code travels in the signup URL as `?ref=`, is stored by the source app on the provider, and returns in the provider record. The Hub never sees the signup itself, only the record afterwards. [V for Hub side; Stays side traced in this session]
- **How attribution is confirmed:** only by the `ref_code` in the record matching a `referral_codes` row. There is no second confirmation.

### 3.4 Bookings and revenue [V]

- **Stays and Experiences bookings: not recorded.** The Hub keeps `first_booking_at` and a live `inventory_count` per property only. First booking triggers a "First booking received" alert.
- **Package bookings (Travel):** created inside the Hub by travel staff (`CreatePackageBooking`), with status, payment status, amounts, hold expiry, cancellation and refund (`PackageCancellation`, `TravelRefund`, `RequestCancellation`, `DecideCancellation`, `ManageRefund`). Revenue is `amount_total` in **KES**.
- **Flight bookings (Travel):** read-only copies from the Flights app. Revenue is `total_amount` in **USD** (`TravelReport` labels). Salesperson is resolved by matching `agent_reference` to a Hub user email (`ApplyFlightRecord::salespersonFor`).
- **Payments:** `TravelPayment`, `AllocatePayment`, `SettlePayment`, M-Pesa and manual payments.
- **Commission:**
  1. *Property side:* Schedule 1 **points**, not commission. `Incentives/Policy::schedule1()` holds stay bands (1–10 rooms = 1 point … 201+ = 9), experience bands, retainer, weekly and monthly bonuses, an exceptional-performance rate.
  2. *Travel influencers:* `InfluencerCode::commissionOn()`. One `InfluencerCommission` line per code and booking, Pending until fully paid, then Payable, cancelled on cancel/refund, partially refunded earns on what the client kept, window by `starts_on`/`ends_on`, capped by `max_bookings` (`SyncInfluencerCommission`).
  3. *Provider contracts* have a commission model (percentage, fixed, net rate) describing what Tourlast earns (`Enums/Travel/CommissionModel`). That is Tourlast's margin, not a salesperson payment.
- Commission rules vary by influencer code, not by product, apart from the code's `applies_to` (packages, flights, all).

### 3.5 Reporting and analytics (what exists) [V]

| Surface | Metrics and method | Code |
|---|---|---|
| **My Progress / Dashboard** | Onboarded (credited in period), active, approved-not-live, awaiting, stalled (older than 14 days), submitted, clicks, **conversion = onboarded ÷ clicks ×100**, activities, onboarded by property type, previous-period comparison, monthly trend, points vs monthly target | `Support/SalesMetrics::forUser`, `monthlyTrend`, `Livewire/Dashboard` |
| **Team Performance** | One row per active seller, status (Inactive N days / No target / Target hit / On track at ≥85% of pro-rata target / Behind) | `SalesMetrics::team`, `status`, `Livewire/Team/Performance` |
| **Home overview (management)** | Live partners, this month vs last month, 6-month trend, funnel by status, top salespeople, claims and statements money, travel this month | `Support/HubOverview` |
| **Travel Reports** | Flight bookings, revenue (USD), markup, passengers, cancellations, refunds requested and value, routes, airlines, package performance, destinations, provider performance | `Support/Travel/TravelReport`, `TravelSalesMetrics`, `Livewire/Travel/Reports` |
| **Insights** | Lost reasons and objections (team and personal) | `Livewire/Insights/*`, `Enums/Objection` |
| **Earnings** | Points → pay under Schedule 1, provisional vs approved, next step | `Incentives/Calculator`, `MonthlyEarnings` |
| **Exports** | Partner Register and Registry in Excel and PDF; influencer, travel report and trip review exports | `PartnerRegisterExportController`, `EngagementRegistryExportController`, `Travel/*ExportController` |
| **API** | `reports:read` scope: objections, my losses, partner register, exports | `Api/V1/ReportController` |

Targets are in **points** (`Target`, global scope `metric = 'points'`) and in travel metrics (`TravelTarget`).

---

## 4. Security and permissions (existing) [V]

- **Authorization is in code, not in the model layer.** Pages check `Permission` in `mount()` (for example `Team\Performance` requires `view-team-performance`; `Dashboard` requires that permission or the viewer being the subject). Gates: `view-earnings`, `view-account`, `view-claim` in `AppServiceProvider`. Only two policy classes exist. Travel access is centralised in `Support/Travel/TravelAccess` and friends.
- **Scoping is by query, not by global scope.** The only global scopes are on `Target` and `TravelTarget` (metric filters, not security). A new query on `Onboarding`, `Lead`, `FlightBooking` or `PackageBooking` is **not** automatically restricted to the current user. Any AI tool must apply the scope itself.
- **Salespeople see only their own** progress and leads; managers see the team; HR and Accounts see the partner register and pay; Travel Salespeople see no property data; Sales Managers see no travel staff. (Role matrix in `docs/SYSTEM_GUIDE.md` §2 matches `Role::permissions()`.)
- **API:** a request needs the token scope **and** the owner's permission (`Enums/ApiScope`, `Api/V1/ApiController`). Integration feeds use a single shared bearer token.
- **Sensitive data:** payout details (`Influencer.payout_details`), M-Pesa numbers and account numbers (`PaymentDetail`), guest ID numbers (`PackageBookingGuest.id_number`) use `encrypted` casts. Flight bookings store customer name, email, phone and the raw `payload` in the clear. Leads and onboardings hold contact details and KRA PINs in the clear. [V]
- **Audit:** `Support/Audit::record()` writes `audit_events` (action, subject, summary, field diffs, IP). 55 rows locally. Attribution changes also have their own table.
- **Headers/session:** `SecurityHeaders` and `TrackPresence` middleware; `EnsureUserIsActive` signs out suspended or fired users.
- **Observed risk (integration):** the shared integration token is a single secret for all source apps. Locally, sync run #4 recorded `HTTP 401` from Stays because the two sides held different tokens. [V in `sync_runs`] This is a deployment-consistency risk, and it silently starves the Hub of data. See 5.3.

---

## 5. Data architecture and availability

### 5.1 Reading the numbers: local snapshot only [L]

All counts below come from the **local development database**. It is not production. It has 0 referral clicks, 0 point entries, 0 leads, 0 activities, 0 influencer commissions, 0 claims in flow. **Production volumes are unverified [U].** Two onboardings in the local table (`hotel-940`, `hotel-941`) were created by this team during an unrelated referral test on 30 October and should be ignored when judging attribution.

| Table | Rows [L] | Notes |
|---|---:|---|
| `onboardings` | 216 | 207 stay, 9 experience; submitted 13 Jun – 30 Oct 2026 |
| `onboarding_status_changes` | 216 | status history |
| `partner_accounts` | 80 | 78 have no owner |
| `referral_codes` | 5 | all sellers have one |
| `referral_clicks` | 0 | feature unused locally |
| `point_entries` | 0 | no points ledger locally |
| `leads`, `activities`, `follow_ups` | 0 | no sales activity locally |
| `targets` | 0 | |
| `flight_bookings` | 13 | 19 Aug – 28 Oct 2026; none cancelled; none with a salesperson or influencer code |
| `package_bookings` | 1 | confirmed and paid, has a salesperson |
| `influencer_codes` / `influencer_commissions` | 4 / 0 | |
| `property_engagements` | 2 | |
| `audit_events` | 55 | |
| `sync_runs` | 12 | 3 succeeded, 5 failed, 4 still marked running |

### 5.2 Key tables and columns for analysis [V]

- **`onboardings`**: `tourlast_property_id` (unique source id), `ref_code`, `referral_code_id`, `user_id`, `attribution` (`referral` or `manual`), `partner_account_id`, `category` (stay or experience), `property_type`, `inventory_count`, `status`, and the timeline `submitted_at`, `approved_at`, `active_at`, `inactive_at`, `rejected_at`, `first_booking_at`, `credited_at`, plus `source_payload` (raw JSON). Soft-deleted.
- **`referral_codes`**: `user_id`, `code`, `is_active`. **`referral_clicks`**: `referral_code_id`, `lead_id`, `target` (stays or experiences), `ip_hash`, `user_agent`, `clicked_at`.
- **`point_entries`**: `user_id`, `partner_account_id`, `type`, `points`, `earned_on`, `month`, `bonus_week`, `status`. **`partner_accounts`**: legal business grouping, activation date and inventory, qualification and review status.
- **`flight_bookings`**: route, airline, `currency`, `fare_amount`, `total_amount`, `markup_amount`, statuses, `cancelled_at`, `refund_status`, `refund_amount`, `booked_at`, `salesperson_id`, `influencer_code_id`.
- **`package_bookings`**: `salesperson_id`, `influencer_code_id`, `currency`, `amount_total`, `amount_paid`, `amount_refunded`, `status`, `payment_status`, `travelers`, timestamps. **`influencer_commissions`**: booking amount, commission amount, status, earned and paid dates.
- **`property_engagements`**: pipeline `stage`, `status`, `source` (cold call, WhatsApp, referral, trade show, campaign, …), `objection`, `competitor`, `outcome_notes`.
- **`leads`**: prospect, `status`, `lost_reason`, `objection`, `competitor`, `onboarding_id` link. There is **no source or channel column** on leads.

### 5.3 Quality and consistency findings

1. **Most local onboardings are unattributed [L].** 214 of 216 have no `user_id`; only 3 carry a `ref_code`. This mirrors the live complaint. The cause is upstream (the source app does not send a code for older properties, or sends a truncated one). It is analysed in the Stays work from this session: Stays' signup tables hold the code in a `varchar(12)` column while Hub codes reach 18 characters, so longer codes are cut or rejected, and a Stays-to-Hub token mismatch stops Stays validating codes. **Any AI analysis of "who brings in the most providers" is only as good as this attribution.** [V in code, U for production]
2. **Sync reliability [L].** `sync_runs` has 3 rows stuck at `running` (ids 9, 10, 12, started 15 and 23 October, never finished) and 5 failures (HTTP 500 from Experiences, HTTP 401 from Stays). Last success 16 October. Stale data would mislead any AI summary unless freshness is shown.
3. **Currencies are mixed.** Flights are USD, packages are KES. They cannot be added; reports show them side by side. Any AI summary must keep them separate.
4. **No revenue for Stays or Experiences** (see section 0).
5. **Clicks are not linked to signups.** A click row has no signup id; conversion is an aggregate ratio `onboarded ÷ clicks`, and a signup can occur without a counted click (bot filter, code typed by hand). The ratio is directional only.
6. **Flight attribution is thin.** Salesperson comes from an email match on `agent_reference`; locally 0 of 13 flights matched.
7. **Soft deletes everywhere** (`onboardings`, `leads`, `property_engagements`): any new query must decide whether trashed rows count.
8. **Unlinked code case.** One local onboarding has a `ref_code` but no `referral_code_id`, meaning the code is not known to the Hub. By design it stays unattributed.

### 5.4 Can the brief's questions be answered?

| # | Question | Answerable today? | Reason and source |
|---|---|---|---|
| 1 | Marketers generating the most revenue | **No** for Stays/Experiences. **Partly** for Travel (by `salesperson_id`, per currency) | No Stays/Experiences revenue in the Hub. Travel salespeople are staff selling, not referral marketers. |
| 2 | Products converting referrals best | **Partly** | `ReferralClick.target` vs `Onboarding.category` gives click→signup by product, aggregate only, not linked per click. No booking conversion. |
| 3 | Marketers with referrals but few completed bookings | **No** for bookings. **Yes** for "signed up but not live / stalled / rejected" | Bookings absent; `OnboardingStatus` timeline exists (`SalesMetrics` awaiting and stalled). `first_booking_at` is a yes/no proxy. |
| 4 | Average booking value per marketer | **No** (property); **Travel only** | Needs referral→booking link, which does not exist. Travel average is possible per `salesperson_id` and currency. |
| 5 | Marketer performance over time | **Yes** (counts and points) | `SalesMetrics::monthlyTrend`, `point_entries.month`, `targets`. Needs real history; local has none. |
| 6 | Products with declining conversions | **Partly** | Needs click history by `target` over time; click table is new and empty locally. |
| 7 | Cancellation and refund rates per marketer | **Travel only** | `flight_bookings` refund and cancel fields, `package_cancellations`, `amount_refunded`, by `salesperson_id`. Not available for referral marketers. |
| 8 | Best referral channels | **No** | Only product (`target`) is captured. No UTM, medium or channel. Registry `source` describes how a salesperson first engaged a property, not how a referral arrived. |

---

## 6. AI opportunity assessment

The Laravel AI SDK evaluation is in section 7. Each capability below is rated against the real data in section 5.

Scale: value / feasibility / effort / cost are Low–Med–High. Costs are **order-of-magnitude judgements [U]**; I did not price providers.

| Capability | Business value | Data and tech required | SDK appropriate? | Complexity | Operating cost | Risks and limits |
|---|---|---|---|---|---|---|
| **D. AI Reporting** (weekly or monthly management summary) | **High**: managers already read `HubOverview`; turns numbers into narrative | Existing aggregates from `HubOverview`, `SalesMetrics::team`, `TravelReport`. Read-only. One prompt, structured output, scheduled | Yes, but only for `prompt()` plus structured output; no tool-calling needed | **Low** | **Low**: one call per report | Model might misstate a number; mitigate by passing numbers in and showing them beside the text. Stale or unattributed data must be flagged in the input |
| **C. Marketer Assistant** (own performance, next-step hints) | **High**: salespeople see progress, conversion, stalled partners, pay steps (`Calculator::nextStep`) | Per-user metrics from `SalesMetrics::forUser`; strictly the signed-in user | Yes; one agent with a single read-only tool or just a prompt with the user's metrics | Low–Med | Low–Med (many users, short prompts) | Must never see another seller's data; keep context to one user; no pay advice beyond what `Calculator` returns |
| **B. Performance Insights** (anomalies, trends) | Med–High | Time series from monthly counts and points; thresholds computed deterministically first | Partly: detect with code (z-score or fixed rules), use AI only to explain | Med | Low | Thin history in many cases; false alarms; do not let the model decide what is "unusual" |
| **A. Marketing Analyst** (natural-language Q&A) | Med now, High later | Needs fixed read-only tools over existing metric classes; permission-aware | Yes: this is where tools, conversation memory and approval fit | **High** | Med–High (multi-step tool loops, long histories) | Only answers questions section 5.4 says are answerable; revenue questions would be wrong or invented. Highest leak risk |
| **E. Forecasting** | Med | Needs long, clean history that does not yet exist locally; production unverified | **No**: use statistics in code. AI may only describe the result | Med–High | Low | Small samples, mixed currencies, unattributed data; an LLM forecast would be unreliable |
| **F. Campaign Assistant** | Low–Med | No data needed from the Hub | SDK works, but adds little over a general chat tool | Low | Low | Off the Hub's core; brand and compliance review; no measurable tie to attribution |

**Ranking (value, feasibility, effort combined):**
1. D. AI Reporting
2. C. Marketer Assistant
3. B. Performance Insights (explanations over deterministic detection)
4. A. Marketing Analyst (after 1–3 prove the metric tools and permission model)
5. F. Campaign Assistant
6. E. Forecasting (defer; do statistically if wanted)

---

## 7. Laravel AI SDK technical evaluation

**Sources:** `https://laravel.com/docs/13.x/ai-sdk` (read), `composer show laravel/ai --all` (metadata only).

| Topic | Finding |
|---|---|
| **Compatibility [V]** | `laravel/ai` requires `php ^8.3` and `illuminate/* ^12.0\|^13.0`. The Hub runs Laravel 13.33 and PHP `^8.3`, so it is compatible. Nothing was installed. |
| **Install footprint** | `composer require laravel/ai`, publish config and migrations, migrate. Creates `agent_conversations` and `agent_conversation_messages`. Code lives in `app/Ai/Agents`, `app/Ai/Tools`, `app/Ai/Middleware`. This is a dependency change and needs approval (project rule). |
| **Providers** | Text: OpenAI, OpenAI-compatible, Anthropic, Gemini, Azure, Bedrock, Groq, xAI, DeepSeek, Mistral, Ollama, OpenRouter, Cohere. `Lab` enum selects the provider. |
| **Agents** | Class-based `Agent` with instructions; `make:agent`. Anonymous agents also exist. |
| **Tools** | `make:tool`, a `Tool` class with `description`, `schema`, `handle`. Tools can be loaded on demand, run sub-agents, or be exposed through MCP. |
| **Structured output** | `HasStructuredOutput` with a JSON schema; suits summaries and classification. |
| **Conversation persistence** | `RemembersConversations` trait stores history in the two tables; or implement `Conversational` and return messages yourself. |
| **Human approval** | `Approvable` contract + `InteractsWithApprovals`: a tool pauses until a person approves. Relevant for any future write action. |
| **Streaming and broadcasting** | Streaming responses and broadcasting supported. The Hub already uses Echo/Pusher. |
| **Queues** | `->queue($prompt)->then()->catch()`. The Hub has a database queue and a worker is expected. |
| **Middleware** | Per-step agent middleware (e.g. logging prompts) via `make:agent-middleware`; the place to enforce audit logging and per-user limits. |
| **Cost and usage** | Each response exposes `usage` (input, output, cache read/write, reasoning tokens); cached and uncached tokens are billed differently. Events such as `AgentPrompted`, `InvokingTool`, `AgentFailed`, `ProviderFailedOver` exist for metering. **No built-in budget cap or per-user rate limit is described**; the Hub would implement these. |
| **Failover and provider switching** | `provider: [Lab::OpenAI, Lab::Anthropic]` fails over on rate limit, overload or insufficient credits. |
| **Other capabilities** | Embeddings, reranking, classification, vector stores, files, images, audio. Not needed for the first features. |
| **Testing** | Fakes for agents and other capabilities, so features can be tested without live calls. |

**Not every capability needs an agent.** Reporting and the marketer summary are single prompts with structured output. Only the analyst benefits from tools and conversation memory.

### Parts of the Hub that would be touched

- New: `app/Ai/*` (agents, tools, middleware), a small usage log table, config, tests.
- Read: `Support/SalesMetrics`, `Support/HubOverview`, `Support/Travel/TravelReport`, `Support/Travel/TravelSalesMetrics`, `Incentives/Calculator`, `Support/Period`.
- Authorization: `Enums/Permission`, `Enums/Role`, `Support/Travel/TravelAccess`, the `view-earnings` gate.
- Logging: `Support/Audit` and `audit_events`.
- Scheduling and delivery: `routes/console.php`, `Notifications/SmartAlert`, `Mail/ManagerDailyAlert`.
- UI: a Livewire component on the dashboard or a reports page.

---

## 8. Proposed architecture (extend, do not duplicate)

**Principle:** the model never queries data. Deterministic code computes every number; the model only explains and answers around numbers it is handed.

1. **Metric layer (exists).** `SalesMetrics`, `HubOverview`, `TravelReport`, `TravelSalesMetrics`, `Calculator` stay the single source of numbers. Do not recompute them in prompts or tools.
2. **Thin AI facade (new, small).** One class per feature that (a) resolves the signed-in user, (b) asks the metric layer for the figures that user may see, (c) calls the agent with those figures as data, (d) stores the result. Example inputs: `SalesMetrics::forUser($user, $period)` for the assistant; `HubOverview::onboarding()` for management reports.
3. **Read-only tools (only for the analyst phase).** Each tool wraps one existing metric method with a fixed parameter schema (period, optional salesperson id), re-checks permission inside `handle()` using the same `Permission` and `TravelAccess` calls as the pages, and returns small JSON. **No raw SQL, no model access, no write tool.**
4. **Permission-aware access.** Build the tool set per request from the user's permissions, so a salesperson's agent has no team tool at all. The tool never accepts a user id from the model for sellers; it uses `Auth::user()`.
5. **Conversation management.** Per-user threads (`RemembersConversations`), conversations never shared across users, short retention, and context built only from that user's authorised results.
6. **Provider abstraction.** Use the SDK's provider setting and failover list; keep model names in config.
7. **Usage and cost.** Middleware or event listeners write one row per call: user, feature, provider, model, token counts (including cached), duration, result status. Add a per-user daily limit and a global monthly budget check before calling.
8. **Audit.** Use `Audit::record()` for each AI request and report generation, with the figures supplied (not only the text), so any statement can be traced.
9. **Background work.** Reports run from the scheduler and the queue worker (`->queue()` or a queued command); interactive questions stream.
10. **Presentation.** Show the underlying numbers beside the generated text, label the text "AI-generated", and show data freshness (last successful sync) so stale or failed syncs are visible.

**Hard boundaries (enforced in code, not in the prompt):** no tool that writes attribution, points, commissions, bookings, payments, claims or statements; no database access beyond the fixed metric methods; no cross-user data in one prompt.

---

## 9. Feature inventory

| Capability | Status | Evidence |
|---|---|---|
| Invitation-only accounts, roles, permissions | Implemented | `SendInvitation`, `Role`, `Permission` |
| Salesperson referral codes and tracked links | Implemented | `IssueReferralCode`, `ReferralRedirectController` |
| Click tracking by product | Implemented (bot filtering is heuristic) | `ReferralClick`, `looksLikeBot` |
| Provider sync (pull, push, nightly full) | Implemented; reliability issues locally | `ApplyProviderRecord`, `sync_runs` |
| Attribution by code, manual override, history | Implemented | `AssignOnboarding`, `attribution_changes` |
| Leads, activities, follow-ups, calendar | Implemented | `Livewire/Leads`, `Activities`, `Calendar` |
| Property Engagement Registry | Implemented | `Livewire/Registry`, `PropertyEngagement` |
| Targets, team performance, home overview | Implemented | `SalesMetrics`, `HubOverview` |
| Schedule 1 points and pay, statements, claims | Implemented | `app/Incentives` |
| Travel providers, contracts, packages, approvals | Implemented | `app/Actions/Travel/*` |
| Package bookings, payments, M-Pesa, refunds | Implemented (M-Pesa refund payout depends on credentials: **[U]**) | `CreatePackageBooking`, `Mpesa/*` |
| Flight bookings (read-only) | Implemented | `ApplyFlightRecord` |
| Influencer codes and commissions | Implemented | `SyncInfluencerCommission` |
| REST API v1 with scoped tokens | Implemented | `routes/api/v1`, `ApiScope` |
| Audit log | Implemented | `Audit`, `audit_events` |
| Stays/Experiences booking and revenue data | **Missing** | none in schema |
| Referral channel (UTM, medium) | **Missing** | `referral_clicks` has only `target` |
| Click→signup link | **Missing** | no signup id on click |
| Revenue commission for salespeople | **Missing by design** | pay is points-based |
| Any AI feature | **Missing** | grep |
| Database provider source | Dead code | `DatabaseProviderSource` only referenced by `ProviderSourcesTest`; `config/tourlast.php` says "not wired up" |

**Dead, duplicated or incomplete [V]:** `Integrations/Tourlast/DatabaseProviderSource` and the `tourlast.database` config block; empty `app/Jobs`, `app/Services`, `app/Observers`; two parallel "onboarded" counters (`SalesMetrics::forUser` and `HubOverview::onboarding`) that both use `creditedBetween`/`onboarded` scopes, so they agree but live in two places; the `Dashboard` component serves two routes.

---

## 10. Findings and recommendations

### A. Executive summary
See section 1 and the correction in section 0.

### B. Existing system architecture
See section 2.

### C. Feature inventory
See section 9.

### D. Data readiness for AI

| Area | Readiness |
|---|---|
| Onboarding counts, statuses, timelines, points, targets | **Ready**, subject to attribution quality and production history |
| Click and conversion by product | **Partly ready**; aggregate only; history is short |
| Travel revenue, cancellations, refunds, commissions | **Ready for Travel**; USD and KES must stay separate; salesperson match on flights is weak |
| Stays and Experiences bookings and revenue | **Not available** |
| Referral channels | **Not available** |
| Data freshness | **At risk**: stuck and failing syncs; show last-success time |
| Attribution completeness | **At risk**: most local onboardings unattributed; code-length and token issues upstream |

### E. AI opportunity matrix

| Feature | Value | Feasibility | Effort | Cost | Priority |
|---|---|---|---|---|---|
| D. AI Reporting | High | High | Low | Low | **1** |
| C. Marketer Assistant | High | High | Low–Med | Low–Med | **2** |
| B. Performance Insights | Med–High | Med | Med | Low | **3** |
| A. Marketing Analyst | Med→High | Med | High | Med–High | **4** |
| F. Campaign Assistant | Low–Med | High | Low | Low | **5** |
| E. Forecasting | Med | Low now | Med–High | Low | **6 (defer)** |

### F. Recommended AI architecture
See section 8.

### G. Implementation roadmap

**Phase 0: prerequisites (no AI).** Fix attribution at the source (Stays column widths and token), clear the stuck `sync_runs`, and show "last successful sync" on the dashboard. Without this, every AI output inherits wrong or stale data. Decide production data access for measuring real volumes.

**Phase 1: AI Reporting.** Scheduled management summary from `HubOverview`, `SalesMetrics::team` and `TravelReport`; structured output; numbers shown beside text; usage log and audit entry. No tools, no conversation.

**Phase 2: Marketer Assistant.** Per-seller summary and "what moves me up a pay step" using `SalesMetrics::forUser` and `Calculator::nextStep`; single-user context; per-user limits.

**Phase 3: Insights.** Deterministic anomaly rules first, AI explanation second.

**Phase 4: Analyst.** Fixed read-only tools wrapping the metric classes, permission-built tool sets, conversation memory, cost caps. Only after Phases 1–3 prove the permission and logging model, and only for the questions in 5.4 marked answerable.

**Later / only if justified:** campaign help; forecasting by statistics.

### H. Risks and unresolved questions
See the final section.

---

## 11. Audit coverage and limits

**Verified by reading code or querying data:** stack and versions, routes, roles and permissions, referral generation and redirect, attribution and override, sync paths, booking and commission logic, metrics classes, API scopes, access rules, encrypted fields, table columns, local row counts and aggregates, AI SDK documentation and compatibility.

**Not verified [U]:**
- Production data volumes, history length and attribution rate.
- Whether production MySQL runs in strict mode.
- Provider pricing, token volumes and actual AI operating cost.
- Live behaviour of M-Pesa, the Flights app and the Experiences app (not run).
- Every Livewire page's authorization line by line; the Dashboard, Performance, Earnings and API report paths were read, the remaining pages were checked by pattern only.
- Whether `docs/SYSTEM_GUIDE.md` (dated 7 October 2026) is fully current; it matched the code where compared.

---

# Closing sections

## 1. What we should build with AI
- **A scheduled management summary** built from numbers the Hub already computes (Reporting).
- **A personal performance explainer for each salesperson**, limited to their own data.
- **Explanations of deterministic anomalies** (the code decides what is unusual; the model explains it).
- Later, **a read-only analyst** that calls fixed metric tools, limited to questions the data can answer.

## 2. What we should NOT build with AI
- Anything that **writes**: attribution, points, pay, commissions, bookings, payments, refunds, claims, statements.
- **Free-form database access** or text-to-SQL.
- **Revenue, booking-value or channel analytics for Stays and Experiences**, because that data is not in the Hub. Building them would produce invented answers.
- **LLM-generated forecasts.**
- **A campaign-content assistant as a first feature**: it needs none of the Hub's data and adds risk without a measurable return.
- Any cross-user prompt that mixes sellers' data.

## 3. Is Laravel AI SDK necessary?
**Not for the first two features.** Reporting and the marketer summary are a single structured prompt each, which any HTTP client or the SDK can do. The SDK becomes worthwhile for the analyst phase: agents, tool calling, conversation storage, approval, usage events and provider failover are exactly what it provides and would otherwise be rebuilt by hand. It is compatible (`php ^8.3`, `illuminate ^12|^13`). Recommendation: adopt it when Phase 1 is approved, because Phases 2–4 will use it and the usage events and failover help with cost control from the start. This is a judgement, not a requirement. Installing it needs approval under the project rules.

## 4. The recommended first AI feature
**AI Reporting: a scheduled management summary** (weekly or monthly) of provider onboarding, team performance and travel sales, generated from existing aggregates, shown with the source numbers and a data-freshness note, logged to the audit table.

## 5. Existing files and services that would be involved
- Numbers: `app/Support/HubOverview.php`, `app/Support/SalesMetrics.php`, `app/Support/Period.php`, `app/Support/Travel/TravelReport.php`, `app/Support/Travel/TravelSalesMetrics.php`, `app/Incentives/Calculator.php` (for the later assistant).
- Authorization: `app/Enums/Permission.php`, `app/Enums/Role.php`, `app/Support/Travel/TravelAccess.php`, the `view-earnings` gate in `app/Providers/AppServiceProvider.php`.
- Delivery and logging: `routes/console.php`, `app/Notifications/SmartAlert.php`, `app/Mail/ManagerDailyAlert.php`, `app/Support/Audit.php`, `app/Models/AuditEvent.php`.
- Freshness input: `app/Models/SyncRun.php`, `app/Models/FlightSyncRun.php`, `app/Support/Travel/FlightSyncStatus.php`.
- UI: a new Livewire page or a panel on `app/Livewire/Dashboard.php`.
- New (to be created later): `app/Ai/Agents`, `app/Ai/Tools`, `app/Ai/Middleware`, a usage-log table, config.

## 6. Outstanding questions requiring management decisions
1. **Is the brief's goal (attribute bookings and revenue to marketers) wanted?** If yes, that is a separate product change: Stays and Experiences would have to send booking and revenue data and the referral code on each booking. AI cannot supply it.
2. **Production access:** may the audit be repeated against production figures (volumes, history, attribution rate) before any budget is set?
3. **Attribution repair:** approve fixing the source-app column widths and token mismatch, and the repair of already-truncated codes, before reporting on "who recruits the most".
4. **Which provider and data-handling position?** Reports include salesperson names and pay-adjacent figures. Is sending these to an external AI provider acceptable, or must a provider with a no-retention or regional agreement be used?
5. **Who may read AI reports** (managers only, or salespeople about themselves) and who is accountable for their accuracy?
6. **Budget:** a monthly spending cap and per-user limits.
7. **Currency:** how should USD flights and KES tours be presented together, or should they stay separate?
8. **Retention:** how long may AI conversations and generated reports be kept?
9. **Channel tracking:** is capturing a referral channel (UTM or medium on `?ref=` links) wanted, so the "best channel" question can ever be answered?
10. **Stuck sync runs:** who owns clearing the three runs left at `running` and monitoring sync health?
