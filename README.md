# Tourlast Sales Hub

A private sales and partner-onboarding tracker for the Tourlast team. Providers sign up on **tourlast.com** through a salesperson's referral link. The Hub credits each signup to that salesperson, shows everyone their progress against the monthly target they set themselves, and gives HR and Accounts a register of onboarded partners with Excel and PDF exports.

A separate **Travel Sales** workspace lets travel salespeople sell flights, tours and experiences: providers and contracts, packages with Sales Admin + Super Admin approval and version control, departures and bookings, M-Pesa (Daraja) payments, influencer referral codes with commission, and a read-only copy of flight bookings from Tourlast Flights Super Admin. See section 4 of [docs/SYSTEM_GUIDE.md](docs/SYSTEM_GUIDE.md).

**Stack:** Laravel 13, Livewire 4, Tailwind CSS 4, MySQL in production (SQLite locally), spatie/laravel-permission, Laravel Sanctum (API tokens), maatwebsite/excel, barryvdh/laravel-dompdf.

## Local setup

```bash
composer install
npm install && npm run build
cp .env.example .env && php artisan key:generate
# demo data is opt-in: set APP_ENV=local and TOURLAST_SOURCE=sandbox first.
# With TOURLAST_SOURCE=api (the default) a fresh database holds only roles and policy rows.
php artisan migrate --seed
```

Demo accounts are created only when `APP_ENV=local`. Every password is `password`:

| Email | Role |
|---|---|
| admin@tourlast.test | Super Admin |
| grace@tourlast.test | Sales Admin |
| david@tourlast.test | Sales Manager |
| john@tourlast.test, mary@tourlast.test, peter@tourlast.test, james@tourlast.test | Salesperson |
| aisha@tourlast.test, kevin@tourlast.test | Travel Salesperson |
| faith@tourlast.test | HR |
| samuel@tourlast.test | Accounts |

In production there is no public registration. Create the first admin with `php artisan hub:create-super-admin` (it prompts for name, email and password, and refuses if a Super Admin already exists), then invite everyone else from **Admin → Users & Invites**.

## Key rules

- **Onboarded** follows the incentive policy (Schedule 1): a provider counts on its **Activation Date**, the day it goes live on tourlast.com. A rejection removes the credit.
- **Targets** are in points and set by each salesperson (18 and 30 are suggested because they unlock the retainer). They can be changed until the 7th of the month (`HUB_TARGET_LOCK_DAY`); managers can see targets but not change them.
- **Referral codes** such as `TL-JOHN-2847` are permanent. Tracked links (`/r/{code}`) count clicks, then redirect to `HUB_LIST_PROPERTY_URL?ref={code}`.
- **Signups without a code** wait under **Unattributed**. A Sales Admin can assign one with a written reason, which is kept on record.

## Incentives (Schedule 1)

- **Partner Accounts** group every property of one legal business. Points come from verified rooms/units (stays: 1–10 → 1, 11–50 → 3, 51–100 → 5, 101–200 → 7, 201+ → 9) or bookable services (experiences: 1–5 → 1 … 51+ → 5).
- Points start **provisional** and become **approved** when a Sales Admin completes the 13-item qualification checklist and verifies the Account. Every change is written to the points history; old lines are cancelled, never edited.
- **My Earnings** shows each salesperson, live, the expected retainer (18/30 points), four fixed bonus weeks (18+ points = KES 1,500), the Monthly Bonus bands, the Exceptional-Performance payment (KES 300 per point above 76, capped at KES 10,000), airtime (KES 400 cap) and transport.
- **90-day expansion**: growth to a higher category earns the difference; 50%+ growth in the same category earns one 0.5 award; caps of 9 (stays) and 5 (experiences). Only while the salesperson's incentive agreement is in force.
- **14-day review**: a failed Account loses its points; anything already paid is recovered on the next statement.
- **Claims**: airtime (straight to Finance), transport reimbursements with receipts or Bolt/Uber trip ID and ride details, and transport requests before a trip. Transport is approved by the Sales Manager, then HR, then Finance.
- **Payouts**: draft statements on the 1st; a Sales Admin confirms the retainer conditions, Accounts approves (figures freeze) and marks them paid by the 5th. PDF statements include the paragraph 13 report.
- Pay tables live in `incentive_policies` (seeded from `App\Incentives\Policy::schedule1()`). The approval chain is in `config/incentives.php`.

