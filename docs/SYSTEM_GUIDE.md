# Tourlast Sales Hub — System Guide

**Address:** `https://sales.tourlast.com` · **Last updated:** 26 September 2026

This is the complete reference for the Tourlast Sales Hub: what it does, how each part works, what is still to be connected, what must be configured before go-live, and what is pending. It is written for Tourlast management, the tourlast.com development team, and any developer who takes over the Hub.

| If you are… | Read |
|---|---|
| Management or a new team lead | Sections 1–5 |
| The tourlast.com developer | Section 7, then [TOURLAST_INTEGRATION.md](TOURLAST_INTEGRATION.md) |
| Anyone building on the API (apps, reporting, other systems) | Section 7.4, then [API.md](API.md) |
| The person deploying the Hub | Sections 8–9, then [DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md) |
| A developer maintaining the Hub | Sections 6, 10 and 11 |

---

## Contents

1. [What the Sales Hub is](#1-what-the-sales-hub-is)
2. [Roles and who can do what](#2-roles-and-who-can-do-what)
3. [How it works, module by module](#3-how-it-works-module-by-module)
4. [Notifications and Smart Alerts](#4-notifications-and-smart-alerts)
5. [Reports and exports](#5-reports-and-exports)
6. [Automated jobs](#6-automated-jobs)
7. [Integration: what is connected and what is not](#7-integration-what-is-connected-and-what-is-not)
8. [Configuration](#8-configuration)
9. [Deployment and go-live](#9-deployment-and-go-live)
10. [Pending work and open decisions](#10-pending-work-and-open-decisions)
11. [Developer guide](#11-developer-guide)
12. [Glossary](#12-glossary)

---

## 1. What the Sales Hub is

The Sales Hub is Tourlast's internal system for managing the acquisition of properties and travel businesses. Providers (hotels, lodges, apartments, tour operators and so on) **sign up on tourlast.com**, not in the Hub. The Hub tracks everything around that signup:

- **Who brought the provider in.** Every salesperson has a permanent referral link. Signups that arrive through it are credited to them automatically.
- **Every property Tourlast has ever engaged.** The Property Engagement Registry records every contact, meeting, proposal, handover and outcome, so nothing is pursued twice and nothing is forgotten.
- **Each salesperson's day and pipeline.** Leads, scheduled calls, meetings and site visits, follow-ups and a calendar.
- **Pay under the incentive policy (Schedule 1).** Points per partner account, retainers, bonuses, claims and monthly payout statements.
- **Management oversight.** Team performance, lost reasons and objections, transfers, suspensions, and audit trails for every sensitive change.

### Architecture at a glance

```
                ┌──────────────────────────── Sales Hub (sales.tourlast.com) ────────────────────────────┐
 Salesperson    │  Referral links ─ Leads ─ Schedule/Calendar ─ Property Engagement Registry ─ Incentives │
 shares link ──▶│  /r/TL-JOHN-2847 logs the click, redirects ↓                                             │
                └───────────────────────────────────────────┬────────────────────────────────────────────┘
                                                            │ redirect with ?ref=TL-JOHN-2847
                                                            ▼
                                tourlast.com /list-your-property  (provider signs up; ref code stored)
                                                            │
                        Sales Hub reads provider records ◀──┘  read-only: database view, JSON API
                        every 10 minutes (+ nightly full check)  or instant webhook
```

The Hub **only reads** from tourlast.com. It never writes to it.

### Technology

| Part | Choice |
|---|---|
| Framework | Laravel 13 (PHP 8.3+), Livewire 4, Alpine.js |
| Styling | Tailwind CSS 4, Ubuntu font, Tourlast navy `#0C5295` |
| Database | MySQL 8 in production (SQLite locally) |
| Permissions | spatie/laravel-permission (roles + permissions) |
| Exports | maatwebsite/excel (Excel), barryvdh/laravel-dompdf (PDF) |
| API | REST API v1 with Laravel Sanctum tokens and scopes (docs/API.md) |
| Tests | PHPUnit feature tests (320+ tests, all passing) |

---

## 2. Roles and who can do what

Accounts are **invitation-only**. There is no public registration. Six roles exist:

| Area | Super Admin | Sales Admin | Sales Manager | Salesperson | HR | Accounts |
|---|:-:|:-:|:-:|:-:|:-:|:-:|
| Own dashboard, leads, schedule, calendar, earnings, claims | ✓ | ✓¹ | ✓¹ | ✓ | – | – |
| Invite people | ✓ | ✓ | Salespeople only | – | – | – |
| Change a person's role or region | ✓ | ✓ | – | – | – | – |
| Suspend / lift a suspension | ✓ | ✓ | Salespeople only | – | – | – |
| Fire (terminate) | ✓ | ✓ | Salespeople only | – | – | – |
| Reinstate someone who was fired | ✓ | ✓ | – | – | – | – |
| Delete an account (only if it has no history) | ✓ | ✓ | – | – | – | – |
| Team performance, targets, team calendar | ✓ | ✓ | ✓ | – | – | – |
| Property Engagement Registry: view and search | ✓ | ✓ | ✓ | ✓ (read only) | ✓ (read only) | ✓ (read only) |
| Registry: add, edit, log engagement, assign, transfer, archive | ✓ | ✓ | ✓ | – | – | – |
| Registry: Excel export and report | ✓ | ✓ | ✓ | – | – | – |
| Transfer leads between salespeople | ✓ | ✓ | ✓ | – | – | – |
| Lost & objections (team) | ✓ | ✓ | ✓ | – | – | – |
| Verify partner accounts, run the 14-day review | ✓ | ✓ | – | – | – | – |
| Partner Register and exports | ✓ | ✓ | View only | – | ✓ | ✓ |
| Everyone's earnings and statements | ✓ | ✓ | – | – | ✓ | ✓ |
| Approve and pay statements | ✓ | – | – | – | – | ✓ |
| Claim approvals | ✓ | Manager step | Manager step | – | HR step | Finance step |
| Payment details (M-Pesa/bank) of others | ✓ | ✓ | – | – | ✓ | ✓ |
| Write announcements | ✓ | ✓ | ✓ | – | ✓ | ✓ |
| People directory and online status | ✓ | ✓ | ✓ | – | ✓ | ✓ |
| Admin → Integration (tourlast.com connection) | ✓ | – | – | – | – | – |

¹ Sales Admins and Sales Managers who also sell have a referral code and their own "Me" section.

**Hard rules enforced on the server (not just hidden in the page):**
- Nobody can suspend, fire or delete their own account, Super Admin included.
- Only a Super Admin can act on another Super Admin.
- Salespeople can never create or change registry records, transfer leads or export the registry, even with crafted requests.

Roles and permissions are defined in `app/Enums/Role.php` and `app/Enums/Permission.php` and written to the database by `RolesAndPermissionsSeeder` (re-run it after every update that adds a permission — see section 9).

---

## 3. How it works, module by module

### 3.1 Accounts, sign-in and account status

- People are invited from **Admin → Users & Invites**. The invitee gets a single-use link by email (from `sales@tourlast.com`), valid for 7 days, and sets their own password.
- The first Super Admin is created on the server: `php artisan hub:create-super-admin` (interactive; refuses if a Super Admin already exists).
- **Account status** is one of:

| Status | Effect | Undone by |
|---|---|---|
| Active | Normal access | — |
| Suspended (optionally until a date) | Signed out immediately, cannot sign in; nothing removed | Manual reinstatement, or automatically on the end date (06:00 job) |
| Fired | Signed out, cannot sign in; all records kept for audit and pay | Sales Admin / Super Admin only |

- **Delete** permanently removes an account **only if it has no business history** (no leads, activities, onboardings, points, statements, claims, agreements, registry or transfer records). This is deliberate: deleting a user in the database would also remove their points, payout statements and claims. Anyone with history must be fired instead; the Hub lists what is blocking deletion.
- Every suspension, firing and reinstatement is recorded (from/to status, reason, notes, who, when) and shown under **Account history** on the person's People profile. Management receives a Smart Alert.
- When suspending or firing someone who still owns open leads or registry properties, the Hub prompts a handover (see 3.8).

### 3.2 Referral links and the Referral Center

- Every salesperson (and selling manager) gets a permanent code such as `TL-JOHN-2847` (prefix set by `HUB_REFERRAL_PREFIX`).
- Their tracked link is `https://sales.tourlast.com/r/TL-JOHN-2847`. The Hub logs the click and redirects to `https://www.tourlast.com/list-your-property?ref=TL-JOHN-2847`.
- A lead-specific version (`…/r/CODE?l=<lead id>`) also records which lead clicked.
- **Referral Center** (salespeople): link, copy/WhatsApp/email buttons, QR code download, and the funnel **Link visits → Applications → Approved → Live → Active**, plus the latest referred providers with their onboarding progress.

### 3.3 Onboardings (tourlast.com signups)

- The Hub reads provider records from tourlast.com (section 7). Each record is an **onboarding** credited to the salesperson whose code it carries.
- Status comes from tourlast.com and is translated to: `submitted` → `under_review` → `approved` → `active` (live), `inactive` (it went live and stopped since), or `rejected`.
- **Onboarded = Activation Date.** Under Schedule 1, a provider counts on the day it first becomes **active** (live and bookable). If it is later rejected, the credit is removed; an **inactive** property keeps it and drops out of the live counts.
- A progress indicator shows **Referral → Application → Verification → Approval → Live** on each onboarding.
- **Signups without a code** wait under **Unattributed**. A Sales Admin can credit one to a salesperson with a written reason, which is kept on record and survives later syncs.
- **My Onboardings** (salespeople) lists every provider that signed up through their link.
- **Deleted** rows: when tourlast.com reports a property as gone, the Hub archives the onboarding (and its lead and registry entry), shows a **Deleted** badge instead of dropping the history, and keeps the credit it earned (statements mark it *(deleted)*). A Sales Admin can **Restore** it, and the next sync restores it automatically if tourlast.com brings it back.

### 3.4 The salesperson dashboard (My Progress)

A salesperson's home page, in this order:

1. **Today strip:** follow-ups due, meetings and visits, overdue items, onboardings in progress.
2. **Today's schedule:** each call, meeting or visit with time, property, contact and a **Done** button; overdue items first.
3. **My sales pipeline:** lead counts by status (New, Contacted, Meeting, Link sent, Onboarded, Lost).
4. **My performance** (switch week / month / quarter / year): target ring and points, partners gone live, not live yet, expected pay this month.
5. **My referrals** (link, QR, funnel), partner acquisition chart, needs follow-up, recent activity.

Managers can open the same view for any salesperson from **Team Performance**. Non-selling roles see a team overview instead.

### 3.5 Leads, activities, scheduling and calendar

- **Leads** are a salesperson's active opportunities. Each has contact details, optional business identifiers (trading name, website, registration number, KRA PIN), status, activity history, schedule and ownership history.
- **Log activity** records a call, WhatsApp, email, meeting, site visit, demo, proposal, contract discussion or follow-up. A first activity moves a New lead to Contacted; a meeting, visit or demo moves it to Meeting.
- **Lead status "Onboarded"** is never set by hand. It is set automatically when the lead's tourlast.com signup goes live (matched by the lead's phone or email).
- **Schedule** (one panel used everywhere): property/lead, type, title, date, optional time and duration, contact and role, location, notes. Leave the time empty for an "anytime" reminder.
- **Mark as done** asks for the outcome and the next step; the outcome is logged as an activity and the next follow-up is booked.
- **Calendar:** day, week and month views. Salespeople see their own; managers see the whole team or one salesperson.
- **Activities & Schedule** lists overdue, today and upcoming items plus recent activity.

### 3.6 Duplicate protection (Hub-wide)

Before anyone records a property — a salesperson adding or editing a lead, or a manager adding or editing a registry record — the Hub searches:

- the **Property Engagement Registry**, every salesperson's **leads**, and **tourlast.com signups**,
- by **name, trading name, phone, email, website, registration number and KRA PIN**, with location strengthening a name match. Name matching works both ways ("Tembo Lodge Naivasha" matches "Tembo Lodge").

The warning shows the owner ("Being worked by Mary Wambui"), current stage and last contact, with two actions:

| The match is… | "Continue existing engagement" does |
|---|---|
| A registry record | Creates your lead linked to it (the registry rep does not change; management and the current rep are alerted) |
| Your own lead | Opens it instead of creating a copy |
| Another salesperson's open lead | Not offered — "Mary is working on this. Speak to your manager before approaching." |

A lead cannot be created while matches are shown unless the salesperson continues an existing engagement or confirms "None of these — this is a different property" (management is alerted to that override).

### 3.7 Property Engagement Registry

The institutional record of **every property Tourlast has ever engaged**, whatever the outcome.

- **Stage** (where it is in the process): Not contacted → Contacted → Interested → Meeting scheduled → Meeting completed → Demo → Proposal sent → Negotiation → Onboarding started → Onboarding submitted → Verification → Approved → Live.
- **Status** (its condition, kept separate from stage): Active, Stalled, Won, Lost, Rejected, Re-engage, Closed.
- **Profile:** property and location details, contacts (with decision-maker flag), current and previous sales representatives, engagement summary, onboarding progress, linked leads and signups.
- **Engagement history** (newest first) combines:
  - the registry's own entries (added, calls, meetings, proposals, stage/status/rep changes, edits with old → new values, contacts added/removed, links, archive/restore),
  - calls and meetings logged on linked salesperson leads ("via lead"),
  - an **Upcoming** block of scheduled meetings on those leads.
- **Audit:** entries are only ever added, never edited or deleted, so the timeline is also the audit log.
- **Links:** managers can link salesperson leads and tourlast.com signups (the profile suggests likely matches). Once a signup is linked, the registry stage follows tourlast.com automatically (Submitted → Onboarding submitted, Under review → Verification, Approved → Approved, Active → Live/Won, Rejected → Rejected).
- **Archive** hides a record (history kept); managers can restore it.
- **Search and filters:** property, business, location, contact, phone, salesperson; type, country, region, city, salesperson, stage, status, source, first/last engaged dates, active/inactive, onboarded.

### 3.8 Ownership and handover

- **Registry:** *Assign property* (no rep yet) or *Transfer ownership* (reason required: territory reassignment, salesperson left, workload balancing, property requested a different contact, performance, new assignment, other + notes). The previous rep's period is closed, not deleted.
- **Leads:** managers can *Transfer ownership* of a lead. Open schedule items move to the new owner; past activity stays with whoever did it; if the previous owner also represented the linked registry property, the registry follows with the same reason. The new owner is alerted.
- **Bulk handover:** on Leads, filter by a salesperson (including inactive ones) and transfer all their open leads at once.
- Every transfer records previous and new salesperson, who transferred it, date, reason and notes, shown on the lead's **Ownership** card and the registry timeline.

### 3.9 Lost reasons, objections and re-engagement

- **Marking a lead Lost** requires a **Primary objection**: Commission, Pricing, Already using another OTA, Already has a PMS, No need, Contract restrictions, Technical concerns, Management approval, Timing, Competitor relationship, Other. **Competitor** is required for "Already using another OTA" and "Competitor relationship". Notes and a **Re-engage on** date are optional.
- **Registry** records set to Lost or Rejected require the same; for Stalled or Closed it is optional.
- **Re-engagement:**
  - A lost lead with a date immediately gets a "Re-engage after loss" item in the owner's schedule and calendar on that date.
  - Every morning (06:45) registry records with a due re-engage date move to **Re-engage**, the rep and management are alerted, and a follow-up is added to the rep's lead for that property.
- **Lost & objections** (management) answers "why are hotels refusing Tourlast?": objections ranked, competitors, losses by salesperson and property type, upcoming re-engagements, and all losses.
- **My losses** (salespeople) shows the same for their own records only.

### 3.10 Targets and progress

- Targets are in **points** and set by each salesperson for the month (18 and 30 are suggested because they unlock the retainer). They can be changed until the 7th (`HUB_TARGET_LOCK_DAY`), then lock. Managers see targets but cannot change them.

### 3.11 Incentives (Schedule 1)

- **Partner Accounts** group every property of one legal business. Points come from verified inventory:
  - Stays (rooms/units): 1–10 → 1 point, 11–50 → 3, 51–100 → 5, 101–200 → 7, 201+ → 9.
  - Experiences (bookable services): 1–5 → 1 point, up to 51+ → 5.
- Points start **provisional** and become **approved** when a Sales Admin completes the 13-item qualification checklist and verifies the Account. The points ledger is append-only: old lines are cancelled and replaced, never edited.
- **90-day expansion:** growth to a higher category earns the difference; 50%+ growth in the same category earns one 0.5 award (caps 9 stays / 5 experiences), while the incentive agreement is in force.
- **14-day review:** a failed Account loses its points; anything already paid is recovered on the next statement.
- **My Earnings** shows, live: expected retainer (18/30 points), four fixed bonus weeks (18+ points = KES 1,500), monthly bonus bands, exceptional-performance payment (KES 300 per point above 76, capped at KES 10,000), airtime (cap KES 400) and transport.
- **Payouts:** draft statements are generated on the 1st; a Sales Admin confirms retainer conditions; Accounts approves (figures freeze) and marks them paid by the 5th. PDF statements include the paragraph 13 report.
- Pay tables are stored in the `incentive_policies` table (seeded from `App\Incentives\Policy::schedule1()`), so they can change without code changes.

### 3.12 Claims

| Claim | Evidence | Approval chain |
|---|---|---|
| Airtime | — | Finance |
| Transport reimbursement | Receipt upload, or Bolt/Uber trip ID + ride details | Sales Manager → HR → Finance |
| Transport request (before a trip) | Purpose and route | Sales Manager → HR → Finance |

The chain is configured in `config/incentives.php`. An optional monthly transport cap is set with `HUB_TRANSPORT_MONTHLY_CAP`.

### 3.13 Payment details

Salespeople choose **M-Pesa** (number + registered name) or **bank account** (bank, account number, account name) on My Earnings. Details are **encrypted at rest**, visible to admins, HR and Finance only (not Sales Managers), and HR and Finance are alerted on every change.

### 3.14 People, profiles and online status

- Everyone can upload a photo and set position, bio and emergency contact under **My profile**.
- **People** (admins, Sales Managers, HR, Finance) shows colleagues, their online status (active in the last five minutes) and account history.

---

## 4. Notifications and Smart Alerts

- **Announcements** can be written by admins, Sales Managers, HR and Finance, targeted by role, and marked important. Salespeople read only.
- **Smart Alerts** appear in the bell and on the Notifications page. They go to management (Super Admin, Sales Admin, Sales Manager) and, where relevant, to the salesperson concerned.

| Alert | Sent when |
|---|---|
| New property referred / New onboarding submitted | A tourlast.com signup arrives |
| Partner approved / Partner rejected | Its tourlast.com status changes |
| Property inactive | A live partner is removed on tourlast.com |
| Property deleted / restored | tourlast.com removes a property, or brings it back |
| First booking received | tourlast.com reports its first booking (needs `first_booking_at`, section 7) |
| Deal won / Deal lost | A lead becomes Onboarded / is marked Lost |
| Contract expiring | An incentive agreement ends within 30 days (daily) |
| Follow-up overdue | A scheduled item is past due (daily) |
| Payment details changed | To HR and Finance only |
| Possible duplicate property | A lead overlaps someone else's active engagement |
| Ownership transferred | A lead or property is handed to someone |
| Re-engagement due | A lost/paused registry property's date arrives |
| Account suspended or fired | An account's status changes |

Managers also receive a weekday **07:30 email summary**: who is inactive, which signups are stalled, and who is behind target.

---

## 5. Reports and exports

| Report | Where | Who |
|---|---|---|
| Partner Register (Excel + branded PDF) | Partners & pay → Partner Register | Admins, HR, Accounts |
| Property Engagement Registry (Excel) | Registry → Export Excel | Managers |
| Property Engagement Report (PDF, chosen period) | Registry → Generate report | Managers |
| Payout statements (PDF per person, Excel batch) | Payouts | Admins, HR, Accounts |
| Lost & objections | Management → Lost & objections | Managers |

All exports respect the filters currently applied on screen.

---

## 6. Automated jobs

Defined in `routes/console.php`. They require the Laravel scheduler to run every minute on the server (section 9).

| Time | Command | What it does |
|---|---|---|
| Every 10 min | `hub:sync-tourlast` | Reads recently changed providers from tourlast.com |
| 02:00 daily | `hub:sync-tourlast --full` | Full re-check of all referred providers |
| 06:00 daily | `hub:reinstate-suspensions` | Lifts suspensions whose end date has arrived |
| 06:45 daily | `hub:process-reengagements` | Moves due registry properties to Re-engage and books follow-ups |
| 07:00 daily | `hub:send-daily-alerts` | Contract-expiry and overdue follow-up alerts |
| 07:30 weekdays | `hub:send-manager-alerts` | Managers' summary email |
| 1st of month, 06:00 | `hub:generate-statements` | Draft payout statements for the previous month |

A **queue worker** must also run to send emails. Every command can be run by hand, e.g. `php artisan hub:sync-tourlast --full`.

---

## 7. Integration: what is connected and what is not

### 7.1 Status

| Connection | Status | Owner |
|---|---|---|
| Referral redirect `/r/{code}` → tourlast.com `?ref=` | ✅ Built in the Hub | — |
| **Ref-code list** the Hub serves (`GET /integrations/tourlast/ref-codes`) and tourlast.com reads to validate `?ref=` | ⏳ To do on tourlast.com (endpoint is live) | tourlast.com dev |
| Hub side of the sync: shared token, push endpoint, `inactive_at`, `is_deleted`, Deleted badge and restore | ✅ Built in the Hub | — |
| tourlast.com **stores the `ref` code** on the provider | ⏳ **To do on tourlast.com** | tourlast.com dev |
| Connection for provider records: Hub reads (DB view or JSON API) **or** tourlast.com pushes to the Hub API | ⏳ **To do on tourlast.com** (push needs no access to tourlast.com) | tourlast.com dev |
| Partner **Account fields** (`account_id`, `legal_name`, `category`, `inventory_count`) | ⏳ To do on tourlast.com (Hub works without them meanwhile) | tourlast.com dev |
| **First booking** date (`first_booking_at`) | ⏳ To do on tourlast.com (only needed for the "First booking" alert) | tourlast.com dev |
| **Webhook** for instant updates | Optional — the 10-minute sync covers it | tourlast.com dev |
| Email sending (SES or SMTP) | ⏳ To configure | DevOps |
| Everything else (registry, leads, schedule, incentives, claims, reports) | ✅ Self-contained in the Hub | — |

The Hub reads real data only (`TOURLAST_SOURCE=api`, the default). Local development can use **sandbox** mode (`TOURLAST_SOURCE=sandbox`, local and test environments only, refused in production): **Admin → Integration** has a simulator that creates sample signups and moves them through each status, using exactly the same code path as real data.

### 7.2 What the tourlast.com developer must do

Full details, code samples and SQL are in **[TOURLAST_INTEGRATION.md](TOURLAST_INTEGRATION.md)**. In summary:

1. **Capture the referral code.** On `/list-your-property`, read `?ref=` and keep it in a 60-day cookie.
2. **Store it** on the host/property record (e.g. `ref_code VARCHAR(40) NULL`). Keep the first code a provider arrived with.
3. **Give the Hub read access over the API only.** The Hub never connects to another app's database (`TOURLAST_SOURCE=database` is rejected), so either:
   - **Option A - read-only JSON endpoint** `GET /api/sales-hub/referrals?updated_since=…&page=…` with a bearer token; or
   - **Option B - push** provider records into the Hub API.
4. **Keep `updated_at` current** whenever status or inventory changes (the Hub reads changes since the last sync).
5. **Add the Account fields** for incentives when possible: `account_id`, `legal_name`, `category` (`stay`/`experience`), `inventory_count`. Until then each property is its own Account and the Sales Admin enters verified counts.
6. **Optionally** expose `first_booking_at` and send the signed **webhook** on signup and status changes.
7. **Confirm value mappings.** Add tourlast.com's exact status and type values to `status_map` and `type_map` in `config/tourlast.php` if they differ.

### 7.3 Data the Hub reads per provider

| Field | Required | Notes |
|---|:-:|---|
| `property_id` | ✓ | Unique, stable ID |
| `ref_code` | ✓ | e.g. `TL-JOHN-2847`; case-insensitive |
| `property_name`, `property_type`, `location` | ✓ | Type is mapped to Hub types |
| `contact_name`, `contact_phone`, `contact_email` | Recommended | Used to link signups to leads and for duplicate checks |
| `status` | ✓ | Mapped to submitted / under_review / approved / active / inactive / rejected |
| `submitted_at`, `approved_at`, `active_at`, `rejected_at` | ✓ (`active_at` critical) | `active_at` is the Activation Date that drives points |
| `inactive_at` | Optional | When a live property stopped; dates the change to `inactive` (falls back to `updated_at`) |
| `is_deleted` | Optional | `true` means the property is gone: the Hub archives the onboarding (and its lead and registry entry), keeps the credit and counts it as deleted in the sync log; `false` or a later row restores it |
| `deleted_at` | Optional | When it was removed; used as the deletion time when `is_deleted` is `true` |
| `updated_at` | ✓ | Must change on every status or inventory change |
| `account_id`, `legal_name`, `category`, `inventory_count` | For incentives | See 7.2 step 5 |
| `first_booking_at` | Optional | Enables the "First booking received" alert |

### 7.4 The Sales Hub API

Everything in the Hub is also available through a REST API at `https://sales.tourlast.com/api/v1`, documented in **[API.md](API.md)** with a machine-readable OpenAPI 3.1 file at `docs/api/openapi.json` (regenerate with `php artisan hub:api-spec`).

- **Tokens:** people sign in with `POST /auth/tokens`; systems get tokens from **Admin → API tokens** (Super Admin, Sales Admin). Everyone can see and revoke their own tokens on their profile.
- **Scopes plus permissions:** each token is limited to scopes (e.g. `leads:read`, `registry:write`) and can never do more than its owner can in the Hub. Suspending or firing someone stops their tokens immediately.
- **Same rules as the web app:** duplicate protection (`409`), required lost reasons, append-only history and role permissions all apply.
- **tourlast.com push:** `php artisan hub:generate-token` writes one shared secret into the Hub's `.env` as `TOURLAST_API_TOKEN` and prints it once — put the same value in each source app's `.env` as `TOURLAST_HUB_TOKEN`. It authenticates `POST /integrations/tourlast/providers` and `GET /integrations/tourlast/ref-codes` (no account, role or scope is created); set `TOURLAST_SOURCE=push`.
 - Rate limit: 120 requests per minute per user (per IP address on the shared-token feed).

### 7.5 Testing the connection

1. Set the `.env` values (section 8), run `php artisan config:clear`.
2. Run `php artisan hub:sync-tourlast --full`. It prints how many records were read, created, updated and deleted, or the exact error.
3. Open **Admin → Integration** to see the sync log and latest status changes.
4. Visit `https://sales.tourlast.com/r/<real code>`, complete a test signup, approve it and make it live on tourlast.com. Within 10 minutes it appears under that salesperson's **My Onboardings** and counts on **My Progress**.

---

## 8. Configuration

### 8.1 Environment settings (`.env`)

`.env.example` lists every setting with safe defaults. Values marked **must set** have no usable default in production.

**Application**

| Setting | Production value | |
|---|---|---|
| `APP_ENV` | `production` | must set |
| `APP_DEBUG` | `false` | must set |
| `APP_KEY` | generated by `php artisan key:generate` | must set |
| `APP_URL` | `https://sales.tourlast.com` | |

**Database, sessions and queue**

| Setting | Value |
|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | MySQL (RDS) credentials — **must set** |
| `SESSION_DRIVER` | `database` (required: suspending/firing signs people out by clearing their sessions) |
| `SESSION_SECURE_COOKIE` | `true` |
| `QUEUE_CONNECTION` | `database` |

**Email** (invitations, password resets, manager summary)

| Setting | Value |
|---|---|
| `MAIL_MAILER` | `ses` (Amazon SES) or `smtp` — **must set** |
| `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | `sales@tourlast.com` / `Tourlast Sales` |
| SES: `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_DEFAULT_REGION` | From AWS; also run `composer require aws/aws-sdk-php` |
| SMTP: `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD` | From the mail provider |

**Sales Hub behaviour**

| Setting | Default | Meaning |
|---|---|---|
| `HUB_LIST_PROPERTY_URL` | `https://www.tourlast.com/list-your-property` | Where referral links send providers |
| `HUB_REFERRAL_PREFIX` | `TL` | Start of every referral code |
| `HUB_INVITATION_EXPIRY_DAYS` | `7` | Invitation link lifetime |
| `HUB_TARGET_LOCK_DAY` | `7` | Last day of the month a target can change |
| `HUB_STALLED_AFTER_DAYS` | `14` | Signup flagged as stalled after this many days |
| `HUB_INACTIVE_AFTER_DAYS` | `3` | Salesperson flagged inactive after this many days without activity |
| `HUB_DEFAULT_COUNTRY` | `Kenya` | Pre-filled country on new registry records |
| `HUB_TRANSPORT_MONTHLY_CAP` | empty (no cap) | Monthly transport claim cap per salesperson, KES |

**tourlast.com connection**

| Setting | Meaning |
|---|---|
| `TOURLAST_SOURCE` | `api` (Hub reads source apps over their API) · `push` (source apps call the Hub API; scheduled sync skipped) · `sandbox` (local simulator; refused outside local and test environments) |
| `TOURLAST_SYNC_EVERY_MINUTES` / `TOURLAST_FULL_SYNC_AT` | Sync frequency (10) and nightly full check (`02:00`) |
| `TOURLAST_API_URL`, `TOURLAST_API_PATH`, `TOURLAST_API_TOKEN`, `TOURLAST_API_TIMEOUT` | Read-only JSON endpoint (Option A); `TOURLAST_API_URL` accepts a comma-separated list of app bases. `TOURLAST_API_TOKEN` is also the shared sync token that `POST /integrations/tourlast/providers` accepts — set it with `php artisan hub:generate-token` |
| `TOURLAST_WEBHOOK_SECRET` | Shared secret for the optional webhook |

After changing `.env` in production, run `php artisan optimize` (or `config:clear`).

### 8.2 Settings in code (change with a deploy)

| File | Holds |
|---|---|
| `config/hub.php` | Property types, accommodation types, contact job titles, star ratings, competitor suggestions, target lock day, stalled/inactive thresholds |
| `config/tourlast.php` | tourlast.com status and type value maps, column defaults |
| `config/incentives.php` | Claim approval chains, upload size and file types, transport cap |
| `app/Enums/*.php` | Roles and permissions, registry stages/statuses/sources, objections, activity types, account statuses |
| `incentive_policies` table | Schedule 1 pay tables (points bands, retainers, bonuses) |

---

## 9. Deployment and go-live

Step-by-step server setup is in **[DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md)**. The essentials:

**Infrastructure**

| Piece | Suggested |
|---|---|
| Web server | EC2 or Lightsail, PHP 8.3+ (`pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `gd`, `intl`, `bcmath`), Nginx, Composer; Node 20+ for building assets |
| Database | RDS for MySQL 8 with automated backups |
| Email | Amazon SES (verify tourlast.com) or company SMTP |
| DNS / HTTPS | `sales.tourlast.com` → server; Let's Encrypt or ACM |

**First deployment**

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate      # then edit .env (section 8)
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan hub:create-super-admin   # prompts for name, email, password
php artisan storage:link
php artisan optimize
```

Never run the plain `db:seed` in production (demo data only seeds when `APP_ENV=local`).

**Background processes**

```cron
* * * * * cd /var/www/sales-hub && php artisan schedule:run >> /dev/null 2>&1
```

plus a Supervisor program running `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`.

**Every update**

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force   # picks up new permissions
php artisan optimize
sudo supervisorctl restart sales-hub-worker
```

**Go-live checklist**

- [ ] `https://sales.tourlast.com/up` returns 200
- [ ] Super Admin can sign in; an invitation email arrives from sales@tourlast.com
- [ ] `php artisan hub:sync-tourlast --full` succeeds against tourlast.com
- [ ] A real `/r/<code>` link lands on tourlast.com with `?ref=` attached, and a test signup reaches the salesperson within 10 minutes
- [ ] Scheduler cron and queue worker are running (`php artisan schedule:list` shows the jobs in section 6)
- [ ] RDS automated backups are on
- [ ] Sales Admin has reviewed the Schedule 1 pay tables and claim approval chain

---

## 10. Pending work and open decisions

### 10.1 Needed before go-live

| # | Item | Owner | What is needed |
|---|---|---|---|
| 1 | Store `ref` codes on tourlast.com | tourlast.com dev | Section 7.2 steps 1–2 |
| 2 | Connect tourlast.com | tourlast.com dev | Hub reads (database view or JSON API), or tourlast.com pushes to the Hub API with the shared sync token (7.4) |
| 3 | Confirm status/type values | tourlast.com dev | Update `config/tourlast.php` maps if needed |
| 4 | Server, database, DNS, HTTPS | DevOps | Section 9 |
| 5 | Email sending | DevOps | SES (+ `composer require aws/aws-sdk-php`, domain verification) or SMTP |
| 6 | Scheduler and queue worker | DevOps | Cron + Supervisor (section 9) |
| 7 | Production `.env` | DevOps | Section 8.1 |
| 8 | Commit the code to Git | Development | Only the initial scaffold is committed; all modules since are uncommitted locally |
| 9 | Staging test on the real domain | Development + Sales Admin | Full walk-through with real accounts before inviting the team |

### 10.2 Recommended soon after go-live

| # | Item | Owner | Notes |
|---|---|---|---|
| 10 | Partner Account fields from tourlast.com | tourlast.com dev | `account_id`, `legal_name`, `category`, `inventory_count` — removes manual inventory entry |
| 11 | `first_booking_at` from tourlast.com | tourlast.com dev | Enables the "First booking received" alert |
| 12 | Webhook for instant updates | tourlast.com dev | Optional; sync already runs every 10 minutes |
| 13 | File storage on S3 | DevOps | Photos, receipts and evidence are on the server disk (`storage/app`); move to S3 if the server is replaced or scaled |
| 14 | Official logo file | Tourlast | The Hub uses a vector redraw of the logo; supply the original SVG/PNG for `public/images/` |
| 15 | Set `HUB_TRANSPORT_MONTHLY_CAP` | Management | Empty means no cap |

### 10.3 Open product decisions

| # | Question | Current behaviour |
|---|---|---|
| 16 | Should a **fired salesperson's referral link** stop crediting new signups? | It keeps working and crediting them |
| 17 | Should marking a **lead Lost** also update its linked **registry record**? | No — managers record the registry outcome (keeps the registry manager-owned) |
| 18 | Should **tourlast.com signups automatically create registry records**? | No — managers add records; signups are linked when they match |
| 19 | **Reminders before meetings** (e.g. 30 minutes before)? | Not built; overdue items are alerted daily |
| 20 | Lead pipeline stages **Proposal** and **Negotiation**? | Lead statuses are New, Contacted, Meeting, Link sent, Onboarded, Lost; the registry has the full stage list |
| 21 | Should HR get a read-only view of account history for all staff? | HR sees it per person under People |

---

## 11. Developer guide

### 11.1 Local setup

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate      # APP_ENV=local, TOURLAST_SOURCE=api
php artisan migrate --seed                            # roles and policy only; demo data needs APP_ENV=local + TOURLAST_SOURCE=sandbox (password "password")
php artisan test                                      # 242 tests
```

Demo accounts: `admin@` (Super Admin), `grace@` (Sales Admin), `david@` (Sales Manager), `john@`, `mary@`, `peter@`, `james@` (Salespeople), `faith@` (HR), `samuel@` (Accounts) — all `@tourlast.test`.

### 11.2 Where things live

| Area | Location |
|---|---|
| Pages (Livewire components + views) | `app/Livewire/*`, `resources/views/livewire/*` |
| API v1 | `routes/api.php` + `routes/api/v1/*.php`, `app/Http/Controllers/Api/V1/*`, `app/Http/Resources/V1/*`, scopes in `app/Enums/ApiScope.php`, tests in `tests/Feature/Api/*` |
| Business actions (one job per class) | `app/Actions/*` — e.g. `ApplyProviderRecord`, `SavePropertyEngagement`, `LogEngagement`, `TransferLead`, `MarkLeadLost`, `ChangeAccountStatus`, `DeleteUser` |
| Incentive engine | `app/Incentives/*` (`Calculator`, `AccountPoints`, `MonthlyEarnings`, `Statements`, `Claims`, `Policy`) |
| tourlast.com integration | `app/Integrations/Tourlast/*`, `config/tourlast.php` |
| Rules and helpers | `app/Support/*` — `Navigation`, `Alerts`, `PropertyDuplicateCheck`, `MatchKeys`, `EngagementRegistryFilters`, `OutcomeRules` |
| Authorisation | `app/Policies/*` (`PropertyEngagementPolicy`, `UserPolicy`), `app/Enums/Role.php`, `app/Enums/Permission.php`, gates in `AppServiceProvider` |
| Scheduled commands | `app/Console/Commands/*`, `routes/console.php` |
| Shared UI components | `resources/views/components/ui/*` (buttons, cards, tables, slide-overs, pills, icons) and `components/*` (duplicate warning, outcome fields, logo) |
| Design tokens | `resources/css/app.css` (`--tl-*` colours, Ubuntu font, table header style) |
| PDF report templates | `resources/views/reports/*` |
| Tests | `tests/Feature/*`, `tests/Unit/*` |

### 11.3 Principles to keep

- **History is never rewritten.** Points ledger lines, registry events, lead transfers, attribution changes and account status changes are append-only. Correct by adding a new entry.
- **Authorise on the server.** Every Livewire action calls a policy or permission check, not just the view.
- **One source for lists.** Property types, job titles and competitors live in `config/hub.php`; stages, statuses and objections are enums.
- **Duplicates are checked in one place** (`PropertyDuplicateCheck`) — reuse it for any new entry point.
- Run `vendor/bin/pint --dirty` before committing and keep `php artisan test` green.

### 11.4 Common changes

| To… | Do |
|---|---|
| Add a property type | Add it to `property_types` in `config/hub.php` (and to `accommodation_types` if it has rooms); map tourlast.com's value in `config/tourlast.php` |
| Add an objection or competitor | Add a case to `app/Enums/Objection.php`, or a name to `competitors` in `config/hub.php` |
| Give a role a new ability | Add a case to `Permission`, add it to the role in `Role::permissions()`, deploy, run `RolesAndPermissionsSeeder` |
| Add a Smart Alert | Add the type to `SmartAlert::Types`, then call `Alerts::send()` where it happens |
| Change pay rules | Edit the policy in `incentive_policies` (seeded from `Policy::schedule1()`); the calculator reads it |
| Add a scheduled job | Create a command in `app/Console/Commands`, register it in `routes/console.php` |

---

## 12. Glossary

| Term | Meaning |
|---|---|
| **Activation Date** | The day a provider first goes live (status `active`) on tourlast.com. Onboarding credit and points date from it. |
| **Onboarding** | A provider signup read from tourlast.com, credited to a salesperson by referral code. |
| **Lead** | A salesperson's active opportunity with a property they are pursuing. |
| **Registry record** | The permanent record of a property Tourlast has engaged, with its full history. |
| **Stage / Status** | Stage = where it is in the acquisition process; Status = its current condition (active, stalled, lost…). |
| **Partner Account** | All properties of one legal business, the unit points are paid on. |
| **Provisional / approved points** | Points before and after a Sales Admin verifies the Account. |
| **Re-engage** | Approaching a lost or paused property again on a set date. |
| **Unattributed** | A signup that arrived without a referral code. |
| **Sandbox** | Built-in simulator for local development and tests. Refused in production. |
