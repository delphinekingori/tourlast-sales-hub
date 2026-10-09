# Tourlast Sales Hub — System Guide

**Address:** `https://sales-hub.tourlast.com` · **Last updated:** 7 October 2026

This is the complete reference for the Tourlast Sales Hub: what it does, how each part works, what is still to be connected, what must be configured before go-live, and what is pending. It is written for Tourlast management, the tourlast.com development team, and any developer who takes over the Hub.

| If you are… | Read |
|---|---|
| Management or a new team lead | Sections 1–6 |
| Anyone working in Travel Sales (flights, tours, experiences) | Section 4 |
| The Tourlast Flights developer, or whoever connects M-Pesa | Sections 8.6 and 8.7 |
| The tourlast.com developer | Section 8, then [TOURLAST_INTEGRATION.md](TOURLAST_INTEGRATION.md) |
| Anyone building on the API (apps, reporting, other systems) | Section 8.4, then [API.md](API.md) |
| The person deploying the Hub | Sections 9–10, then [DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md) |
| A developer maintaining the Hub | Sections 7, 11 and 12 |

---

## Contents

1. [What the Sales Hub is](#1-what-the-sales-hub-is)
2. [Roles and who can do what](#2-roles-and-who-can-do-what)
3. [How it works, module by module](#3-how-it-works-module-by-module)
4. [Travel Sales](#4-travel-sales)
5. [Notifications and Smart Alerts](#5-notifications-and-smart-alerts)
6. [Reports and exports](#6-reports-and-exports)
7. [Automated jobs](#7-automated-jobs)
8. [Integration: what is connected and what is not](#8-integration-what-is-connected-and-what-is-not)
9. [Configuration](#9-configuration)
10. [Deployment and go-live](#10-deployment-and-go-live)
11. [Pending work and open decisions](#11-pending-work-and-open-decisions)
12. [Developer guide](#12-developer-guide)
13. [Glossary](#13-glossary)

---

## 1. What the Sales Hub is

The Sales Hub is Tourlast's internal system for managing the acquisition of properties and travel businesses. Providers (hotels, lodges, apartments, tour operators and so on) **sign up on tourlast.com**, not in the Hub. The Hub tracks everything around that signup:

- **Who brought the provider in.** Every salesperson has a permanent referral link. Signups that arrive through it are credited to them automatically.
- **Every property Tourlast has ever engaged.** The Property Engagement Registry records every contact, meeting, proposal, handover and outcome, so nothing is pursued twice and nothing is forgotten.
- **Each salesperson's day and pipeline.** Leads, scheduled calls, meetings and site visits, follow-ups and a calendar.
- **Pay under the incentive policy (Schedule 1).** Points per partner account, retainers, bonuses, claims and monthly payout statements.
- **Management oversight.** Team performance, lost reasons and objections, transfers, suspensions, and audit trails for every sensitive change.
- **Travel Sales** (section 4). A separate workspace for selling flights, tours and experiences: providers and contracts, packages with two-level approval, departures and bookings, M-Pesa payments, influencer codes and a read-only copy of flight bookings.

### Architecture at a glance

```
                ┌──────────────────────────── Sales Hub (sales-hub.tourlast.com) ────────────────────────────┐
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
| Payments | Safaricom M-Pesa Daraja (STK push and paybill) for Travel Sales packages |
| Tests | PHPUnit feature tests (630+ tests) |

---

## 2. Roles and who can do what

Accounts are **invitation-only**. There is no public registration. Seven roles exist. The table covers property sales; the **Travel Salesperson** role and what each role can do in Travel Sales are in section 4.1.

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

Travel Salespeople see nothing on the property side, including the Property Engagement Registry. **Sales Managers do not see travel salespeople at all** (People, Users & Invites, calendars, lists) and cannot suspend or fire them.

**Hard rules enforced on the server (not just hidden in the page):**
- Nobody can suspend, fire or delete their own account, Super Admin included.
- Only a Super Admin can act on another Super Admin.
- Salespeople can never create or change registry records, transfer leads or export the registry, even with crafted requests.
- Travel salespeople can never approve a package, publish an unapproved one, change approval records, confirm payments or pay out refunds.

Roles and permissions are defined in `app/Enums/Role.php` and `app/Enums/Permission.php` and written to the database by `RolesAndPermissionsSeeder` (re-run it after every update that adds a permission — see section 10).

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
- Their tracked link is `https://sales-hub.tourlast.com/r/TL-JOHN-2847`. The Hub logs the click and redirects to `https://www.tourlast.com/list-your-property?ref=TL-JOHN-2847`.
- A lead-specific version (`…/r/CODE?l=<lead id>`) also records which lead clicked.
- **Referral Center** (salespeople): link, copy/WhatsApp/email buttons, QR code download, and the funnel **Link visits → Applications → Approved → Live → Active**, plus the latest referred providers with their onboarding progress.

### 3.3 Onboardings (tourlast.com signups)

- The Hub reads provider records from tourlast.com (section 8). Each record is an **onboarding** credited to the salesperson whose code it carries.
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
- **Calendar:** day, week and month views. Salespeople see their own; managers see the whole team or one salesperson. Travel salespeople use the same calendar (section 4.12); Sales Managers never see their items.
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

## 4. Travel Sales

Travel Sales is a separate workspace inside the same Hub for selling **flights, tours and experiences**. The same person can handle all of it. There is no separate login: the **Travel Salesperson** role decides what they see.

### 4.1 Who uses it

| Role | In Travel Sales |
|---|---|
| Travel Salesperson | Works on their own providers, contracts, packages, bookings, clients and influencer codes; reads the shared catalogue (all providers, packages, media) and all flight bookings. Creates and submits packages; never approves or publishes without approval |
| Sales Admin | Everything in Travel Sales for every travel salesperson; **first** package approval; approves contracts, cancellations and refunds; sets travel targets |
| Super Admin | Everything, the **final** package approval, and the only one who can publish over a contract problem (with a written reason) |
| Accounts | Payments (confirm cash/bank, allocate unmatched M-Pesa), pays out approved refunds, marks influencer commission paid, travel reports. No package or operations controls |
| Sales Manager | **Nothing.** Travel salespeople are hidden from their People, Team, calendar and lists, and they cannot suspend or fire them |
| HR | Nothing beyond what HR sees for any colleague (People profile) |

Travel salespeople are invited from **Admin → Users & Invites** by a Sales Admin or Super Admin (choose the *Travel Salesperson* role). They have no property referral code and earn no Schedule 1 points. Their home page is the **Travel dashboard**.

### 4.2 The workspace

| Page | What it is for |
|---|---|
| Travel dashboard | Flights and tours KPIs for the month, **My sales actions**, my targets, low availability, contracts expiring. Sales Admin can switch to **Whole team** (salespeople table, approvals) |
| Calendar | The normal Hub calendar: own follow-ups, meetings and check-ins plus **departures** of own packages |
| Flights | Read-only flight bookings, upcoming flights, cancellations, refunds and flight customers |
| Providers · Contracts | Tour, safari, experience, transport and other partners; their contracts, documents and incidents |
| Packages · Approvals | Building packages; the two-level approval queue |
| Inventory | Departures (dates and slots) for every approved package |
| Bookings · Clients | Package bookings and the people who made them |
| Cancellations & refunds | Requests, decisions and refund payouts |
| Payments | M-Pesa and cash/bank payments, unmatched M-Pesa, Daraja message log |
| Media Gallery | Shared photos, videos and documents, reused across packages |
| Drivers & guides | Who runs the trips, and their upcoming assignments |
| Influencers | Influencer referral codes and commission |
| Travel reports | Flights, tours, providers, approvals and salespeople for any period, with Excel export |
| Travel targets | Sales Admin sets monthly targets |
| Search (`/travel/search`, box on the dashboard) | One search across providers, contracts, packages, bookings, clients, payments, flights, drivers, guides and influencer codes, limited to what the person may open |

Every rule below is enforced on the server, not just by hiding buttons.

### 4.3 Providers, contracts and incidents

- **Providers** have business details, contacts, type (tour operator, safari operator, experience, activity, DMC, transport, guide company, adventure, attraction, dining, event/venue, other) and a status: Prospect → Contacted → Interested → Contract negotiation → Contracted → Active, or Suspended / Inactive / Terminated.
- **Duplicate check** on save: same registration number, KRA PIN, email, phone, website or name, against other travel providers, and (for Sales Admin/Super Admin only) the Property Engagement Registry. A strong match blocks a travel salesperson; only Sales Admin/Super Admin can continue anyway. Sales Admin/Super Admin can link a provider to its Registry record; travel salespeople never see Registry records, and Travel Sales never creates them.
- Everyone in Travel Sales sees every provider; only the owner or a manager edits.
- **Contracts** (number `TL-YYYY-NNN`): type, dates, commission model (percentage / fixed per booking / net rate) and rate, currency, payment, settlement, cancellation and refund terms, documents (signed contract, addendum, rate sheet, terms, insurance, permit; stored privately).
  - Workflow: Draft → Pending review → Pending approval → **Active** (approved by Sales Admin/Super Admin; the creator cannot approve their own unless Super Admin). Suspend and Terminate need a reason.
  - "Expiring soon" (last 30 days) and "Expired" are worked out from the end date.
  - Commission figures and documents are shown only to Sales Admin, Super Admin, Accounts and the provider's owner.
  - **Expiry alerts** at 30, 14 and 7 days and on expiry, once each, to Travel managers and the owner (daily job).
- **Incidents:** driver no-show, guide/vehicle issue, complaint, package mismatch, safety concern, provider cancellation; severity, assignee, resolution, status.
- The provider page shows performance: packages, bookings, revenue, cancellations, refunds, slots sold, active contract and expiry.

### 4.4 Media Gallery

- Upload once, reuse everywhere: up to 20 files at a time (images up to 8 MB, MP4 up to 50 MB, PDF up to 10 MB). Details are entered once per batch: provider, destination, category, tags, source, copyright owner, usage permission.
- Grid and list views, filters, bulk edit, and **"Used in N packages"** on every file.
- **Revoked permission:** the file stays on packages that already show it but can no longer be chosen. Only managers revoke.
- Files are archived rather than deleted. Deleting is possible only when no package uses the file. A file on a published package can only be archived by a manager who types "Archive anyway".

### 4.5 Packages: building, approval, versions and publishing

**Statuses are kept separate:** the package (Draft, Pending approval, Approved, Published, Unpublished, Archived), each booking (Pending, Confirmed, Completed, Cancelled, No-show) and each trip (Scheduled, Confirmed, Preparing, In progress, Completed, Cancelled).

- **Building:** tabs for Basics, Content (overview, highlights, inclusions, exclusions, requirements, what to bring, terms, cancellation and refund policy, meeting point, pickup, drop-off), Itinerary (day by day: title, description, activities, meals, accommodation, transport, notes), Pricing (adult, child, infant, group, single supplement, discount; provider price, net price and commission only for Sales Admin, Super Admin, Accounts and the owner), Media (picked from the gallery, primary image and order), Driver & guide. *Created by* and *Updated by* are set by the Hub and cannot be edited.
- **Readiness checklist** before "Submit for approval": name, short and full description, provider, active contract, destination, adult price, at least one itinerary day, inclusions, exclusions, cancellation policy, refund policy, at least one usable image, capacity.
- **Duplicate check:** same provider and similar name, or same destination and very similar name. An exact match blocks a travel salesperson; managers may continue. **Duplicate as template** copies content, itinerary, media and provider, but leaves prices, capacity, driver and guide empty and asks to confirm the contract.
- **Two-level approval:** Submitted → **Sales Admin review** → **Super Admin review** → Approved. Two different people must approve, and nobody may review a package they created, own or submitted (Super Admin included). Reject and Request changes need a reason and send the version back to its creator to fix and resubmit. Every decision is kept, never edited.
- **Versions and change control:** an approved version is never changed. Editing a price, provider, contract, capacity, itinerary, cancellation/refund policy, inclusions/exclusions, days, nights or destination creates the next version (v1.0 → v1.1), shows **Approval required**, and must be approved again; **the approved version keeps selling until then**. Changes to wording such as the overview or highlights become a new version applied at once and logged as non-material. A version under review cannot be edited until it is withdrawn.
- **Approved is not Published.** Once approved, the owner (or a manager) marks it **Published** and records the channel (e.g. tourlast.com, Instagram, partner site) and optional link; publishing on that channel itself is done by the salesperson. Before publishing the Hub checks: provider active, contract active and not expired, contract cancellation terms, provider contact details, adult price, cancellation policy. Anything missing **blocks** publishing and is listed. Only a **Super Admin** can override, with a written reason. Unpublish and Archive are recorded.

### 4.6 Inventory, bookings and clients

- **Departures** (Inventory) are dated runs of an approved package with a capacity. **Sold** = travelers on confirmed or completed bookings; **reserved** = travelers on pending bookings whose hold hasn't expired; **available** = capacity − sold − reserved. Nearly full (80%+) and Full are worked out; Closed and Cancelled are set by hand. Capacity can't go below what is sold and reserved. "View bookings" lists each client and their slots ("8 / 12 sold").
- **Overbooking is refused** unless a Sales Admin has allowed it on that departure. The check locks the departure while booking, so two people can't take the last seats at once.
- **Bookings** (reference `TB-YYYY-NNNN`): package → departure → client → adults/children/infants, requirements, dietary needs, optional emergency contact, optional **influencer code**. The price comes from the live version (managers may override with a reason). The booking records which version was sold.
- A new booking is **Pending** and holds its slots for 48 hours (`TRAVEL_RESERVATION_HOLD_HOURS`); an hourly job cancels unpaid expired holds. Confirm, Complete and No-show follow.
- **Clients** are matched by phone or email, so the same person is never entered twice. Contact details are masked for anyone who is not the booking's salesperson, the package owner, a Travel manager or Accounts.
- Travel salespeople see their own sales and bookings on their own packages; Sales Admin, Super Admin and Accounts see all.

### 4.7 Drivers, guides and the pre-trip checklist

- Drivers (vehicle, registration, licence) and guides (languages, specialisation) can be assigned to the **package**, a **departure** or a **booking**; the most specific one wins. A guide can be marked as not required.
- **Clash detection:** a driver or guide already on an overlapping trip is refused unless a manager overrides (logged).
- **Pre-trip checklist** on confirmed bookings: booking confirmed, payment confirmed, customer contacted, travel details sent, pickup confirmed, driver assigned, guide assigned, emergency contact confirmed, reminder sent, trip completed. Several items tick themselves (status, payment, assignments). Bookings within 7 days with open items show **Pre-trip action required**; a daily 08:00 job alerts about trips in the next 3 days missing a driver, guide or other action.
- The Hub is not the operations system: these records are kept so an operations module can take over the trip later.

**Confirmation ticket.** Every booking has a ticket (booking page → **View ticket**): a compact voucher with the package, provider, date, pick-up time and place, duration, guests, lead guest, driver, guide, payment status and the booking reference, plus a QR code.
- It is rendered live from the booking (no copies are stored), so it always shows the latest details. The package image comes from the Media Gallery (primary image), never a new upload.
- Actions: **Download PDF** (one landscape A5 page), **Print** (prints only the ticket), **Copy verification link**, **Share** (the phone's share sheet), **WhatsApp** (opens a chat with the client and the link) and **Email to client** (sends the PDF to the client's email). Email and share are only offered once the booking is confirmed; a pending booking shows a preview marked as such.
- The QR code opens `/booking/verify/{token}`: a public page that shows only the reference, package, provider, date, guest count and the **live** status. A cancelled booking shows as cancelled there, even if someone still has an old PDF. The token is random (32 characters), so bookings can't be found by guessing ids or references; the page is rate-limited and never shows names, contacts, amounts or notes.
- Who can open a ticket: the same people who can open the booking.

### 4.8 Cancellations and refunds

- **Cancellation request** (owner or manager): reason, the package's cancellation policy at that moment, and a suggested refund up to what was paid.
- **Decision** by a Sales Admin (or Super Admin); nobody decides their own request except a Super Admin. Approving cancels the booking and frees its slots; a refund above zero becomes an approved refund. Rejecting leaves the booking as it was.
- **Refunds** can also be requested without cancelling (partial refunds), with the same approval.
- **Paying out** is done by **Accounts**: Processing → Completed (method and M-Pesa/bank reference required) or Failed (reason). Only completed refunds reduce what the booking has paid. Salespeople can never approve or complete a refund.

### 4.9 Payments (M-Pesa Daraja)

Package payments come into the Hub's own paybill:

- **Request to phone (STK push):** on a booking, the salesperson (or Accounts) enters the client's phone and an amount up to the balance. The client approves on their phone; Safaricom confirms to the Hub and the booking updates by itself.
- **Paybill:** the client pays the paybill with the **booking reference as the account number** (e.g. `TB-2026-0042`). The Hub matches it automatically. A payment that matches no booking goes to **Unmatched M-Pesa**, where Accounts allocate it.
- **Cash, bank or card:** a salesperson can log it, but it only counts once **Accounts confirms** it (or rejects it with a reason).
- M-Pesa payments can never be edited or deleted by anyone. Every Safaricom message is stored as received (Callbacks log). A receipt number is only ever used once.
- A booking's paid amount and **payment status** (Unpaid, Partially paid, Paid, Partially refunded, Refunded) are always worked out from its payments and refunds, never typed in.
- Requests that get no answer are checked with Safaricom every 5 minutes (after 3 minutes); after an hour without an answer they are marked failed.
- **Test mode** (`MPESA_DRIVER=sandbox`, the default): nothing reaches Safaricom; the booking page has *Simulate customer paying / cancelling* buttons and a banner says no real money moves.

### 4.10 Influencer referral codes and commission

Travel salespeople have their own referral module, separate from the property referral link:

- **Influencers** (name, platform, handle, contacts, payout method; payout details encrypted and shown only to the owner, Travel managers and Accounts).
- **Codes** such as `AMINA10` (4–20 letters, numbers or dashes, unique), each with its **commission terms**: percentage of the booking or a fixed amount per booking, what it applies to (packages, flights or both), the **number of bookings** that earn, and the **period** (start and optional end). Codes can be paused, resumed or ended. Once a code has earned anything its terms are locked; create a new code for new terms.
- **How commission is earned:** a booking made with a running code during its period earns one commission line, up to the booking limit. The line is **Pending** until the booking is fully paid, then **Payable**. A cancelled, no-show or fully refunded booking cancels the line (and frees a slot under the limit); a partly refunded booking earns on what was kept. Flight bookings earn when the Flights system reports them paid and a promo code matches.
- The **Influencers table** shows one row per code: influencer, code, created by, terms, applies to, period, limit, bookings used/left, revenue, commission pending/payable/paid, status, with Excel export.
- **Paying influencers:** Accounts or a Travel manager selects payable lines and marks them paid with a reference. Lines are never deleted.

### 4.11 Flights (read-only)

- **Tourlast Flights Super Admin is the source of truth.** The Hub keeps a read-only copy for sales: bookings, passengers, segments, amounts, booking/payment/cancellation/refund status (exactly as the Flights system names them), booking date, salesperson and promo code.
- Nothing in the Hub can change a flight booking, payment, cancellation or refund. Every booking has **Open in Flights Admin**.
- A booking is credited to the travel salesperson whose Hub email matches the booking's `agent_reference`.
- Every flights page shows when data was last synced and from where; if the sync is late or failing it says **"Flight data synchronization delayed"** instead of presenting old data as live. Managers get one alert per hour while it fails.
- Customer email and phone are masked unless you are the credited salesperson or a Travel manager; markup is shown only to Sales Admin, Super Admin and Accounts.
- Until the Flights team connects (section 8.6) the Hub shows **test data**, clearly labelled.

### 4.12 Targets, dashboard, calendar and follow-ups

- **Travel targets** are set by a Sales Admin per salesperson per month: flight bookings, flight revenue, tour & experience bookings, tour & experience revenue. Months before last month are locked. Flight bookings count on their booking date (cancelled excluded); package bookings count when confirmed or completed.
- **My sales actions** on the dashboard lists, most urgent first: overdue and upcoming follow-ups, packages to submit or fix, packages awaiting your approval, contract renewals, bookings to confirm before their hold runs out, and pre-trip confirmations.
- **Calendar and follow-ups** reuse the Hub's calendar. A travel salesperson schedules against a provider, package, booking, client or flight booking, with travel types (customer follow-up, flight follow-up, customer meeting, provider meeting, contract meeting, package review, pre-trip briefing, partner check-in, call, WhatsApp, email). Marking one done keeps the outcome on the item and can book the next follow-up on the same record. Departures appear on the calendar.

### 4.13 Travel reports and the audit log

- **Travel reports** (travel salespeople: their own figures; Sales Admin, Super Admin, Accounts: everyone or one salesperson) for this month, last month, quarter, year or custom dates:
  - Flights: bookings, revenue, markup (financial roles), passengers, cancellations, refunds by status, routes, airlines.
  - Tours: bookings, revenue, slots sold, collected, cancellations, refunds paid, package performance (with cancellation rate), destinations.
  - Providers: active, new, contracts active/expiring/expired, provider performance.
  - Approvals: pending, approved, rejected, changes requested, average approval time.
  - Salespeople: flight and tour bookings and revenue against targets.
  - **Export Excel** downloads every section as one workbook.
- **Audit log** (**Admin → Audit log**, Sales Admin and Super Admin): every important Travel Sales change — packages created, edited, submitted, approved, rejected, published; contracts and providers changed; prices, availability, drivers and guides changed; payments, refunds, allocations; flight bookings, cancellations and refunds synced; targets — with who, when, and before → after values. Entries cannot be edited or deleted.

---

## 5. Notifications and Smart Alerts

- **Announcements** can be written by admins, Sales Managers, HR and Finance, targeted by role, and marked important. Salespeople read only.
- **Smart Alerts** appear in the bell and on the Notifications page. They go to management (Super Admin, Sales Admin, Sales Manager) and, where relevant, to the salesperson concerned.

| Alert | Sent when |
|---|---|
| New property referred / New onboarding submitted | A tourlast.com signup arrives |
| Partner approved / Partner rejected | Its tourlast.com status changes |
| Property inactive | A live partner is removed on tourlast.com |
| Property deleted / restored | tourlast.com removes a property, or brings it back |
| First booking received | tourlast.com reports its first booking (needs `first_booking_at`, section 8) |
| Deal won / Deal lost | A lead becomes Onboarded / is marked Lost |
| Contract expiring | An incentive agreement ends within 30 days (daily) |
| Follow-up overdue | A scheduled item is past due (daily) |
| Payment details changed | To HR and Finance only |
| Possible duplicate property | A lead overlaps someone else's active engagement |
| Ownership transferred | A lead or property is handed to someone |
| Re-engagement due | A lost/paused registry property's date arrives |
| Account suspended or fired | An account's status changes |

**Travel Sales alerts** go to Sales Admin, Super Admin and the travel salesperson concerned (never to Sales Managers or HR), or to the people who must act:

| Alert | Sent when | To |
|---|---|---|
| Package submitted / approved / rejected / changes requested / published | A package moves through approval | The next approver; the creator |
| Contract expiring | 30, 14 and 7 days before, and on expiry (daily) | Travel managers, provider owner |
| Departure nearly full / fully booked | A booking crosses 80% or 100% (once each) | Travel managers, package owner |
| New package booking, booking cancellation | A booking is made or cancelled | Travel managers, salesperson |
| Refund | A refund is requested (to approvers) or approved (to Accounts) | Sales Admin / Accounts |
| Payment received / Unmatched M-Pesa payment | M-Pesa money arrives | Salesperson / Accounts |
| Trip has no driver / guide, Pre-trip action required | Trips in the next 3 days (daily 08:00) | Salesperson, Travel managers |
| Flight data delayed | The flights sync fails (at most hourly) | Travel managers |
| Influencer commission | Commission is marked paid | The influencer's salesperson |

Managers also receive a weekday **07:30 email summary**: who is inactive, which signups are stalled, and who is behind target.

---

## 6. Reports and exports

| Report | Where | Who |
|---|---|---|
| Partner Register (Excel + branded PDF) | Partners & pay → Partner Register | Admins, HR, Accounts |
| Property Engagement Registry (Excel) | Registry → Export Excel | Managers |
| Property Engagement Report (PDF, chosen period) | Registry → Generate report | Managers |
| Payout statements (PDF per person, Excel batch) | Payouts | Admins, HR, Accounts |
| Lost & objections | Management → Lost & objections | Managers |
| Travel report (Excel, all sections) | Travel Sales → Travel reports | Travel salespeople (own), Sales Admin, Super Admin, Accounts |
| Influencer codes (Excel) | Travel Sales → Influencers | Travel salespeople (own), Travel managers, Accounts |

All exports respect the filters currently applied on screen.

---

## 7. Automated jobs

Defined in `routes/console.php`. They require the Laravel scheduler to run every minute on the server (section 10).

| Time | Command | What it does |
|---|---|---|
| Every 10 min | `hub:sync-tourlast` | Reads recently changed providers from tourlast.com |
| 02:00 daily | `hub:sync-tourlast --full` | Full re-check of all referred providers |
| 06:00 daily | `hub:reinstate-suspensions` | Lifts suspensions whose end date has arrived |
| 06:45 daily | `hub:process-reengagements` | Moves due registry properties to Re-engage and books follow-ups |
| 07:00 daily | `hub:send-daily-alerts` | Contract-expiry and overdue follow-up alerts |
| 07:30 weekdays | `hub:send-manager-alerts` | Managers' summary email |
| 1st of month, 06:00 | `hub:generate-statements` | Draft payout statements for the previous month |
| Every 5 min | `travel:mpesa-reconcile` | Asks Safaricom about payment requests with no answer; fails them after an hour |
| Every 10 min | `travel:sync-flights` | Reads changed flight bookings from Flights Super Admin (skipped when `FLIGHTS_SOURCE=push`) |
| Hourly | `travel:expire-holds` | Cancels unpaid pending package bookings whose hold ran out |
| 07:10 daily | `travel:contract-alerts` | Provider contract expiry alerts |
| 08:00 daily | `travel:pretrip-reminders` | Missing driver/guide and pre-trip alerts for the next 3 days |

A **queue worker** must also run to send emails. Every command can be run by hand, e.g. `php artisan hub:sync-tourlast --full`.

---

## 8. Integration: what is connected and what is not

### 8.1 Status

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
| **Tourlast Flights Super Admin** → flight bookings | ⏳ **Connection points built; Flights team to connect** (Hub shows test data meanwhile, 8.6) | Flights dev |
| **M-Pesa Daraja** for package payments | ⏳ **Built and tested in test mode; live credentials and paybill needed** (8.7) | Tourlast finance + DevOps |
| Everything else (registry, leads, schedule, incentives, claims, reports, Travel Sales packages and bookings) | ✅ Self-contained in the Hub | — |

The Hub reads real data only (`TOURLAST_SOURCE=api`, the default). Local development can use **sandbox** mode (`TOURLAST_SOURCE=sandbox`, local and test environments only, refused in production): **Admin → Integration** has a simulator that creates sample signups and moves them through each status, using exactly the same code path as real data.

### 8.2 What the tourlast.com developer must do

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

### 8.3 Data the Hub reads per provider

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
| `account_id`, `legal_name`, `category`, `inventory_count` | For incentives | See 8.2 step 5 |
| `first_booking_at` | Optional | Enables the "First booking received" alert |

### 8.4 The Sales Hub API

Everything in the Hub is also available through a REST API at `https://sales-hub.tourlast.com/api/v1`, documented in **[API.md](API.md)** with a machine-readable OpenAPI 3.1 file at `docs/api/openapi.json` (regenerate with `php artisan hub:api-spec`).

- **Tokens:** people sign in with `POST /auth/tokens`; systems get tokens from **Admin → API tokens** (Super Admin, Sales Admin). Everyone can see and revoke their own tokens on their profile.
- **Scopes plus permissions:** each token is limited to scopes (e.g. `leads:read`, `registry:write`) and can never do more than its owner can in the Hub. Suspending or firing someone stops their tokens immediately.
- **Same rules as the web app:** duplicate protection (`409`), required lost reasons, append-only history and role permissions all apply.
- **tourlast.com push:** `php artisan hub:generate-token` writes one shared secret into the Hub's `.env` as `TOURLAST_API_TOKEN` and prints it once — put the same value in each source app's `.env` as `TOURLAST_HUB_TOKEN`. It authenticates `POST /integrations/tourlast/providers` and `GET /integrations/tourlast/ref-codes` (no account, role or scope is created); set `TOURLAST_SOURCE=push`.
 - Rate limit: 120 requests per minute per user (per IP address on the shared-token feed).

### 8.5 Testing the connection

1. Set the `.env` values (section 9), run `php artisan config:clear`.
2. Run `php artisan hub:sync-tourlast --full`. It prints how many records were read, created, updated and deleted, or the exact error.
3. Open **Admin → Integration** to see the sync log and latest status changes.
4. Visit `https://sales-hub.tourlast.com/r/<real code>`, complete a test signup, approve it and make it live on tourlast.com. Within 10 minutes it appears under that salesperson's **My Onboardings** and counts on **My Progress**.

### 8.6 Tourlast Flights Super Admin (flight bookings)

The Hub only **reads** flight bookings; Flights Super Admin stays the source of truth. Today `FLIGHTS_SOURCE=sandbox` fills the Flights pages with clearly labelled test data. The Flights developer connects in one of two ways:

**Option A — the Hub pulls** (`FLIGHTS_SOURCE=api`)

1. Flights Super Admin exposes `GET {FLIGHTS_API_URL}/bookings?updated_since=<ISO 8601>&page=<n>`, protected by a bearer token, returning `{"data": [ …bookings… ], "meta": {"next_page": 2}}` (or `last_page`).
2. Set `FLIGHTS_API_URL`, `FLIGHTS_API_TOKEN` (and optionally `FLIGHTS_API_TIMEOUT`) in the Hub's `.env`.
3. The Hub syncs every 10 minutes (`travel:sync-flights`; run `php artisan travel:sync-flights --full` once to load history). A failed run changes nothing and alerts Travel managers.

**Option B — Flights pushes** (`FLIGHTS_SOURCE=push`)

1. On the Hub server run `php artisan travel:create-flights-account`. It creates an account with no role that can only push flight bookings, and prints a token (scope `flights:push`). `--rotate` replaces the token.
2. Flights Super Admin sends `POST https://sales-hub.tourlast.com/api/v1/integrations/flights/bookings` with `Authorization: Bearer <token>` and `{"bookings": [ … ]}` (up to 500) or `{"booking": { … }}` whenever a booking is created or changes. The reply counts created, updated, unchanged and failed records.
3. In push mode the scheduled pull is skipped; pages warn if nothing has arrived for 24 hours.

**The booking record** (both options) uses snake_case keys. Required: `external_id`, `booked_at`, `booking_status`. The full shape — references, customer, airline, route, segments, passengers, amounts, markup, payment/cancellation/refund fields, `agent_reference` (the travel salesperson's Hub email, for credit), `promo_code` (influencer code) and `updated_at` — is documented in [API.md](API.md) and in `app/Integrations/Flights/FlightRecord.php`. Statuses are stored exactly as sent (lower-cased); the Hub's refund report groups `pending`, `requested`, `processing`, `in_progress`, `submitted` as open and `completed`, `refunded`, `paid`, `processed` as done, so tell the Hub team if Flights uses other words.

Set `FLIGHTS_ADMIN_BOOKING_URL` (e.g. `https://admin.flights.tourlast.com/bookings/{id}`) so **Open in Flights Admin** goes to the right page.

### 8.7 M-Pesa Daraja (package payments)

Built and tested end to end in test mode (`MPESA_DRIVER=sandbox`: nothing reaches Safaricom). To go live:

1. **Get a paybill or till for the Hub.** Safaricom accepts **one set of confirmation URLs per shortcode**. If Tourlast's existing paybill already sends its notifications to tourlast.com, the Hub needs its **own paybill or till**, or tourlast.com must forward the paybill messages to the Hub's URLs.
2. **Create a Daraja app** on developer.safaricom.co.ke with *Lipa na M-Pesa Online* (STK push) and *C2B*. Test against Safaricom's sandbox first (`DARAJA_ENVIRONMENT=sandbox`, shortcode `174379`), then request production access (Go Live) for the real shortcode.
3. **Set `.env`:** `MPESA_DRIVER=daraja`, `DARAJA_ENVIRONMENT` (`sandbox` or `production`), `DARAJA_CONSUMER_KEY`, `DARAJA_CONSUMER_SECRET`, `DARAJA_SHORTCODE`, `DARAJA_SHORTCODE_TYPE` (`paybill` or `till`; for a till also `DARAJA_TILL_NUMBER`), `DARAJA_PASSKEY`, and a long random `DARAJA_CALLBACK_SECRET` (callbacks are refused without it in production). Optionally `DARAJA_ALLOWED_IPS` (Safaricom's published IP addresses) and `DARAJA_CALLBACK_BASE_URL` if it differs from `APP_URL`.
4. **Public HTTPS is required.** Safaricom calls `https://sales-hub.tourlast.com/api/daraja/{secret}/stk`, `…/c2b/validation` and `…/c2b/confirmation`. They cannot reach a local machine.
5. **Register the paybill URLs once:** `php artisan travel:mpesa-register-urls` (prints the URLs and registers them with Safaricom in live mode). Re-run it if the secret or domain changes.
6. **Test with a small real payment:** request a payment to your own phone from a test booking, and pay the paybill with a booking reference as the account number.

Refunds are paid out by Accounts outside the Hub (M-Pesa or bank) and recorded with their reference. Automatic M-Pesa refunds (Daraja B2C) are not built (section 11).

---

## 9. Configuration

### 9.1 Environment settings (`.env`)

`.env.example` lists every setting with safe defaults. Values marked **must set** have no usable default in production.

**Application**

| Setting | Production value | |
|---|---|---|
| `APP_ENV` | `production` | must set |
| `APP_DEBUG` | `false` | must set |
| `APP_KEY` | generated by `php artisan key:generate` | must set |
| `APP_URL` | `https://sales-hub.tourlast.com` | |

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

**Travel Sales: flights** (section 8.6)

| Setting | Default | Meaning |
|---|---|---|
| `FLIGHTS_SOURCE` | `sandbox` | `sandbox` (test data) · `api` (Hub pulls) · `push` (Flights pushes to the Hub API) |
| `FLIGHTS_API_URL`, `FLIGHTS_API_TOKEN`, `FLIGHTS_API_TIMEOUT` | empty, empty, `20` | Flights Super Admin bookings endpoint (Option A) |
| `FLIGHTS_ADMIN_BOOKING_URL` | `https://admin.flights.tourlast.com/bookings/{id}` | Link behind **Open in Flights Admin** |
| `FLIGHTS_STALE_AFTER_MINUTES` | `30` | After this, pages show `Flight data synchronization delayed` |

**Travel Sales: M-Pesa and bookings** (section 8.7)

| Setting | Default | Meaning |
|---|---|---|
| `MPESA_DRIVER` | `sandbox` | `sandbox` (simulated, no money moves) · `daraja` (real Safaricom calls) |
| `DARAJA_ENVIRONMENT` | `sandbox` | Safaricom's `sandbox` or `production` |
| `DARAJA_CONSUMER_KEY`, `DARAJA_CONSUMER_SECRET`, `DARAJA_PASSKEY` | empty | From the Daraja app — **must set** for live |
| `DARAJA_SHORTCODE`, `DARAJA_SHORTCODE_TYPE`, `DARAJA_TILL_NUMBER` | `174379`, `paybill`, empty | The Hub's paybill or till |
| `DARAJA_CALLBACK_SECRET` | empty | Secret part of the callback URLs — **must set** for live |
| `DARAJA_CALLBACK_BASE_URL` | `APP_URL` | Public HTTPS address Safaricom calls |
| `DARAJA_ALLOWED_IPS` | empty (any) | Comma-separated Safaricom IPs to accept callbacks from |
| `DARAJA_STK_TIMEOUT_MINUTES` | `3` | When an unanswered payment request is checked with Safaricom |
| `TRAVEL_RESERVATION_HOLD_HOURS` | `48` | How long a pending package booking holds its slots |

After changing `.env` in production, run `php artisan optimize` (or `config:clear`).

### 9.2 Settings in code (change with a deploy)

| File | Holds |
|---|---|
| `config/hub.php` | Property types, accommodation types, contact job titles, star ratings, competitor suggestions, target lock day, stalled/inactive thresholds |
| `config/tourlast.php` | tourlast.com status and type value maps, column defaults |
| `config/incentives.php` | Claim approval chains, upload size and file types, transport cap |
| `config/travel.php` | Flights and M-Pesa connection, currency, contract alert days (30/14/7), nearly-full threshold (80%), booking hold |
| `app/Enums/Travel/*.php` | Travel provider types and statuses, contract, package, version, booking, payment, refund, trip and influencer statuses |
| `app/Enums/*.php` | Roles and permissions, registry stages/statuses/sources, objections, activity types, account statuses |
| `incentive_policies` table | Schedule 1 pay tables (points bands, retainers, bonuses) |

---

## 10. Deployment and go-live

Step-by-step server setup is in **[DEPLOYMENT_AWS.md](DEPLOYMENT_AWS.md)**. The essentials:

**Infrastructure**

| Piece | Suggested |
|---|---|
| Web server | EC2 or Lightsail, PHP 8.3+ (`pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `gd`, `intl`, `bcmath`), Nginx, Composer; Node 20+ for building assets |
| Database | RDS for MySQL 8 with automated backups |
| Email | Amazon SES (verify tourlast.com) or company SMTP |
| DNS / HTTPS | `sales-hub.tourlast.com` → server; Let's Encrypt or ACM |

**First deployment**

```bash
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate      # then edit .env (section 9)
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan hub:create-super-admin   # prompts for name, email, password
php artisan storage:link
php artisan optimize
```

Never run the plain `db:seed` in production (demo data only seeds when `APP_ENV=local`).

`php artisan storage:link` is required: Media Gallery images and profile photos are served from `storage/app/public`. For video uploads in the Media Gallery, set PHP's `upload_max_filesize` and `post_max_size` to at least **50M** (and Nginx `client_max_body_size 50m`).

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

- [ ] `https://sales-hub.tourlast.com/up` returns 200
- [ ] Super Admin can sign in; an invitation email arrives from sales@tourlast.com
- [ ] `php artisan hub:sync-tourlast --full` succeeds against tourlast.com
- [ ] A real `/r/<code>` link lands on tourlast.com with `?ref=` attached, and a test signup reaches the salesperson within 10 minutes
- [ ] Scheduler cron and queue worker are running (`php artisan schedule:list` shows the jobs in section 7)
- [ ] RDS automated backups are on
- [ ] Sales Admin has reviewed the Schedule 1 pay tables and claim approval chain
- [ ] `FLIGHTS_SOURCE` set (or left on `sandbox` knowingly) and, if connected, `php artisan travel:sync-flights --full` succeeds
- [ ] M-Pesa: live credentials and secret set, `php artisan travel:mpesa-register-urls` run, one real test payment matched to a booking

---

## 11. Pending work and open decisions

### 11.1 Needed before go-live

| # | Item | Owner | What is needed |
|---|---|---|---|
| 1 | Store `ref` codes on tourlast.com | tourlast.com dev | Section 8.2 steps 1–2 |
| 2 | Connect tourlast.com | tourlast.com dev | Hub reads (database view or JSON API), or tourlast.com pushes to the Hub API with the shared sync token (8.4) |
| 3 | Confirm status/type values | tourlast.com dev | Update `config/tourlast.php` maps if needed |
| 4 | Server, database, DNS, HTTPS | DevOps | Section 10 |
| 5 | Email sending | DevOps | SES (+ `composer require aws/aws-sdk-php`, domain verification) or SMTP |
| 6 | Scheduler and queue worker | DevOps | Cron + Supervisor (section 10) |
| 7 | Production `.env` | DevOps | Section 9.1 |
| 8 | Connect Flights Super Admin | Flights dev | Section 8.6 (pull or push); until then flights pages show test data |
| 9 | M-Pesa paybill/till and Daraja credentials | Tourlast finance + DevOps | Section 8.7; confirm whether the Hub gets its own shortcode |
| 10 | Staging test on the real domain | Development + Sales Admin | Full walk-through with real accounts before inviting the team |

### 11.2 Recommended soon after go-live

| # | Item | Owner | Notes |
|---|---|---|---|
| 11 | Partner Account fields from tourlast.com | tourlast.com dev | `account_id`, `legal_name`, `category`, `inventory_count` — removes manual inventory entry |
| 12 | `first_booking_at` from tourlast.com | tourlast.com dev | Enables the "First booking received" alert |
| 13 | Webhook for instant updates | tourlast.com dev | Optional; sync already runs every 10 minutes |
| 14 | File storage on S3 | DevOps | Photos, receipts and evidence are on the server disk (`storage/app`); move to S3 if the server is replaced or scaled |
| 15 | Official logo file | Tourlast | The Hub uses a vector redraw of the logo; supply the original SVG/PNG for `public/images/` |
| 16 | Set `HUB_TRANSPORT_MONTHLY_CAP` | Management | Empty means no cap |

### 11.3 Open product decisions

| # | Question | Current behaviour |
|---|---|---|
| 17 | Should a **fired salesperson's referral link** stop crediting new signups? | It keeps working and crediting them |
| 18 | Should marking a **lead Lost** also update its linked **registry record**? | No — managers record the registry outcome (keeps the registry manager-owned) |
| 19 | Should **tourlast.com signups automatically create registry records**? | No — managers add records; signups are linked when they match |
| 20 | **Reminders before meetings** (e.g. 30 minutes before)? | Not built; overdue items are alerted daily |
| 21 | Lead pipeline stages **Proposal** and **Negotiation**? | Lead statuses are New, Contacted, Meeting, Link sent, Onboarded, Lost; the registry has the full stage list |
| 22 | Should HR get a read-only view of account history for all staff? | HR sees it per person under People |
| 23 | **Automatic M-Pesa refunds** (Daraja B2C)? | Not built: Accounts pays refunds outside the Hub and records the reference. B2C needs separate Safaricom credentials and approval |
| 24 | **Publishing packages to tourlast.com** automatically? | Manual by design: the salesperson publishes on the chosen channel and records it in the Hub; no package API to tourlast.com |
| 25 | **Commission scheme for travel salespeople** themselves? | None defined; travel salespeople earn no Schedule 1 points. Targets and sales figures are tracked |
| 26 | **Customer reviews and provider ratings** after a trip? | Not built; incidents are logged. Room is left to add feedback on completed bookings |
| 27 | **Flight status words** | The Hub shows Flights Super Admin's own status names; the open/done refund grouping (8.6) must be confirmed against the real API |
| 28 | ~~Should Accounts see the **Bookings** page in their menu?~~ | Done: Accounts now have **Bookings** in their Travel Sales menu (read-only, with payments and refunds) |

---

## 12. Developer guide

### 12.1 Local setup

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate      # APP_ENV=local, TOURLAST_SOURCE=api
php artisan migrate --seed                            # roles and policy only; demo data needs APP_ENV=local + TOURLAST_SOURCE=sandbox (password "password")
php artisan test                                      # 630+ tests
```

Demo accounts: `admin@` (Super Admin), `grace@` (Sales Admin), `david@` (Sales Manager), `john@`, `mary@`, `peter@`, `james@` (Salespeople), `aisha@`, `kevin@` (Travel Salespeople), `faith@` (HR), `samuel@` (Accounts) — all `@tourlast.test`. Travel demo data (providers, packages in every approval state, departures, bookings, payments, influencer codes, test flights) is seeded by `TravelDemoSeeder`.

### 12.2 Where things live

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
| Travel Sales | Pages `app/Livewire/Travel/*`; actions `app/Actions/Travel/*` (e.g. `CreatePackage`, `ReviewPackage`, `PublishPackage`, `CreatePackageBooking`, `RefreshBookingPayment`); rules `app/Support/Travel/*` (`TravelAccess`, `PublishGate`, `PackageReadiness`, `ResourceSchedule`, `TravelSearch`); routes `routes/travel/*.php`; enums `app/Enums/Travel/*` |
| M-Pesa and Flights connections | `app/Integrations/Mpesa/*`, `app/Integrations/Flights/*`, `config/travel.php`, callbacks in `routes/api/public/daraja.php` |
| Audit log | `App\Support\Audit::record()` writes `audit_events`; page at **Admin → Audit log** |
| Tests | `tests/Feature/*` (Travel Sales under `tests/Feature/Travel`), `tests/Unit/*` |

### 12.3 Principles to keep

- **History is never rewritten.** Points ledger lines, registry events, lead transfers, attribution changes and account status changes are append-only. Correct by adding a new entry.
- **Authorise on the server.** Every Livewire action calls a policy or permission check, not just the view.
- **One source for lists.** Property types, job titles and competitors live in `config/hub.php`; stages, statuses and objections are enums.
- **Duplicates are checked in one place** (`PropertyDuplicateCheck`) — reuse it for any new entry point.
- **Travel: check access through `TravelAccess`** (and `PaymentAccess` / `InfluencerAccess`) in every action, write important changes with `Audit::record()`, and never set a booking's paid amount or payment status directly — call `RefreshBookingPayment`.
- Run `vendor/bin/pint --dirty` before committing and keep `php artisan test` green.

### 12.4 Common changes

| To… | Do |
|---|---|
| Add a property type | Add it to `property_types` in `config/hub.php` (and to `accommodation_types` if it has rooms); map tourlast.com's value in `config/tourlast.php` |
| Add an objection or competitor | Add a case to `app/Enums/Objection.php`, or a name to `competitors` in `config/hub.php` |
| Give a role a new ability | Add a case to `Permission`, add it to the role in `Role::permissions()`, deploy, run `RolesAndPermissionsSeeder` |
| Add a Smart Alert | Add the type to `SmartAlert::Types`, then call `Alerts::send()` where it happens |
| Change pay rules | Edit the policy in `incentive_policies` (seeded from `Policy::schedule1()`); the calculator reads it |
| Add a scheduled job | Create a command in `app/Console/Commands`, register it in `routes/console.php` |

---

## 13. Glossary

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
| **Sandbox** | Built-in simulator for local development and tests. Refused in production for tourlast.com; for Flights and M-Pesa it is the clearly labelled test mode used until they are connected. |
| **Package version** | One numbered copy (v1.0, v1.1…) of a package's content and prices. Approved versions never change. |
| **Departure** | A dated run of a package with a fixed number of slots. |
| **Hold** | The time a pending booking keeps its slots (48 hours) before it is cancelled if unpaid. |
| **STK push** | An M-Pesa payment request that pops up on the client's phone. |
| **Paybill (C2B)** | The client pays the Hub's paybill using the booking reference as the account number. |
| **Influencer code** | A referral code a travel salesperson gives an influencer; bookings with it earn commission. |