## People, pay details and notifications

- **Payout details**: salespeople choose M-Pesa (number + registered name) or a bank account (bank, account number, account name) on My Earnings. Stored encrypted; visible under **Payment details** to admins, HR and Finance only (not Sales Managers). HR and Finance get an alert on every change.
- **Profiles**: everyone can upload a photo and set their position, bio and emergency contact under My profile. Admins, Sales Managers, HR and Finance browse colleagues under **People**.
- **Online status**: anyone active in the last five minutes shows as Online (badge in their own top bar; dot and pill for admins, Sales Managers, HR and Finance). Signing out clears it.
- **Notifications**: announcements can be written by admins, Sales Managers, HR and Finance and targeted by role; salespeople read only. **Smart Alerts** (new property referred, new onboarding submitted, partner approved/rejected, deal won/lost, contract expiring, follow-up overdue, property inactive, first booking) go to management and to the salesperson concerned. `php artisan hub:send-daily-alerts` runs daily at 07:00.

## Roles

| Role | Can do |
|---|---|
| Super Admin | Everything, including Admin → Integration |
| Sales Admin | Manage users and invites, see all sales data, assign unattributed signups, verify Accounts and run reviews, confirm retainer conditions, approve claims as manager, manage agreements, Partner Register and exports |
| Sales Manager | Team Performance and Targets (points, not pay), view all leads and Accounts, invite salespeople, first approval of transport claims |
| Salesperson | Own progress, earnings, Accounts, onboardings, leads, activities and claims only |
| Travel Salesperson | Travel Sales only: own providers, contracts, packages (submit for approval, never approve), bookings, payments requests, influencer codes; reads all flights. Invisible to Sales Managers |
| HR | Partner Register, everyone's earnings, incentive agreements, second approval of transport claims |
| Accounts (Finance) | Partner Register, everyone's earnings, final approval of claims, transport disbursement, approve and pay statements |

## Handover documents

- **[docs/SYSTEM_GUIDE.md](docs/SYSTEM_GUIDE.md):** the complete guide: how every module works, roles, integration status, configuration, deployment, and pending work and open decisions. Start here.
- **[docs/API.md](docs/API.md):** the Sales Hub REST API (v1): tokens and scopes, every endpoint with examples, errors, and the tourlast.com push integration. OpenAPI 3.1 file: `docs/api/openapi.json`.
- **[docs/TOURLAST_INTEGRATION.md](docs/TOURLAST_INTEGRATION.md):** what tourlast.com must add (ref capture, read-only access, optional webhook), with the exact settings.
- **[docs/DEPLOYMENT_AWS.md](docs/DEPLOYMENT_AWS.md):** server, database, email, scheduler and queue setup for `sales-hub.tourlast.com`.

## Useful commands

```bash
php artisan hub:sync-tourlast [--full]     # pull referred providers from tourlast.com now
php artisan hub:send-manager-alerts        # send the managers' daily summary now
php artisan hub:generate-statements [YYYY-MM]  # create or refresh draft payout statements
php artisan hub:create-super-admin           # interactive; first Super Admin only
php artisan hub:purge-demo-data [--force]  # list (default) or delete @tourlast.test users and TL-##### sample rows
php artisan hub:create-integration-account [--rotate]  # least-privilege account + token for tourlast.com push
php artisan hub:api-spec                    # regenerate docs/api/openapi.json from the routes
php artisan travel:sync-flights [--full]     # pull flight bookings from Flights Super Admin (FLIGHTS_SOURCE=api or sandbox)
php artisan travel:create-flights-account [--rotate]  # push-only account + token for Flights Super Admin
php artisan travel:mpesa-register-urls      # print and register the Daraja paybill callback URLs
php artisan travel:mpesa-reconcile          # check unanswered M-Pesa payment requests (scheduled every 5 min)
php artisan travel:mpesa-simulate TB-2026-0001 5000  # test mode: simulate a paybill payment
php artisan travel:expire-holds             # cancel unpaid package bookings whose hold ran out
php artisan travel:contract-alerts          # provider contract expiry alerts
php artisan travel:pretrip-reminders        # missing driver/guide and pre-trip alerts
php artisan test                           # full test suite
```
