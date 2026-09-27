# Tourlast Sales Hub API — v1

> The complete reference for the Tourlast Sales Hub API. A machine-readable OpenAPI 3.1 description of every endpoint is in [`docs/api/openapi.json`](api/openapi.json) (regenerate with `php artisan hub:api-spec`); import it into Postman, Insomnia or Swagger UI.

## Contents

- [Quick start](#quick-start)
- [Authentication and tokens](#authentication-and-tokens)
- [Scopes](#scopes)
- [Requests and responses](#requests-and-responses)
- [Errors](#errors)
- [The rules the API keeps](#the-rules-the-api-keeps)
- [Versioning](#versioning)
- [Your account](#your-account)
- [Option lists](#option-lists)
- [Notifications and announcements](#notifications-and-announcements)
- [Leads and activities](#leads-and-activities)
- [Schedule and calendar](#schedule-and-calendar)
- [Duplicate check](#duplicate-check)
- [Property Engagement Registry](#property-engagement-registry)
- [Onboardings](#onboardings)
- [Partner accounts and points](#partner-accounts-and-points)
- [Earnings and statements](#earnings-and-statements)
- [Claims](#claims)
- [Team and accounts](#team-and-accounts)
- [Admin: API tokens](#admin-api-tokens)
- [Insights and reports](#insights-and-reports)
- [tourlast.com integration](#tourlastcom-integration)

The Sales Hub API gives apps and other systems the same capabilities as the Sales Hub web app: leads and scheduling, the Property Engagement Registry, tourlast.com onboardings, incentives and claims, team management, notifications, reports, and the tourlast.com integration.

| | |
|---|---|
| **Base URL** | `https://sales.tourlast.com/api/v1` |
| **Format** | JSON over HTTPS (`Content-Type: application/json`, `Accept: application/json`); multipart for file uploads |
| **Authentication** | Bearer tokens (`Authorization: Bearer <token>`) |
| **Authorisation** | Token scopes **and** the token owner's permissions in the Hub |
| **Dates** | ISO 8601 with timezone (`2026-09-28T11:30:00+03:00`); dates as `YYYY-MM-DD`; months as `YYYY-MM` |
| **Money** | Numbers in Kenyan shillings (KES) |
| **Rate limit** | 120 requests per minute per user; 10 sign-in attempts per minute |

## Quick start

**1. Get a token** (or ask a Sales Admin to issue one under **Admin → API tokens**):

```bash
curl -X POST https://sales.tourlast.com/api/v1/auth/tokens \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"john@tourlast.com","password":"••••••••","device_name":"John phone","scopes":["profile","leads:read","leads:write"]}'
```

```json
{
  "token": "12|Kx8Hc0tR4mZq9w…",
  "token_type": "Bearer",
  "scopes": ["profile", "leads:read", "leads:write"],
  "expires_at": null,
  "user": { "id": 4, "name": "John Doe", "role": "salesperson", "referral_code": "TL-JOHN-2847" }
}
```

**2. Call the API with it:**

```bash
curl https://sales.tourlast.com/api/v1/me/dashboard \
  -H "Accept: application/json" -H "Authorization: Bearer 12|Kx8Hc0tR4mZq9w…"
```

**3. Load the option lists once** with `GET /meta` (property types, stages, statuses, objections…), so your forms never hard-code values.

## Authentication and tokens

Every endpoint except `POST /auth/tokens` needs a token. A token:

- **acts as one person.** It can never do more than that person can in the Hub. If the person is suspended or fired, every token they own stops working immediately (`403` with the reason).
- **is limited to scopes** chosen when it is created.
- **may expire** (`expires_at`); expired tokens return `401`.
- **is shown once.** Only a hash is stored; lost tokens must be revoked and replaced.

There are two ways to get one:

| Way | Use for |
|---|---|
| `POST /auth/tokens` with email and password | Apps where a person signs in (mobile app, internal tools). |
| **Admin → API tokens** in the Hub (Super Admin, Sales Admin) | Systems: tourlast.com, reporting tools, other internal systems. Create a dedicated service account, then issue it a token with only the scopes it needs. |

People can see and revoke tokens issued as them on their Hub profile, or with `GET /auth/tokens` and `DELETE /auth/tokens/{id}`.

### POST /auth/tokens

**Scope:** none (public, rate limited)

| Name | Type | Required | Description |
|---|---|---|---|
| `email` | string | yes | Hub account email |
| `password` | string | yes | Hub password |
| `device_name` | string | yes | Shown to the person in their token list, e.g. "John phone" |
| `scopes` | string[] | no | Defaults to every scope. Ask only for what you need. |
| `expires_in_days` | integer | no | 1–365. Omit for no expiry. |

Returns `201` with `token`, `scopes`, `expires_at` and `user`. Wrong credentials or a suspended/fired account return `422`.

### GET /auth/tokens · DELETE /auth/tokens/current · DELETE /auth/tokens/{id}

**Scope:** any token. List your tokens (never the secrets), sign out the current token, or revoke another of your tokens.

## Scopes

| Scope | Allows |
|---|---|
| `profile` | Own profile, dashboard, referral link, target, payment details, earnings |
| `leads:read` / `leads:write` | Read leads and activities / create, update, log activity, mark lost, transfer |
| `schedule:read` / `schedule:write` | Read the calendar / schedule, edit, complete, remove items |
| `registry:read` / `registry:write` | Search and read the registry / add and change records (managers) |
| `onboardings:read` / `onboardings:write` | Read tourlast.com signups / assign unattributed signups (admins) |
| `incentives:read` / `incentives:write` | Earnings, partner accounts, statements / verify accounts, approve and pay statements |
| `claims:read` / `claims:write` | Read claims and approvals / submit, approve, reject, disburse |
| `team:read` / `team:write` | People, performance, targets / invite, suspend, fire, reinstate, delete, API tokens |
| `notifications:read` / `notifications:write` | Notifications and announcements / mark read, publish |
| `reports:read` | Insights and Excel/PDF exports |
| `integration:read` / `integration:push` | tourlast.com sync status / send provider records (tourlast.com) |

**Scope plus permission.** A token with `registry:write` held by a salesperson still gets `403` on registry writes, because salespeople cannot change the registry in the Hub. The roles and what each can do are listed in the System Guide (`docs/SYSTEM_GUIDE.md`, section 2).

### Suggested scope sets

| Integration | Scopes |
|---|---|
| tourlast.com | `integration:push` on the integration account (`php artisan hub:create-integration-account`) |
| Reporting / BI (read only) | `registry:read`, `onboardings:read`, `incentives:read`, `team:read`, `reports:read` |
| Salesperson mobile app | `profile`, `leads:*`, `schedule:*`, `registry:read`, `onboardings:read`, `incentives:read`, `claims:*`, `notifications:*` |

## Requests and responses

**Single records** are wrapped in `data`:

```json
{ "data": { "id": 81, "business_name": "ABC Hotel", "status": "meeting" } }
```

**Lists** are paginated. Use `?page=` and `?per_page=` (default 25, maximum 100):

```json
{
  "data": [ { "id": 81, "business_name": "ABC Hotel" } ],
  "links": { "first": "…?page=1", "last": "…?page=4", "prev": null, "next": "…?page=2" },
  "meta": { "current_page": 1, "per_page": 25, "total": 92, "last_page": 4 }
}
```

- Field names are `snake_case`. Enumerated values (statuses, stages, types) are returned as `value` plus a human `…_label` where useful; the full lists come from `GET /meta`.
- `POST` that creates something returns `201` with the new record.
- Actions (approve, transfer, mark lost…) return the updated record or `{"message": "…"}`.
- Files (Excel, PDF, attachments) are returned as downloads with a `Content-Disposition` header.

## Errors

| Status | Meaning | Body |
|---|---|---|
| `401` | Missing, invalid or expired token | `{"message": "Unauthenticated."}` |
| `403` | Token lacks the scope | `{"message": "This token is missing the required scope: leads:write.", "required_scopes": ["leads:write"]}` |
| `403` | The person may not do this, or their account is suspended/fired | `{"message": "…"}` |
| `404` | Record does not exist | `{"message": "…"}` |
| `409` | Possible duplicate property, or a delete blocked by history | `{"message": "…", "matches": […]}` or `{"message": "…", "blockers": […]}` |
| `422` | Validation failed | `{"message": "…", "errors": {"field": ["…"]}}` |
| `429` | Rate limit exceeded; retry after the `Retry-After` header | `{"message": "Too Many Attempts."}` |

## The rules the API keeps

The API runs the same business rules as the web app, so integrations cannot bypass them:

- **Duplicate protection.** Creating a lead or registry record that looks like an existing property returns `409` with the matches. Continue the existing engagement (link to it) or resend with `confirm_different: true`; management is alerted to overrides.
- **Lost needs a reason.** Marking a lead or registry record lost requires an objection (and a competitor for OTA/competitor objections).
- **History is append-only.** Registry timelines, transfers, points and account history are never edited or deleted through the API.
- **Ownership and permissions.** Salespeople only see and change their own leads and schedule; managers see the team; registry writes, transfers and account actions are manager/admin only.

## Versioning

This is version 1 (`/api/v1`). Additive changes (new endpoints, new fields) can happen within v1; build clients to ignore unknown fields. Breaking changes will ship as `/api/v2`, with v1 kept running during a transition period.

---

# Endpoint reference

## Your account

### GET /me
**Scope:** `profile` · **Who:** anyone

The token owner: profile, role, account status, referral code and link, Hub permissions and the current token's scopes.

### PATCH /me
**Scope:** `profile` · **Who:** anyone

| Name | Type | Required | Description |
|---|---|---|---|
| `name`, `phone`, `job_title` | string | no | Cannot be emptied once set |
| `bio` | string | no | Up to 500 characters |
| `emergency_contact_name`, `emergency_contact_phone` | string | no | |

### GET /me/dashboard
**Scope:** `profile` · **Who:** people who sell

`?period=week|month|quarter|year` (default month). Returns `today` counts (follow-ups, meetings, overdue, onboardings in progress), today's `schedule`, lead `pipeline` by status, performance `metrics`, and a six-month `trend`.

```json
{
  "data": {
    "today": { "follow_ups": 1, "meetings": 2, "overdue": 1, "onboardings": 4 },
    "schedule": [ { "id": 311, "type": "meeting", "title": "Proposal discussion", "due_at": "2026-09-28T11:30:00+03:00", "time_label": "11:30–12:30", "contact_name": "John Wambua", "contact_role": "General Manager", "lead": { "id": 81, "business_name": "ABC Hotel" } } ],
    "pipeline": { "new": 1, "contacted": 2, "meeting": 1, "link_sent": 2, "onboarded": 0, "lost": 1 },
    "metrics": { "target": 31, "points": 29, "onboarded": 7, "awaiting": 11, "clicks": 18 }
  }
}
```

### GET /me/referral
**Scope:** `profile` · **Who:** people who sell

Your referral code and link, and the funnel for `?period=`: `link_visits`, `applications`, `approved`, `live`, `active`, plus `conversion_percent`.

### GET /me/target · PUT /me/target
**Scope:** `profile` · **Who:** people who sell

`GET` returns this month and next month with `target`, `locked` and `locks_on`. `PUT` sets a target:

| Name | Type | Required | Description |
|---|---|---|---|
| `month` | string `YYYY-MM` | yes | This month (until the lock day) or next month |
| `target` | integer | yes | Points, 1–500 |

### GET /me/payment-details · PUT /me/payment-details
**Scope:** `profile` · **Who:** anyone

Returns the method, payee name and a **masked** destination. `PUT` saves new details (HR and Finance are alerted):

| Name | Type | Required | Description |
|---|---|---|---|
| `method` | `mpesa` \| `bank` | yes | |
| `mpesa_phone`, `mpesa_name` | string | for M-Pesa | Kenyan mobile number; name as registered on M-Pesa |
| `bank_name`, `account_number`, `account_name` | string | for bank | |
| `bank_branch` | string | no | |

### GET /me/earnings
**Scope:** `profile` · **Who:** anyone with incentives

`?month=YYYY-MM` (default this month). Approved figures and figures including provisional points: points, retainer, weekly bonuses, monthly bonus, exceptional payment, airtime, transport, adjustments and total in KES, the next pay step, and the month's statement if generated.

## Option lists

### GET /meta
**Scope:** any token

Every option list: scopes, roles, periods, property types, star ratings, contact titles, competitors, lead statuses, activity types, onboarding statuses, registry stages/statuses/sources/log types, objections (with `needs_competitor`), transfer reasons, account statuses, suspension and termination reasons, announcement audiences.

## Notifications and announcements

### GET /notifications
**Scope:** `notifications:read` · **Who:** anyone

`?filter=all|alerts|announcements&limit=50`. Smart Alerts and announcements, newest first, each with a `key`, `unread` flag and `created_at`; `meta.unread` is the unread count.

### POST /notifications/{key}/read · POST /notifications/read-all
**Scope:** `notifications:write` · **Who:** anyone

Mark one item (by `key` from the list, e.g. `a-12` or `n-<uuid>`) or everything as read.

### POST /announcements
**Scope:** `notifications:write` · **Who:** Super Admin, Sales Admin, Sales Manager, HR, Accounts

| Name | Type | Required | Description |
|---|---|---|---|
| `title` | string | yes | Up to 150 characters |
| `body` | string | yes | Up to 5,000 characters |
| `audience` | string[] | yes | Values from `meta.announcement_audiences`, e.g. `["everyone"]` |
| `important` | boolean | no | Highlights the announcement |

---

## Leads and activities

A **lead** is a salesperson's active opportunity with a property. Salespeople see and change only their own leads. Sales Admins, Sales Managers and Super Admins (the *team performance* permission) can read everyone's leads and transfer them, but only the owner can edit a lead, change its status, mark it lost or log activity on it.

Base URL: `https://sales.tourlast.com/api/v1`

### GET /leads

**Scope:** `leads:read` · **Who:** salespeople (own leads); managers with team performance (all leads)

| Name | Type | Required | Description |
|---|---|---|---|
| `status` | string | No | `open` (default: New, Contacted, Meeting, Link sent), `all`, or one status: `new`, `contacted`, `meeting`, `link_sent`, `onboarded`, `lost` |
| `owner` | integer | No | Managers only: one salesperson's user ID |
| `q` | string | No | Searches business name, contact name and location |
| `per_page` | integer | No | 1–100, default 25 |
| `page` | integer | No | Page number |

```json
{
  "data": [
    {
      "id": 214,
      "business_name": "ABC Hotel",
      "trading_name": null,
      "property_type": "hotel",
      "property_type_label": "Hotel",
      "location": "Westlands, Nairobi",
      "contact_name": "Jane Mwangi",
      "contact_role": "General Manager",
      "contact_phone": "+254700000000",
      "contact_email": "gm@abchotel.co.ke",
      "status": "meeting",
      "status_label": "Meeting",
      "is_open": true,
      "last_contacted_at": "2026-09-24T10:15:00+03:00",
      "lost": null,
      "owner": { "id": 7, "name": "John Doe", "avatar_url": null },
      "property_engagement_id": 31,
      "next_follow_up": { "id": 88, "type": "meeting", "title": "Proposal discussion", "due_at": "2026-09-28T11:30:00+03:00", "time_label": "11:30–12:30" },
      "created_at": "2026-09-10T08:02:11+03:00"
    }
  ],
  "links": { "first": "…?page=1", "last": "…?page=3", "prev": null, "next": "…?page=2" },
  "meta": { "current_page": 1, "per_page": 25, "total": 61 }
}
```

Errors: `403` if the account neither sells nor has team performance.

### POST /leads

**Scope:** `leads:write` · **Who:** people who sell (Salesperson, and Sales Admins/Managers with a referral code)

Every new lead goes through the Hub-wide **duplicate rule**. The Hub searches the Property Engagement Registry, every salesperson's leads and tourlast.com signups. If the property looks known, the lead is only created when you either continue the existing engagement (`property_engagement_id`) or confirm it is a different property (`confirm_different: true`). Overlaps with someone else's active work alert management.

| Name | Type | Required | Description |
|---|---|---|---|
| `business_name` | string | Yes | Up to 190 characters |
| `property_type` | string | Yes | A key from `GET /meta` → `property_types` |
| `trading_name` | string | No | |
| `location` | string | No | e.g. `Diani, Kwale` |
| `contact_name`, `contact_role` | string | No | |
| `contact_phone` | string | No | Use the number the provider will sign up with; it links their tourlast.com signup to this lead |
| `contact_email` | string | No | Stored lower-case |
| `website`, `registration_number`, `kra_pin` | string | No | Improve the duplicate check. KRA PIN is stored upper-case |
| `notes` | string | No | Up to 5,000 characters |
| `property_engagement_id` | integer | No | Continue this registry record (the engagement ID from a duplicate match) |
| `confirm_different` | boolean | No | `true` to create despite matches |

Response `201` with the lead (same shape as `GET /leads/{id}`).

When matches are found and neither option is given, `409`:

```json
{
  "message": "This looks like a property Tourlast already knows. Continue the existing engagement (property_engagement_id), or confirm it is a different property (confirm_different: true).",
  "matches": [
    {
      "kind": "registry",
      "key": "registry-31",
      "name": "PrideInn Paradise Beach Resort",
      "location": "Shanzu, Mombasa, Kenya",
      "owner": "Mary Wambua",
      "owner_is_you": false,
      "stage": "Proposal sent · Active",
      "last_contacted": "2026-09-25T00:00:00+03:00",
      "reasons": ["Similar name, same town"],
      "active": true,
      "archived": false,
      "engagement_id": 31,
      "lead_id": null
    }
  ]
}
```

`kind` is `registry`, `lead` or `onboarding`. If a match is your own lead (`owner_is_you: true`), open that lead instead of creating another. `lead_id` is only shown for your own leads, or to managers.

Errors: `403` for accounts that don't sell; `409` duplicates; `422` validation.

### GET /leads/{id}

**Scope:** `leads:read` · **Who:** the owner, or managers with team performance

Returns the lead with its activity history, open schedule items, ownership transfers, lost details, linked registry record and tourlast.com signup.

```json
{
  "data": {
    "id": 214,
    "business_name": "ABC Hotel",
    "status": "lost",
    "lost": {
      "reason": "Already using another OTA (Booking.com)",
      "objection": "other_ota",
      "objection_label": "Already using another OTA",
      "competitor": "Booking.com",
      "notes": "Management renewed contract for another year.",
      "lost_at": "2026-09-26T09:40:00+03:00",
      "reengage_on": "2027-03-01"
    },
    "owner": { "id": 7, "name": "John Doe", "avatar_url": null },
    "property_engagement": { "id": 31, "name": "ABC Hotel", "stage": "proposal_sent", "status": "lost" },
    "onboarding": null,
    "open_schedule": [
      { "id": 102, "type": "follow_up", "title": "Re-engage after loss", "due_at": "2027-03-01T00:00:00+03:00", "has_time": false, "time_label": "Anytime" }
    ],
    "activities": [
      { "id": 55, "type": "meeting", "type_label": "Meeting", "happened_at": "2026-09-24T11:30:00+03:00", "notes": "GM requested a commercial proposal.", "next_action": "Send proposal", "user": { "id": 7, "name": "John Doe" } }
    ],
    "transfers": []
  }
}
```

Errors: `403` someone else's lead; `404` not found.

### PATCH /leads/{id}

**Scope:** `leads:write` · **Who:** the owner only

Send only the fields to change (same fields and rules as `POST /leads`, except `property_engagement_id` and `confirm_different`). Response: the updated lead.

Errors: `403` not the owner; `422` validation.

### POST /leads/{id}/status

**Scope:** `leads:write` · **Who:** the owner only

| Name | Type | Required | Description |
|---|---|---|---|
| `status` | string | Yes | `new`, `contacted`, `meeting` or `link_sent` |

`onboarded` is set automatically when the lead's tourlast.com signup goes live. To mark a lead lost, use `POST /leads/{id}/lost`. Re-opening a lost lead removes its pending "Re-engage after loss" reminder; the objection stays on record.

Errors: `403` not the owner; `422` for `onboarded`, `lost`, or a lead that is already onboarded.

### POST /leads/{id}/lost

**Scope:** `leads:write` · **Who:** the owner only

| Name | Type | Required | Description |
|---|---|---|---|
| `objection` | string | Yes | From `GET /meta` → `objections`, e.g. `commission`, `other_ota`, `has_pms`, `timing` |
| `competitor` | string | When the objection is `other_ota` or `competitor_relationship` | e.g. `Booking.com` |
| `notes` | string | No | |
| `reengage_on` | date | No | `YYYY-MM-DD`, after today. Books a "Re-engage after loss" follow-up on that date |

Response: the lead with `status: "lost"` and the `lost` object filled. Management receives a "Deal lost" alert.

Errors: `403` not the owner; `422` missing objection/competitor, past re-engage date, or an onboarded lead.

### POST /leads/{id}/transfer

**Scope:** `leads:write` · **Who:** Super Admin, Sales Admin, Sales Manager (transfer permission)

| Name | Type | Required | Description |
|---|---|---|---|
| `to_user_id` | integer | Yes | An active salesperson other than the current owner |
| `reason` | string | Yes | From `GET /meta` → `transfer_reasons`: `territory`, `left`, `workload`, `client_request`, `performance`, `new_assignment`, `other` |
| `notes` | string | When reason is `other` | |
| `with_registry` | boolean | No | Default `true`: if the previous owner represented the linked registry property, it moves too |

Open schedule items move to the new owner; past activity stays with whoever did it. The transfer is recorded (previous and new owner, who, when, reason) and the new owner is alerted. Response: the lead including `transfers`.

Errors: `403` without the transfer permission; `422` same owner, inactive or non-selling recipient, missing reason or notes.

### POST /leads/transfer-bulk

**Scope:** `leads:write` · **Who:** Super Admin, Sales Admin, Sales Manager

Moves every **open** lead of one salesperson to another (for example when someone leaves). Won and lost leads stay with the original owner.

| Name | Type | Required | Description |
|---|---|---|---|
| `from_user_id` | integer | Yes | Current owner (may be inactive) |
| `to_user_id` | integer | Yes | Active salesperson, different from `from_user_id` |
| `reason` | string | Yes | As for a single transfer |
| `notes` | string | When reason is `other` | |

```json
{ "message": "12 open leads transferred to Mary Wambua.", "transferred": 12, "lead_ids": [201, 204, 219] }
```

### GET /leads/{id}/activities

**Scope:** `leads:read` · **Who:** the owner, or managers with team performance

Paginated, newest first.

```json
{
  "data": [
    { "id": 55, "lead_id": 214, "type": "site_visit", "type_label": "Site visit", "happened_at": "2026-09-25T11:00:00+03:00", "notes": "Walked the property with the GM.", "next_action": "Share onboarding link", "user": { "id": 7, "name": "John Doe" } }
  ],
  "links": { "…": "…" },
  "meta": { "current_page": 1, "total": 6 }
}
```

### POST /leads/{id}/activities

**Scope:** `leads:write` · **Who:** the owner only

| Name | Type | Required | Description |
|---|---|---|---|
| `type` | string | Yes | From `GET /meta` → `activity_types`: `call`, `whatsapp`, `email`, `meeting`, `site_visit`, `demo`, `proposal_sent`, `contract_discussion`, `follow_up` |
| `happened_at` | datetime | Yes | ISO 8601; no later than an hour from now |
| `notes` | string | No | |
| `next_action` | string | No | Becomes the title of the follow-up |
| `follow_up_at` | date | No | Books a follow-up on this date (today or later) |
| `follow_up_time` | string | No | `HH:MM`; without it the follow-up is "anytime" |

The first activity moves a New lead to Contacted; a meeting, site visit or demo moves it to Meeting. Response `201` with the activity.

## Schedule and calendar

Scheduled calls, meetings, site visits and follow-ups. Each item belongs to a lead and its owner. Only the owner creates, edits, completes or removes their items. Managers with team performance can read the whole team's calendar.

### GET /schedule

**Scope:** `schedule:read` · **Who:** people who sell (own items); managers with team performance (team or one person)

| Name | Type | Required | Description |
|---|---|---|---|
| `from` | date | No | Start of range; default start of this week (Monday) |
| `to` | date | No | End of range; default end of this week. At most 93 days after `from` |
| `user` | string | No | Managers: `team`, or a user ID. Default: yourself if you sell, otherwise `team`. Ignored for salespeople, who always see their own |
| `include_completed` | boolean | No | `false` to leave out done items (default: included) |
| `per_page` | integer | No | 1–100, default 100 |

```json
{
  "data": [
    {
      "id": 88,
      "type": "meeting",
      "type_label": "Meeting",
      "title": "Proposal discussion",
      "due_at": "2026-09-28T11:30:00+03:00",
      "has_time": true,
      "duration_minutes": 60,
      "time_label": "11:30–12:30",
      "contact_name": "John Wambua",
      "contact_role": "General Manager",
      "location": "PrideInn Paradise, Shanzu",
      "notes": null,
      "is_meeting": true,
      "is_overdue": false,
      "completed_at": null,
      "outcome_activity_id": null,
      "lead": { "id": 214, "business_name": "PrideInn Paradise Beach Resort", "property_engagement_id": 31 },
      "owner": { "id": 9, "name": "Mary Wambua", "avatar_url": null }
    }
  ],
  "meta": { "current_page": 1, "total": 1, "from": "2026-09-21T00:00:00+03:00", "to": "2026-09-27T23:59:59+03:00", "user": 9 }
}
```

Items are ordered by day; within a day, timed items by time, then "anytime" items.

Errors: `403` for accounts that neither sell nor see the team; `422` range longer than 93 days.

### POST /schedule

**Scope:** `schedule:write` · **Who:** people who sell, on their own leads

| Name | Type | Required | Description |
|---|---|---|---|
| `lead_id` | integer | Yes | One of your leads |
| `type` | string | Yes | An activity type (see `GET /meta`), e.g. `meeting`, `site_visit`, `call` |
| `title` | string | Yes | e.g. `Proposal discussion` |
| `date` | date | Yes | Today or later |
| `time` | string | No | `HH:MM`. Leave out for an "anytime" reminder |
| `duration_minutes` | integer | No | 5–720; only kept when a time is given |
| `contact_name`, `contact_role`, `location`, `notes` | string | No | |

Response `201` with the schedule item.

Errors: `403` for accounts that don't sell; `422` lead not yours, date in the past.

### PATCH /schedule/{id}

**Scope:** `schedule:write` · **Who:** the item's owner

Send only the fields to change (same fields as `POST /schedule`; the date may be any date). Send `"time": null` to make an item "anytime". Response: the updated item.

Errors: `403` not your item; `422` validation.

### POST /schedule/{id}/complete

**Scope:** `schedule:write` · **Who:** the item's owner

Marks the item done. Unless it is a plain reminder with nothing to report, the outcome is logged on the lead as an activity, so it appears in the lead's history and the property's registry timeline.

| Name | Type | Required | Description |
|---|---|---|---|
| `outcome` | string | No | What happened |
| `next_action` | string | No | Title of the next follow-up |
| `next_type` | string | No | Activity type of the next item; default `follow_up` |
| `next_date` | date | No | Books the next item on this date (today or later) |
| `next_time` | string | No | `HH:MM` |

Response: the completed item (`completed_at` set, `outcome_activity_id` when an activity was logged).

Errors: `403` not your item; `422` already done.

### DELETE /schedule/{id}

**Scope:** `schedule:write` · **Who:** the item's owner

Removes an open item. Completed items stay in the history and cannot be removed.

```json
{ "message": "Removed from the schedule." }
```

Errors: `403` not your item; `422` already done.

## Duplicate check

### POST /duplicates/check

**Scope:** `leads:read` or `registry:read` · **Who:** anyone with either scope

Asks whether Tourlast already knows a property, before you create a lead or registry record. It searches the Property Engagement Registry, every salesperson's leads and tourlast.com signups. It checks name, trading name, phone, email, website, registration number and KRA PIN, and uses the town to strengthen a name match. Names match both ways ("Tembo Lodge Naivasha" ↔ "Tembo Lodge").

| Name | Type | Required | Description |
|---|---|---|---|
| `name` | string | No | Property or business name |
| `trading_name` | string | No | |
| `city` | string | No | Town, strengthens a name match |
| `phones` | string[] | No | Up to 10; any format (`0711 222 333`, `+254711222333`) |
| `emails` | string[] | No | Up to 10 |
| `website` | string | No | Any form; compared by domain |
| `registration_number` | string | No | |
| `kra_pin` | string | No | Case-insensitive |
| `except_lead_id` | integer | No | Leave this lead out (when editing it) |
| `except_engagement_id` | integer | No | Leave this registry record out |

```json
{
  "data": [
    {
      "kind": "lead",
      "key": "lead-118",
      "name": "Coral Reef Stays",
      "location": "Diani",
      "owner": "Sarah Achieng",
      "owner_is_you": false,
      "stage": "Lead · Meeting",
      "last_contacted": "2026-09-18T14:05:00+03:00",
      "reasons": ["Same phone number"],
      "active": true,
      "archived": false,
      "engagement_id": null,
      "lead_id": null
    }
  ]
}
```

An empty `data` array means no match was found. Matches are ranked strongest first (identifiers outrank a similar name) and at most six are returned.

Errors: `403` without `leads:read` or `registry:read`; `422` validation.

---

## Property Engagement Registry

The registry is Tourlast's permanent record of every property or business it has engaged, whatever the outcome. Base URL: `https://sales.tourlast.com/api/v1`.

- **Everyone** with a registry token (all six roles) can search and read it.
- **Only managers** (Super Admin, Sales Admin, Sales Manager) can add, edit, log engagement, assign, link, archive or restore. Salespeople, HR and Accounts get `403` on every write, even with a `registry:write` token.

**Stage and status are separate.** `stage` is where the property is in the acquisition process (Not contacted → … → Proposal sent → Negotiation → Onboarding started → … → Live). `status` is its current condition (active, stalled, won, lost, rejected, re_engage, closed). A property can be at stage *Proposal sent* with status *Stalled*. Values and labels are listed by `GET /meta` (`engagement_stages`, `engagement_statuses`).

**The timeline is append-only.** Registry events are never edited or deleted, so the timeline doubles as the audit log: stage, status and representative changes, edited fields with old → new values, contacts added and removed, links, archive and restore. The profile also merges in calls and meetings logged on linked salesperson leads (`kind: "lead_activity"`, `via_lead: true`); those are read from the leads, not copied into the registry.

### GET /registry

**Scope:** `registry:read` · **Who:** everyone

Paginated list, newest engagement first. `meta` and `links` follow the standard pagination format; `summary` gives registry-wide counts that ignore filters.

| Name | Type | Required | Description |
|---|---|---|---|
| `q` | string | No | Search property, trading/registration name, location, tourlast.com ID, contact name/email/phone, salesperson |
| `type` | string | No | Property type key (`GET /meta` → `property_types`) |
| `country`, `region`, `city` | string | No | Exact location match |
| `rep` | integer | No | Current sales representative (user ID) |
| `stage` | string | No | Engagement stage value |
| `status` | string | No | Engagement status value |
| `source` | string | No | Engagement source value |
| `first_from`, `first_to` | date | No | First engaged between (YYYY-MM-DD) |
| `last_from`, `last_to` | date | No | Last engaged between |
| `activity` | string | No | `active` (being worked) or `inactive` (won, lost, closed) |
| `onboarded` | string | No | `yes` (Live) or `no` |
| `archived` | boolean | No | Archived records only. Ignored unless you are a manager |
| `sort` | string | No | `name`, `type`, `location`, `stage` (process order), `status`, `rep`, `first`, `last` (default) |
| `dir` | string | No | `asc` or `desc` (default) |
| `per_page` | integer | No | 1–100, default 25 |

```json
{
  "data": [
    {
      "id": 3,
      "name": "PrideInn Paradise Beach Resort",
      "property_type": "resort",
      "property_type_label": "Resort",
      "location": "Shanzu, Mombasa",
      "city": "Mombasa",
      "region": "Mombasa",
      "country": "Kenya",
      "stage": "proposal_sent", "stage_label": "Proposal sent", "stage_order": 7,
      "status": "active", "status_label": "Active",
      "sales_rep": { "id": 5, "name": "Mary Wambui", "avatar_url": null },
      "primary_contact": { "name": "John Wambua", "title": "General Manager", "phone": "+254734607084", "email": "gm@prideinn.co.ke" },
      "first_engaged_on": "2026-09-06",
      "last_engaged_on": "2026-09-25",
      "next_action": "Book a site visit",
      "next_action_on": "2026-10-01",
      "archived": false
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 25, "total": 24, "last_page": 1 },
  "summary": { "total": 24, "engaged": 16, "onboarding": 4, "stalled": 2, "lost": 2, "live": 5 }
}
```

### GET /registry/{id}

**Scope:** `registry:read` · **Who:** everyone; archived records managers only

The full profile: details, location, contacts, representatives (current and previous, with transfer reason and who assigned), merged timeline (newest first), upcoming scheduled meetings on linked leads, linked leads and tourlast.com signups, outcome, and what you may do (`can`).

```json
{
  "data": {
    "id": 3,
    "name": "PrideInn Paradise Beach Resort",
    "property_type": "resort",
    "star_rating": "4",
    "website": "https://www.prideinnhotels.com",
    "location": { "label": "Shanzu, Mombasa", "country": "Kenya", "region": "Mombasa", "city": "Mombasa", "area": "Shanzu", "address": null, "latitude": null, "longitude": null },
    "stage": "proposal_sent", "stage_label": "Proposal sent", "stage_order": 7,
    "status": "active", "status_label": "Active",
    "source": "trade_show", "source_label": "Trade show",
    "outcome": { "objection": null, "objection_label": null, "competitor": null, "notes": null, "closed_at": null, "reengage_on": null },
    "sales_rep": { "id": 5, "name": "Mary Wambui", "avatar_url": null },
    "contacts": [
      { "id": 9, "name": "John Wambua", "title": "General Manager", "phone": "+254734607084", "whatsapp": null, "email": "gm@prideinn.co.ke", "is_primary": true, "is_decision_maker": true }
    ],
    "reps": [
      { "id": 12, "user": { "id": 5, "name": "Mary Wambui" }, "started_on": "2026-09-15", "ended_on": null, "current": true, "reason": "territory", "reason_label": "Territory reassignment", "notes": null, "assigned_by": { "id": 3, "name": "David Otieno" } },
      { "id": 11, "user": { "id": 4, "name": "John Doe" }, "started_on": "2026-09-06", "ended_on": "2026-09-15", "current": false, "reason": null, "reason_label": null, "notes": null, "assigned_by": { "id": 3, "name": "David Otieno" } }
    ],
    "timeline": [
      { "kind": "lead_activity", "id": 88, "type": "call", "type_label": "Call", "is_interaction": true, "happened_at": "2026-09-24T10:15:00+03:00", "sales_rep": { "id": 5, "name": "Mary Wambui" }, "lead": { "id": 41, "business_name": "PrideInn Paradise Beach Resort" }, "notes": "GM confirmed interest.", "next_action": "Proposal discussion meeting", "via_lead": true },
      { "kind": "event", "id": 57, "type": "stage_changed", "type_label": "Stage changed", "is_interaction": false, "happened_at": "2026-09-20T11:00:00+03:00", "sales_rep": { "id": 5, "name": "Mary Wambui" }, "recorded_by": { "id": 3, "name": "David Otieno" }, "transition": { "from": "Meeting completed", "to": "Proposal sent" }, "summary": null, "notes": null, "changes": null, "via_lead": false }
    ],
    "upcoming": [
      { "id": 204, "type": "meeting", "title": "Proposal discussion", "due_at": "2026-09-28T11:30:00+03:00", "time_label": "11:30–12:30", "contact_name": "John Wambua", "contact_role": "General Manager" }
    ],
    "leads": [ { "id": 41, "business_name": "PrideInn Paradise Beach Resort", "status": "meeting", "status_label": "Meeting", "owner": { "id": 5, "name": "Mary Wambui" } } ],
    "onboardings": [],
    "archived": false,
    "can": { "update": true, "archive": true, "restore": false }
  }
}
```

Errors: `403` archived record and you are not a manager · `404` no such record.

### POST /registry

**Scope:** `registry:write` · **Who:** managers

Add a property with its primary contact. The Hub-wide duplicate check runs first (registry, all salesperson leads and tourlast.com signups, by name, trading name, town, phone, email, website, registration number and KRA PIN). If a **registry** record matches, the request is refused with `409` and the matches; send it again with `confirm_different: true` once you have checked it is a different property. The override is recorded on the new record's first timeline entry.

| Name | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | Property / business name |
| `property_type` | string | Yes | Property type key |
| `star_rating` | string | No | `1`–`5` or `unrated` (accommodation types only) |
| `tourlast_property_id`, `website`, `trading_name`, `registration_name`, `registration_number` | string | No | Identifiers |
| `kra_pin` | string | No | Format `P051234567X` |
| `rooms` | integer | No | Rooms/units (accommodation types only) |
| `capacity` | integer | No | Guests, covers or seats |
| `country`, `region`, `city` | string | Yes | Location |
| `area`, `address` | string | No | |
| `latitude`, `longitude` | number | No | |
| `contact_name`, `contact_title`, `contact_phone` | string | Yes | Primary contact |
| `contact_whatsapp`, `contact_email` | string | No | |
| `sales_rep_id` | integer | Yes | Primary sales representative |
| `first_engaged_on` | date | Yes | Not in the future |
| `stage`, `status` | string | Yes | See `GET /meta` |
| `source` | string | No | Engagement source |
| `summary`, `next_action` | string | No | |
| `next_action_on` | date | No | |
| `objection` | string | If status is `lost`/`rejected` | Primary objection (`GET /meta` → `objections`) |
| `competitor` | string | If objection needs it | Required for `other_ota` and `competitor_relationship` |
| `outcome_notes` | string | No | |
| `reengage_on` | date | No | Future date; the property moves to Re-engage that morning |
| `confirm_different` | boolean | No | Create despite registry matches |

Returns `201` with the full profile.

```json
{
  "message": "This looks like a property already in the registry. Open the existing record, or send confirm_different=true if it is a different property.",
  "matches": [
    {
      "kind": "registry",
      "key": "registry-3",
      "name": "PrideInn Paradise Beach Resort",
      "location": "Shanzu, Mombasa, Kenya",
      "owner": "Mary Wambui",
      "owner_is_you": false,
      "stage": "Proposal sent · Active",
      "last_contacted": "2026-09-25T00:00:00+03:00",
      "reasons": ["Similar name, same town"],
      "active": true,
      "archived": false,
      "engagement_id": 3,
      "lead_id": null
    }
  ]
}
```

Errors: `409` possible duplicate · `422` validation (e.g. `objection` missing for a lost record) · `403` not a manager.

### PATCH /registry/{id}

**Scope:** `registry:write` · **Who:** managers

Send only the fields that change (same fields as create, except the contact fields; manage contacts with the contact endpoints). Every changed field is recorded in the timeline with its old and new value; stage, status and representative changes get their own entries.

| Name | Type | Required | Description |
|---|---|---|---|
| any create field | | No | New value (`null` clears optional fields) |
| `rep_reason` | string | If `sales_rep_id` changes | Transfer reason (`GET /meta` → `transfer_reasons`) |
| `rep_notes` | string | No | |
| `objection`, `competitor`, `outcome_notes`, `reengage_on` | | When the status is or becomes lost/rejected | As for create |

Errors: `403` not a manager, or the record is archived (restore it first) · `422` validation.

### POST /registry/{id}/engagements

**Scope:** `registry:write` · **Who:** managers

Log a touchpoint (call, WhatsApp, email, meeting, site visit, demo, proposal, negotiation, note). Interactions move `last_engaged_on`. If `rep_id` is a different salesperson, they become the current representative and the change is recorded ("took over while logging").

| Name | Type | Required | Description |
|---|---|---|---|
| `type` | string | Yes | `GET /meta` → `engagement_log_types` |
| `rep_id` | integer | If the record has no rep | Salesperson who made the contact (default: current rep) |
| `happened_on` | date | Yes | Today or earlier |
| `summary` | string | Yes | What happened |
| `notes` | string | No | |
| `stage`, `status` | string | No | Move the record at the same time |
| `next_action`, `next_action_on` | | No | |
| `objection`, `competitor`, `outcome_notes`, `reengage_on` | | When `status` is lost/rejected | As for create |

```json
{ "type": "meeting", "happened_on": "2026-09-24", "summary": "Meeting completed", "notes": "GM requested a commercial proposal.", "stage": "meeting_completed" }
```

Returns the full profile.

### POST /registry/{id}/assign

**Scope:** `registry:write` · **Who:** managers

Assign a property with no representative, or transfer ownership. The previous representative's period is closed, not deleted.

| Name | Type | Required | Description |
|---|---|---|---|
| `rep_id` | integer | Yes | New representative; must differ from the current one |
| `reason` | string | Yes when transferring | Defaults to `new_assignment` when the record has no rep |
| `notes` | string | If reason is `other` | |

Returns the full profile. Errors: `422` same rep, missing reason, or missing notes for `other`.

### POST /registry/{id}/contacts

**Scope:** `registry:write` · **Who:** managers

| Name | Type | Required | Description |
|---|---|---|---|
| `name` | string | Yes | |
| `title` | string | Yes | Job title (`GET /meta` → `contact_titles`) |
| `phone` | string | If no email | |
| `email` | string | If no phone | |
| `whatsapp` | string | No | |
| `is_decision_maker` | boolean | No | |
| `is_primary` | boolean | No | Make this the primary contact |

Returns `201` with the contact.

### DELETE /registry/{id}/contacts/{contactId}

**Scope:** `registry:write` · **Who:** managers

Removes a contact; their details stay in the timeline. Errors: `422` it is the primary contact (make another one primary first).

### POST /registry/{id}/contacts/{contactId}/primary

**Scope:** `registry:write` · **Who:** managers

Makes the contact primary. Returns the contact.

### POST /registry/{id}/links

**Scope:** `registry:write` · **Who:** managers

Link a salesperson lead or a tourlast.com signup to the record. Send one of the two. Once a signup is linked (directly or through its lead), the registry stage follows tourlast.com automatically (Submitted → Onboarding submitted, Under review → Verification, Approved → Approved, Active → Live/Won, Rejected → Rejected).

| Name | Type | Required | Description |
|---|---|---|---|
| `lead_id` | integer | One of the two | Lead to link |
| `onboarding_id` | integer | One of the two | tourlast.com signup to link |

Returns the full profile. Errors: `422` already linked to a registry record.

### POST /registry/{id}/archive

**Scope:** `registry:write` · **Who:** managers

Hides the record from the registry (history kept). Returns the profile with `archived: true`. Errors: `422` already archived.

### POST /registry/{id}/restore

**Scope:** `registry:write` · **Who:** managers

Brings an archived record back. Errors: `422` not archived.

---

## Onboardings

An **onboarding** is a provider signup read from tourlast.com and credited to the salesperson whose referral code it carries. Statuses come from tourlast.com: `submitted`, `under_review`, `approved`, `active` (live, counts as onboarded) and `rejected`.

**Who sees what:** salespeople see their own onboardings; anyone who can view the Partner Register (Sales Admin, HR, Accounts) or team performance (Sales Manager) sees all of them.

### GET /onboardings

**Scope:** `onboardings:read` · **Who:** salespeople (own), Sales Admin, Sales Manager, HR, Accounts (all)

| Name | Type | Required | Description |
|---|---|---|---|
| `status` | string | No | `all` (default), `awaiting` (not live yet), `onboarded` (live), or an exact status such as `rejected` |
| `user_id` | integer | No | Only this salesperson's signups (people who see all) |
| `from`, `to` | date | No | Signup date range, `YYYY-MM-DD` |
| `q` | string | No | Search property name, location or contact |
| `per_page` | integer | No | 1–100, default 25 |

```json
{
  "data": [
    {
      "id": 118,
      "tourlast_property_id": "TL-00842",
      "property_name": "Kifaru Lodge",
      "property_type": "lodge",
      "property_type_label": "Lodge",
      "location": "Naivasha, Kenya",
      "contact_name": "Jane Mwangi",
      "contact_phone": "+254712345678",
      "status": "under_review",
      "status_label": "Under review",
      "is_stalled": false,
      "ref_code": "TL-JOHN-2847",
      "attribution": "referral",
      "salesperson": { "id": 4, "name": "John Doe", "avatar_url": null },
      "submitted_at": "2026-09-18T09:41:00+03:00",
      "approved_at": null,
      "active_at": null,
      "credited_at": null,
      "progress": {
        "steps": [
          { "key": "referral", "label": "Referral", "state": "done" },
          { "key": "application", "label": "Application", "state": "done" },
          { "key": "verification", "label": "Verification", "state": "current" },
          { "key": "approval", "label": "Approval", "state": "upcoming" },
          { "key": "live", "label": "Live", "state": "upcoming" }
        ],
        "completed": 2,
        "rejected": false
      }
    }
  ],
  "links": { "first": "…", "last": "…", "prev": null, "next": null },
  "meta": { "current_page": 1, "per_page": 25, "total": 1 }
}
```

Progress step `state` is one of `done`, `current`, `upcoming` or `rejected` (a rejected signup shows the approval step as `Rejected`).

**Errors:** `403` for roles without onboarding access.

### GET /onboardings/{id}

**Scope:** `onboardings:read` · **Who:** the credited salesperson, or anyone who sees all onboardings

Returns one onboarding with `status_history` (every status change from tourlast.com) and `credit_changes` (manual assignments with reason and who made them).

```json
{
  "data": {
    "id": 118,
    "property_name": "Kifaru Lodge",
    "status": "approved",
    "status_history": [
      { "from": null, "to": "submitted", "to_label": "Submitted", "source": "sync", "occurred_at": "2026-09-18T09:41:00+03:00" },
      { "from": "submitted", "to": "approved", "to_label": "Approved, going live", "source": "webhook", "occurred_at": "2026-09-22T11:05:00+03:00" }
    ],
    "credit_changes": []
  }
}
```

**Errors:** `403` if it is someone else's and you cannot see all; `404` if it does not exist.

### GET /onboardings/unattributed

**Scope:** `onboardings:read` · **Who:** Super Admin, Sales Admin

Signups that arrived without a referral code, newest first (paginated, same fields as the list).

### POST /onboardings/{id}/assign

**Scope:** `onboardings:write` · **Who:** Super Admin, Sales Admin

Credits an unattributed signup to a salesperson. The reason is stored and later tourlast.com syncs keep this assignment.

| Name | Type | Required | Description |
|---|---|---|---|
| `salesperson_id` | integer | Yes | An active salesperson (or selling manager) |
| `reason` | string | Yes | 10–500 characters |

```json
{ "salesperson_id": 4, "reason": "John met the owner at the Magical Kenya expo." }
```

Returns the onboarding with `attribution: "manual"` and the new entry in `credit_changes`.

**Errors:** `403` for other roles; `404` if the signup is not unattributed; `422` for a short reason or a person who does not sell.

## Partner accounts and points

A **Partner Account** groups every property of one legal business; Schedule 1 points are paid per Account, sized by verified rooms/units (stays) or bookable services (experiences). Points start `provisional` and become `approved` when a Sales Admin verifies the Account.

**Who sees what:** salespeople see their own Accounts; Sales Admin, Sales Manager, HR and Accounts see all.

### GET /partner-accounts

**Scope:** `incentives:read` · **Who:** salespeople (own), Sales Admin, Sales Manager, HR, Accounts (all)

| Name | Type | Required | Description |
|---|---|---|---|
| `queue` | string | No | `all` (default), `verify` (live, awaiting verification), `review` (within the 14-day review), `expansion` (within the 90-day window), `failed` |
| `user_id` | integer | No | One salesperson's Accounts (people who see all) |
| `q` | string | No | Search the legal name |
| `per_page` | integer | No | 1–100, default 25 |

```json
{
  "data": [
    {
      "id": 31,
      "legal_name": "Kifaru Hospitality Ltd",
      "category": "stay",
      "category_label": "Stay",
      "salesperson": { "id": 4, "name": "John Doe", "avatar_url": null },
      "activation_date": "2026-09-03T10:00:00+03:00",
      "activation_inventory": null,
      "inventory_basis": "rooms",
      "qualification_status": "pending",
      "verified": false,
      "review": { "status": "in_review", "ends_at": "2026-09-17T10:00:00+03:00", "failed_at": null, "failed_reason": null },
      "expansion_days_left": 72,
      "live_points": 3,
      "checklist_progress": { "done": 9, "total": 13 }
    }
  ],
  "links": {}, "meta": {}
}
```

### GET /partner-accounts/{id}

**Scope:** `incentives:read` · **Who:** the Account's salesperson, verifiers, managers, HR, Accounts

Adds `checklist` (the 13 qualification items with completion and whether evidence is attached), `properties`, `inventory_snapshots`, `current_inventory` and `points` (the ledger; lines replaced by a later correction are hidden).

```json
{
  "data": {
    "id": 31,
    "legal_name": "Kifaru Hospitality Ltd",
    "checklist": [
      { "item": "contract_signed", "label": "Partnership agreement signed", "automatic": false, "wants_evidence": true, "completed_at": "2026-09-04T09:00:00+03:00", "has_evidence": true }
    ],
    "properties": [ { "onboarding_id": 118, "property_name": "Kifaru Lodge", "status": "active" } ],
    "points": [
      { "id": 901, "type": "base", "type_label": "New Account", "points": 3, "status": "provisional", "earned_on": "2026-09-03", "month": "2026-09", "bonus_week": 1 }
    ]
  }
}
```

**Errors:** `403` if you cannot view this Account.

### POST /partner-accounts/{id}/verify

**Scope:** `incentives:write` · **Who:** Super Admin, Sales Admin

Records the verified inventory and approves the Account's points. The Account must be live on tourlast.com and every checklist item complete.

| Name | Type | Required | Description |
|---|---|---|---|
| `inventory` | integer | Yes | Verified count, 1–100000 |
| `basis` | string | Yes | `rooms`, `units`, `services`, `outlets`, `packages` or `products` |
| `category` | string | Yes | `stay` or `experience` |
| `note` | string | For `outlets`, `packages`, `products` | Why services were not a reasonable measure (paragraph 4.2), up to 250 characters |

```json
{ "inventory": 42, "basis": "rooms", "category": "stay" }
```

Returns the Account with `verified: true`.

**Errors:** `403` for other roles; `422` on `inventory` when the Account is not live yet or the checklist is incomplete.

### POST /partner-accounts/{id}/fail-review

**Scope:** `incentives:write` · **Who:** Super Admin, Sales Admin

The 14-day review failed: the Account's points are cancelled and anything already paid is recovered on the next statement.

| Name | Type | Required | Description |
|---|---|---|---|
| `reason` | string | Yes | 10–250 characters |

**Errors:** `403` for other roles; `422` for a missing or short reason.

## Earnings and statements

Amounts are numbers in **KES**. Months are `YYYY-MM`.

### GET /earnings/{userId}

**Scope:** `incentives:read` · **Who:** the salesperson themselves, Sales Admin, HR, Accounts (Sales Managers see points, not pay, and get `403`)

| Name | Type | Required | Description |
|---|---|---|---|
| `month` | string | No | `YYYY-MM`, default this month |

```json
{
  "data": {
    "user_id": 4,
    "month": "2026-09",
    "currency": "KES",
    "has_agreement": true,
    "approved": {
      "points": 31.5, "provisional_points": 6, "retainer": 15000, "retainer_compliant": true,
      "weekly_points": { "1": 8, "2": 19, "3": 4.5, "4": 0 }, "weekly_bonuses": { "1": 0, "2": 1500, "3": 0, "4": 0 },
      "weekly_bonus_total": 1500, "monthly_bonus": 5000, "exceptional": 0,
      "airtime": 400, "transport": 1200, "adjustments": 0, "adjustment_lines": [],
      "incentives": 21500, "total": 23100
    },
    "including_provisional": { "points": 37.5, "total": 25600 },
    "next_step": { "points": 36, "missing": 4.5, "label": "KES 7,500 monthly bonus" },
    "statement": null
  }
}
```

`approved` counts only verified points; `including_provisional` shows what the month is worth if pending Accounts are verified. The salesperson's own figures are also at `GET /me/earnings`.

**Errors:** `403` if you may not see this person's pay; `404` for people who do not sell.

### GET /statements

**Scope:** `incentives:read` · **Who:** salespeople (own), Sales Admin, HR, Accounts (all)

| Name | Type | Required | Description |
|---|---|---|---|
| `month` | string | No | `YYYY-MM` |
| `user_id` | integer | No | One salesperson (people who see all) |
| `status` | string | No | `draft`, `approved` or `paid` |
| `per_page` | integer | No | 1–100, default 25 |

```json
{
  "data": [
    {
      "id": 57,
      "salesperson": { "id": 4, "name": "John Doe", "avatar_url": null },
      "month": "2026-08",
      "status": "draft",
      "locked": false,
      "points": 31.5,
      "retainer": 15000, "weekly_bonus": 1500, "monthly_bonus": 5000, "exceptional": 0,
      "airtime": 400, "transport": 1200, "adjustments": 0, "adjustment_lines": [],
      "recovered_amount": 0,
      "total": 23100,
      "currency": "KES",
      "compliance": [
        { "key": "reports", "label": "Required reports submitted", "confirmed": true },
        { "key": "training", "label": "Required training completed", "confirmed": true },
        { "key": "follow_up", "label": "Partner follow-up done", "confirmed": false },
        { "key": "support", "label": "Partner support provided", "confirmed": true }
      ],
      "compliant": false,
      "approved_at": null,
      "paid_at": null,
      "payment_reference": null
    }
  ],
  "links": {}, "meta": {}
}
```

### GET /statements/{id}

**Scope:** `incentives:read` · **Who:** the statement's salesperson, Sales Admin, HR, Accounts

### GET /statements/{id}/pdf

**Scope:** `incentives:read` · **Who:** as above

Downloads the statement PDF (`application/pdf`), including the Schedule 1 paragraph 13 report for every Account claimed that month.

### POST /statements/{id}/compliance

**Scope:** `incentives:write` · **Who:** Super Admin, Sales Admin

Confirms the retainer conditions; the draft is recalculated.

| Name | Type | Required | Description |
|---|---|---|---|
| `compliance` | object | Yes | Booleans for `reports`, `training`, `follow_up`, `support`; missing keys count as `false` |

```json
{ "compliance": { "reports": true, "training": true, "follow_up": true, "support": true } }
```

**Errors:** `422` once the statement is approved.

### POST /statements/{id}/approve

**Scope:** `incentives:write` · **Who:** Super Admin, Accounts

Recalculates one last time and freezes the figures (`status: "approved"`, `locked: true`). Recoveries from earlier months shown on the statement count as settled.

### POST /statements/{id}/pay

**Scope:** `incentives:write` · **Who:** Super Admin, Accounts

| Name | Type | Required | Description |
|---|---|---|---|
| `payment_reference` | string | Yes | M-Pesa or bank reference, up to 100 characters |

Marks the statement paid; the month's approved airtime and transport reimbursements are marked paid with it.

**Errors:** `403` for other roles; `422` if the statement is not approved yet.

---

## Claims

Salespeople claim **airtime**, **transport reimbursements** (trips already taken) and **transport requests** (money before a trip). Amounts are in **KES**.

| Type | Evidence | Approval chain |
|---|---|---|
| `airtime` | — | Finance |
| `transport_reimbursement` | A receipt, or for Bolt/Uber the trip ID plus the ride details from the app | Sales Manager → HR → Finance |
| `transport_request` | Purpose and route | Sales Manager → HR → Finance |

Status is `pending` (waiting for `current_step`: `manager`, `hr` or `finance`), `approved`, `paid` or `rejected`. Nobody can approve their own claim.

### GET /claims

**Scope:** `claims:read` · **Who:** salespeople (their own claims)

| Name | Type | Required | Description |
|---|---|---|---|
| `per_page` | integer | No | 1–100, default 25 |

```json
{
  "data": [
    {
      "id": 212,
      "type": "transport_reimbursement",
      "type_label": "Transport reimbursement",
      "month": "2026-09",
      "amount": 640,
      "approved_amount": null,
      "payable_amount": 640,
      "currency": "KES",
      "description": "Site visit to Kifaru Lodge",
      "travel_date": "2026-09-19",
      "ride_provider": "bolt",
      "ride_provider_label": "Bolt",
      "trip_reference": "RB12345678",
      "pickup": "Westlands",
      "dropoff": "Kilimani",
      "distance_km": 7.4,
      "status": "pending",
      "status_label": "Waiting for Sales Manager",
      "current_step": "manager",
      "steps": ["manager", "hr", "finance"],
      "attachments": [
        { "id": 88, "kind": "ride_details", "kind_label": "Ride details (Bolt/Uber)", "name": "bolt-trip.png", "mime": "image/png", "size": 184220, "download_url": "https://sales.tourlast.com/api/v1/claims/212/attachments/88" }
      ],
      "created_at": "2026-09-19T18:02:00+03:00"
    }
  ],
  "links": {}, "meta": {}
}
```

### POST /claims

**Scope:** `claims:write` · **Who:** salespeople

Send as `multipart/form-data` when attaching files (JSON is fine without files).

| Name | Type | Required | Description |
|---|---|---|---|
| `type` | string | Yes | `airtime`, `transport_reimbursement` or `transport_request` |
| `amount` | number | Yes | 1–100000 |
| `description` | string | Yes | Purpose, up to 1000 characters |
| `travel_date` | date | Transport | On or before today for reimbursements; today or later for requests |
| `ride_provider` | string | Transport | `bolt`, `uber`, `taxi`, `matatu`, `boda`, `own_vehicle`, `other` |
| `pickup`, `dropoff` | string | Transport | Up to 190 characters |
| `distance_km` | number | No | 0–5000 |
| `trip_reference` | string | Bolt/Uber reimbursements | The trip ID shown in the app |
| `receipts[]` | file | Reimbursements not by Bolt/Uber or matatu | jpg, jpeg, png, webp, pdf, heic; up to 8 MB each |
| `ride_details[]` | file | Bolt/Uber reimbursements | Trip receipt screenshot or the emailed PDF |
| `partner_account_id` | integer | No | One of your Partner Accounts the trip was for |
| `lead_id` | integer | No | One of your leads the trip was for |

```
POST /api/v1/claims
Content-Type: multipart/form-data

type=transport_reimbursement
amount=640
description=Site visit to Kifaru Lodge
travel_date=2026-09-19
ride_provider=bolt
pickup=Westlands
dropoff=Kilimani
trip_reference=RB12345678
ride_details[]=@bolt-trip.png
```

Returns `201` with the claim (`current_step: "manager"` for transport, `"finance"` for airtime).

**Errors:** `403` for people who do not sell; `422` with messages such as "Enter the trip ID shown in the Bolt or Uber app." or "Upload a receipt for this trip."

### GET /claims/{id}

**Scope:** `claims:read` · **Who:** the claimant, claim approvers (Sales Admin, Sales Manager, HR, Accounts)

Adds `claimant` and `approvals` (each step's decision, amount, note, who and when).

```json
{
  "data": {
    "id": 212,
    "status": "approved",
    "approved_amount": 600,
    "approvals": [
      { "step": "manager", "step_label": "Sales Manager", "decision": "approved", "amount": 640, "note": "Fine", "by": { "id": 3, "name": "David Otieno" }, "created_at": "2026-09-20T08:10:00+03:00" },
      { "step": "hr", "step_label": "HR", "decision": "approved", "amount": 640, "note": null, "by": { "id": 8, "name": "Faith Achieng" }, "created_at": "2026-09-20T10:31:00+03:00" },
      { "step": "finance", "step_label": "Finance", "decision": "approved", "amount": 600, "note": "Receipt shows KES 600", "by": { "id": 9, "name": "Samuel Kiprono" }, "created_at": "2026-09-21T09:00:00+03:00" }
    ]
  }
}
```

**Errors:** `403` for anyone else.

### GET /claims/{id}/attachments/{attachmentId}

**Scope:** `claims:read` · **Who:** as `GET /claims/{id}`

Downloads the file (the `download_url` in the claim).

### GET /claims/approvals

**Scope:** `claims:read` · **Who:** Sales Admin and Sales Manager (manager step), HR (HR step), Accounts (finance step)

| Name | Type | Required | Description |
|---|---|---|---|
| `tab` | string | No | `mine` (default: claims waiting for your step, excluding your own), `disburse` (approved transport requests awaiting payment), `all` |
| `per_page` | integer | No | 1–100, default 25 |

**Errors:** `403` for people with no approval step.

### POST /claims/{id}/approve

**Scope:** `claims:write` · **Who:** the approver for the claim's current step

| Name | Type | Required | Description |
|---|---|---|---|
| `note` | string | No | Up to 1000 characters |
| `amount` | number | Finance step | Amount to pay, up to the amount claimed. Airtime is capped at the monthly allowance (KES 400). |

Moves the claim to the next step, or to `approved` after Finance.

**Errors:** `403` if the claim is not waiting for your step (or is your own); `422` if the amount is above the claim or the airtime allowance is used.

### POST /claims/{id}/reject

**Scope:** `claims:write` · **Who:** the approver for the claim's current step

| Name | Type | Required | Description |
|---|---|---|---|
| `note` | string | Yes | Why it is rejected, 5–1000 characters |

### POST /claims/{id}/disburse

**Scope:** `claims:write` · **Who:** Accounts (Finance)

Releases the money for an approved transport request.

| Name | Type | Required | Description |
|---|---|---|---|
| `payment_reference` | string | Yes | Up to 100 characters |

**Errors:** `422` if the claim is not an approved transport request or you are not Finance.

---

## Team and accounts

The team directory, invitations, suspending/firing/reinstating/deleting accounts, team performance and targets. Base URL `https://sales.tourlast.com/api/v1`.

Account actions follow the same rules as **Admin → Users & Invites**:

| Action | Super Admin | Sales Admin | Sales Manager | Others |
|---|---|---|---|---|
| Suspend / reinstate a suspension | Anyone | Anyone except Super Admins | Salespeople only | – |
| Fire | Anyone | Anyone except Super Admins | Salespeople only | – |
| Reinstate someone fired | Anyone | Anyone except Super Admins | – | – |
| Delete (no history only) | Anyone | Anyone except Super Admins | – | – |
| Change role / region | Anyone | Anyone except Super Admins | – | – |

Nobody can act on their own account. HR and Accounts can read the directory but cannot change accounts.

### GET /users

**Scope:** `team:read` · **Who:** people with "view presence" (admins, Sales Managers, HR, Accounts) or "invite salespeople"

| Name | Type | Required | Description |
|---|---|---|---|
| `q` | string | no | Search name, email, job title or region |
| `role` | string | no | A role value from `GET /meta` (`salesperson`, `hr`…) |
| `status` | string | no | `active`, `suspended` or `terminated` |
| `online` | boolean | no | `1` = active in the last five minutes |
| `per_page` | integer | no | 1–100, default 25 |

```json
{
  "data": [
    {
      "id": 14,
      "name": "John Doe",
      "email": "john@tourlast.com",
      "phone": "+254712345678",
      "role": "salesperson",
      "role_label": "Salesperson",
      "region": "Nairobi",
      "job_title": "Business Development Executive",
      "avatar_url": null,
      "account_status": "active",
      "account_status_label": "Active",
      "suspended_until": null,
      "online": true,
      "last_seen_at": "2026-09-27T09:12:40+03:00",
      "last_login_at": "2026-09-27T08:01:03+03:00",
      "referral_code": "TL-JOHN-2847",
      "created_at": "2026-08-01T10:00:00+03:00"
    }
  ],
  "links": { "first": "…?page=1", "last": "…?page=2", "prev": null, "next": "…?page=2" },
  "meta": { "current_page": 1, "last_page": 2, "per_page": 25, "total": 31 }
}
```

### GET /users/{id}

**Scope:** `team:read` · **Who:** directory viewers (as above), or the person themselves

Same fields as the list, plus `status_history` (suspensions, firings and reinstatements, newest first) for directory viewers:

```json
"status_history": [
  {
    "id": 3,
    "from_status": "active",
    "to_status": "suspended",
    "reason": "investigation",
    "reason_label": "Under investigation",
    "notes": "Client complaint being reviewed.",
    "suspended_until": "2026-10-04",
    "changed_by": { "id": 2, "name": "David Otieno" },
    "created_at": "2026-09-27T09:30:00+03:00"
  }
]
```

### PATCH /users/{id}

**Scope:** `team:write` · **Who:** Sales Admin, Super Admin

| Name | Type | Required | Description |
|---|---|---|---|
| `role` | string | no | A role the caller may assign (Sales Admins cannot assign `super-admin`) |
| `region` | string\|null | no | Up to 100 characters |

Changing someone to a selling role issues their referral code. Returns the updated user. `403` for your own account or a Super Admin (unless you are one).

### POST /users/{id}/suspend

**Scope:** `team:write` · **Who:** see the table above

| Name | Type | Required | Description |
|---|---|---|---|
| `reason` | string | yes | `investigation`, `conduct`, `performance`, `leave` or `other` (see `suspension_reasons` in `GET /meta`) |
| `until` | date | no | `YYYY-MM-DD`, after today. The suspension is lifted automatically that morning |
| `notes` | string | if reason is `other` | Up to 1,000 characters |

The person is signed out immediately and their API tokens stop working. Returns the updated user (`account_status: "suspended"`).

### POST /users/{id}/terminate

**Scope:** `team:write` · **Who:** see the table above

| Name | Type | Required | Description |
|---|---|---|---|
| `reason` | string | yes | `misconduct`, `performance`, `contract_ended`, `redundancy`, `resigned` or `other` |
| `notes` | string | if reason is `other` | Up to 1,000 characters |
| `confirm` | boolean | yes | Must be `true` |

Every record (leads, points, pay) is kept. Returns the updated user (`account_status: "terminated"`).

### POST /users/{id}/reinstate

**Scope:** `team:write` · **Who:** a suspension: whoever can suspend; someone fired: Sales Admin or Super Admin

| Name | Type | Required | Description |
|---|---|---|---|
| `notes` | string | no | Up to 1,000 characters |

### DELETE /users/{id}

**Scope:** `team:write` · **Who:** Sales Admin, Super Admin

| Name | Type | Required | Description |
|---|---|---|---|
| `confirm` | boolean | yes | Must be `true` |

Only accounts with no business history can be deleted. Otherwise the response is `409`:

```json
{
  "message": "John Doe can't be deleted because they have history. Fire them instead, so these records are kept.",
  "blockers": ["12 leads", "3 payout statements"]
}
```

### GET /invitations

**Scope:** `team:read` · **Who:** people who can invite (admins, Sales Managers)

| Name | Type | Required | Description |
|---|---|---|---|
| `status` | string | no | `pending`, `accepted`, `expired` or `revoked` |
| `q` | string | no | Search name or email |

```json
{
  "data": [
    {
      "id": 7,
      "name": "Lucy Wanjiku",
      "email": "lucy@tourlast.com",
      "phone": null,
      "role": "salesperson",
      "role_label": "Salesperson",
      "region": "Nairobi",
      "status": "pending",
      "invited_by": { "id": 2, "name": "Grace Njeri" },
      "expires_at": "2026-10-04T09:00:00+03:00",
      "last_sent_at": "2026-09-27T09:00:00+03:00",
      "accepted_at": null,
      "revoked_at": null,
      "created_at": "2026-09-27T09:00:00+03:00"
    }
  ],
  "links": { "…": "…" },
  "meta": { "…": "…" }
}
```

### POST /invitations

**Scope:** `team:write` · **Who:** admins (any role they may assign), Sales Managers (salespeople only)

| Name | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Full name |
| `email` | string | yes | Must not already have an account |
| `role` | string | yes | A role you may assign |
| `phone` | string | no | Up to 32 characters |
| `region` | string | no | Up to 100 characters |

Emails a single-use link valid for 7 days and returns `201` with the invitation. Any earlier pending invitation for the same email is cancelled.

### POST /invitations/{id}/resend

**Scope:** `team:write` · Sends a fresh link; the old one stops working. `422` if already accepted; `403` for invitations to roles you cannot assign.

### DELETE /invitations/{id}

**Scope:** `team:write` · Cancels a pending invitation. `422` if it is no longer pending.

### GET /team/performance

**Scope:** `team:read` · **Who:** Super Admin, Sales Admin, Sales Manager

| Name | Type | Required | Description |
|---|---|---|---|
| `period` | string | no | `week`, `month` (default), `quarter` or `year` |
| `region` | string | no | Only salespeople in this region |

```json
{
  "data": [
    {
      "user": { "id": 14, "name": "John Doe", "role": "salesperson", "avatar_url": null },
      "region": "Nairobi",
      "metrics": { "target": 30, "points": 24, "approvedPoints": 18, "onboarded": 7, "awaiting": 3, "clicks": 41, "conversion": 17.1, "lastActivityAt": "2026-09-26T15:20:00+03:00" },
      "status": { "label": "On track", "tone": "brand" },
      "progress": 0.8,
      "expected_pay": 10400
    }
  ],
  "meta": {
    "period": { "key": "month", "label": "September 2026", "from": "2026-09-01", "to": "2026-09-30" },
    "pay_visible": true,
    "totals": { "onboarded": 23, "points": 81, "target": 120, "awaiting": 9, "clicks": 140, "needs_attention": 2 },
    "regions": ["Coast", "Nairobi", "Western"]
  }
}
```

`expected_pay` is only filled for people who may see team earnings, and only for `period=month`.

### GET /team/targets

**Scope:** `team:read` · **Who:** Super Admin, Sales Admin, Sales Manager

| Name | Type | Required | Description |
|---|---|---|---|
| `month` | string | no | `YYYY-MM`, default this month |

```json
{
  "data": [
    { "user": { "id": 14, "name": "John Doe", "avatar_url": null }, "target": 30, "target_set_at": "2026-09-02T08:10:00+03:00", "onboarded": 7 }
  ],
  "meta": { "month": "2026-09", "locked": true, "locks_on": "2026-09-07", "missing": 1 }
}
```

Targets are set by each salesperson (`PUT /me/target`); managers can only read them.

## Admin: API tokens

Sales Admins and Super Admins can issue tokens for any user — for example a service account for tourlast.com or a reporting tool — and revoke any token. Only a Super Admin can issue or revoke a Super Admin's tokens. Tokens can also be managed in the Hub under **Admin → API tokens**.

### GET /admin/tokens

**Scope:** `team:write` · **Who:** Sales Admin, Super Admin

| Name | Type | Required | Description |
|---|---|---|---|
| `user_id` | integer | no | Only this person's tokens |

```json
{
  "data": [
    {
      "id": 41,
      "name": "tourlast.com push",
      "scopes": ["integration:push"],
      "last_used_at": "2026-09-27T09:40:12+03:00",
      "expires_at": null,
      "created_at": "2026-09-20T10:00:00+03:00",
      "issued_by": { "id": 1, "name": "Super Admin" },
      "current": false,
      "owner": { "id": 30, "name": "tourlast.com integration", "role": "super-admin" }
    }
  ],
  "meta": { "current_page": 1, "last_page": 1, "per_page": 25, "total": 1 }
}
```

### POST /admin/tokens

**Scope:** `team:write` · **Who:** Sales Admin, Super Admin

| Name | Type | Required | Description |
|---|---|---|---|
| `user_id` | integer | yes | Who the token acts as (must be active) |
| `name` | string | yes | What it is for, e.g. "Power BI" |
| `scopes` | array | yes | One or more scopes from `GET /meta` |
| `expires_in_days` | integer | no | 1–730; omit for no expiry |

```json
{
  "token": "41|p0WgE2sN9…",
  "token_type": "Bearer",
  "data": { "id": 41, "name": "Power BI", "scopes": ["reports:read"], "expires_at": "2026-12-26T09:00:00+03:00", "owner": { "id": 22, "name": "Reporting", "role": "accounts" } }
}
```

The plain-text `token` is shown **once**; store it securely. A token never grants more than its owner can do in the Hub.

### DELETE /admin/tokens/{id}

**Scope:** `team:write` · Revokes the token immediately.

---

## Insights and reports

Why properties say no, the Partner Register, and the same Excel and PDF files the Hub produces. Base URL `https://sales.tourlast.com/api/v1`. All endpoints need the `reports:read` scope.

### GET /insights/objections

**Scope:** `reports:read` · **Who:** Super Admin, Sales Admin, Sales Manager

The team view of **Lost & objections**: lost leads plus lost or rejected registry records (a lead whose registry property is also counted is counted once).

| Name | Type | Required | Description |
|---|---|---|---|
| `period` | string | no | `90` (last 90 days), `quarter`, `year` (default) or `all` |

```json
{
  "data": {
    "period": { "key": "year", "label": "This year" },
    "summary": {
      "properties_lost": 8,
      "top_objection": { "value": "commission", "label": "Commission" },
      "competitor_share_percent": 38,
      "lost_without_reason": 0,
      "upcoming_reengagements": 6
    },
    "by_objection": [
      { "value": "commission", "label": "Commission", "count": 4, "percent": 50 },
      { "value": "other_ota", "label": "Already using another OTA", "count": 3, "percent": 38 }
    ],
    "by_competitor": [{ "name": "Expedia", "count": 2 }, { "name": "Booking.com", "count": 1 }],
    "by_salesperson": [{ "name": "Peter Kamau", "count": 2, "top_objection": "Commission" }],
    "by_property_type": [{ "label": "Villa", "count": 2 }],
    "upcoming_reengagements": [
      {
        "name": "Hemingways Watamu",
        "salesperson": "Mary Wambui",
        "objection": "has_pms",
        "objection_label": "Already has a PMS",
        "reengage_on": "2026-10-17",
        "lost_on": "2026-06-30",
        "web_url": "https://sales.tourlast.com/registry/12"
      }
    ],
    "losses": [
      {
        "kind": "lead",
        "id": 88,
        "name": "Tembo Apartments",
        "property_type": "Villa",
        "salesperson": "Peter Kamau",
        "objection": "commission",
        "objection_label": "Commission",
        "competitor": null,
        "notes": "Commission too high compared with direct bookings.",
        "lost_on": "2026-09-14",
        "web_url": "https://sales.tourlast.com/leads/88"
      }
    ]
  }
}
```

`kind` is `lead` or `registry`. `upcoming_reengagements` covers the next 120 days, including overdue dates.

### GET /me/losses

**Scope:** `reports:read` · **Who:** people who sell (salespeople, and selling managers)

The same structure as `GET /insights/objections`, limited to the caller's own lost leads and the lost registry properties they represent.

| Name | Type | Required | Description |
|---|---|---|---|
| `period` | string | no | `90`, `quarter`, `year` (default) or `all` |

### GET /partners

**Scope:** `reports:read` · **Who:** Super Admin, Sales Admin, Sales Manager, HR, Accounts

The Partner Register: providers onboarded on tourlast.com through a salesperson's referral link.

| Name | Type | Required | Description |
|---|---|---|---|
| `status` | string | no | `onboarded` (default, live), `approved`, `awaiting`, `rejected` or `all` |
| `salesperson` | integer | no | User ID |
| `type` | string | no | Property type from `GET /meta` |
| `from`, `to` | date | no | `YYYY-MM-DD`. Filters by onboarding date for `onboarded`, otherwise by signup date |
| `q` | string | no | Property name, location or referral code |
| `per_page` | integer | no | 1–100, default 25 |

```json
{
  "data": [
    {
      "id": 301,
      "tourlast_property_id": "TL-00842",
      "property_name": "ABC Hotel",
      "property_type": "hotel",
      "property_type_label": "Hotel",
      "location": "Nairobi, Kenya",
      "contact_name": "Jane Mwangi",
      "contact_phone": "+254700000000",
      "contact_email": "gm@abchotel.co.ke",
      "status": "active",
      "status_label": "Live",
      "ref_code": "TL-JOHN-2847",
      "attribution": "referral",
      "salesperson": { "id": 14, "name": "John Doe" },
      "submitted_at": "2026-09-20T09:41:00+03:00",
      "onboarded_at": "2026-09-25T10:12:00+03:00"
    }
  ],
  "meta": {
    "current_page": 1, "last_page": 1, "per_page": 25, "total": 1,
    "filters": { "status": "onboarded" },
    "period": "All time",
    "by_type": { "hotel": 1 }
  }
}
```

`attribution` is `referral` or `manual` (credited by an admin with a recorded reason).

### GET /reports/partner-register.xlsx · GET /reports/partner-register.pdf

**Scope:** `reports:read` · **Who:** Super Admin, Sales Admin, HR, Accounts ("export Partner Register")

Accept the same filters as `GET /partners` and return the same Excel workbook / branded PDF report as the Hub. Send `Accept: application/json` only if you want JSON errors; a successful response is a file download (`Content-Disposition: attachment`).

```bash
curl -H "Authorization: Bearer $TOKEN" -o register.xlsx \
  "https://sales.tourlast.com/api/v1/reports/partner-register.xlsx?status=onboarded&from=2026-09-01&to=2026-09-30"
```

### GET /reports/registry.xlsx · GET /reports/registry.pdf

**Scope:** `reports:read` · **Who:** Super Admin, Sales Admin, Sales Manager (registry export)

The Property Engagement Registry export and management report. Filters match `GET /registry` (`q`, `type`, `stage`, `status`, `rep`, `source`, `country`, `region`, `city`, `first_from`, `first_to`, `last_from`, `last_to`, `activity`, `onboarded`). The PDF also takes the reporting period `from` and `to` (`YYYY-MM-DD`, default this month).

Errors for all reports: `403` if the token owner may not see or export the data, `403` with `required_scopes` if the token lacks `reports:read`.

---

## tourlast.com integration

tourlast.com can **push** provider signups and status changes to the Hub through the API. This is an alternative to giving the Hub a read-only database user or building the JSON endpoint described in `docs/TOURLAST_INTEGRATION.md`: with push, the Hub needs no access to tourlast.com at all. The scheduled sync and the signed webhook keep working and can be used alongside it.

Base URL `https://sales.tourlast.com/api/v1`.

### Setting it up (for the tourlast.com developer)

1. **Get the integration token.** On the Sales Hub server, a Tourlast administrator runs `php artisan hub:create-integration-account`. It creates a dedicated **integration account** that has no role, no usable password and only one permission (pushing provider records), and prints a token limited to `integration:push`. Store the token as a secret on the tourlast.com servers. To replace it later, run the command again with `--rotate` (old tokens stop working immediately).
2. **Keep capturing the referral code.** On `/list-your-property`, read `?ref=` and store it on the provider (see `docs/TOURLAST_INTEGRATION.md`, steps 1–2).
3. **Send every change.** Whenever a provider signs up, changes status, or its inventory changes, send the provider record to `POST /integrations/tourlast/providers`. Batching up to 100 records per request is fine (for example from a queue job every minute).
4. **Retry on failure.** A `5xx` response or network error means nothing was saved for that request; send it again. Re-sending a record is safe: the Hub updates the existing onboarding (matched on `property_id`) and never creates a duplicate.
5. **Check the result.** Each record in the response has either a `result` or an `error`. Log errors; they are usually a missing `property_id`.
6. **Switch the Hub to push mode.** Set `TOURLAST_SOURCE=push` in the Hub's `.env` so its scheduled read sync is skipped.

### POST /integrations/tourlast/providers

**Scope:** `integration:push` · **Who:** the integration account (permission *push provider records*; Super Admins also have it)

Send one record as `provider`, or up to 100 as `providers`:

```bash
curl -X POST "https://sales.tourlast.com/api/v1/integrations/tourlast/providers" \
  -H "Authorization: Bearer $SALES_HUB_TOKEN" \
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
        "rejected_at": null,
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

Provider fields:

| Field | Type | Required | Description |
|---|---|---|---|
| `property_id` | string | yes | tourlast.com's unique, stable property ID |
| `ref_code` | string | recommended | The referral code the provider arrived with, e.g. `TL-JOHN-2847` (case-insensitive). Without it the signup waits under **Unattributed** |
| `property_name` | string | yes | |
| `property_type` | string | yes | Mapped to Hub types with `type_map` in `config/tourlast.php`; unknown values become `other` |
| `location` | string | no | Free text, e.g. "Diani, Kenya" |
| `contact_name`, `contact_phone`, `contact_email` | string | recommended | Link the signup to the salesperson's lead and feed duplicate checks |
| `status` | string | yes | Mapped with `status_map`: submitted, under review, approved, active (live) or rejected |
| `submitted_at`, `approved_at`, `active_at`, `rejected_at` | ISO 8601 | as they happen | `active_at` is the **Activation Date**: it decides the month and bonus week points are credited to |
| `updated_at` | ISO 8601 | recommended | Records older than the last one the Hub saw are ignored, so out-of-order deliveries are safe |
| `account_id`, `legal_name` | string | for incentives | Every property of one legal business must share `account_id` |
| `category` | string | for incentives | `stay` or `experience` |
| `inventory_count` | integer | for incentives | Live rooms/units (stays) or bookable services (experiences) |
| `first_booking_at` | ISO 8601 | optional | Triggers the "First booking received" alert |

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

`result` is `created`, `updated` or `unchanged`. Crediting, status history, Smart Alerts, partner accounts and registry stage updates all run exactly as for the scheduled sync.

Errors: `403` with `required_scopes` if the token lacks `integration:push`; `403` if the token owner lacks the *push provider records* permission (ordinary staff accounts never have it); `422` if neither `provider` nor `providers` is sent, or more than 100 records.

### GET /integrations/tourlast/sync-runs

**Scope:** `integration:read` · **Who:** Super Admin

The sync log (scheduled and manual pulls from tourlast.com), newest first.

```json
{
  "data": [
    {
      "id": 912,
      "source": "database",
      "mode": "incremental",
      "status": "succeeded",
      "changed_since": "2026-09-27T09:20:00+03:00",
      "records_seen": 4,
      "records_created": 1,
      "records_updated": 3,
      "error": null,
      "started_at": "2026-09-27T09:30:00+03:00",
      "finished_at": "2026-09-27T09:30:02+03:00"
    }
  ],
  "links": { "…": "…" },
  "meta": { "…": "…" }
}
```

### POST /integrations/tourlast/sync

**Scope:** `integration:push` · **Who:** Super Admin

Runs a pull from tourlast.com now, using the configured source (`TOURLAST_SOURCE`).

| Name | Type | Required | Description |
|---|---|---|---|
| `mode` | string | no | `incremental` (default, changes since the last run) or `full` |

Returns the sync run (as above) with `200`, or `502` if the sync failed (see `error`).
