# Production setup: Sales Hub, Stays and Experiences

How to take the referral pipeline from your local machine to production. Follow the steps in order. Anything marked **STOP** needs a human check before you continue.

The pipeline in one line: a salesperson gets a referral code in the Hub, a provider signs up on Stays or Experiences with that code, the Hub pulls the provider from each app, and credits the salesperson who owns the code.

```
Stays  ──feed──▶  Sales Hub  ◀──feed──  Experiences
  ▲                  │                     ▲
  └──── owner lookup ┴──── owner lookup ───┘
```

Every request between the apps carries one shared token. A missing or wrong token is rejected with 401. An app with no token set answers 503, so it never runs open.

Related guides: `docs/DEPLOYMENT_AWS.md` (server, SES, Supervisor), `docs/TOURLAST_INTEGRATION.md` (feed contract), `docs/SOURCE_APPS_HANDOFF.md`.

---

## 0. Before you start

- [ ] Server access for all three apps, and the three production URLs (written below as `HUB_URL`, `STAYS_URL`, `EXPERIENCES_URL`).
- [ ] **Back up every production database.** Steps 3 to 5 change tables.
- [ ] All three apps use HTTPS. The token travels in a header, so never use plain HTTP in production.
- [ ] You know which branch each app deploys from:

| App | Branch with this work | Repo |
|---|---|---|
| Sales Hub | `delphine` | `delphinekingori/tourlast-sales-hub` |
| Stays | `sales-hub` | `dev-tourlast/hotelPMS_V1` |
| Experiences | `sales-hub` | `dev-tourlast/experiences-v1` |

Check these for open pull requests or merges into your deploy branch before deploying.

---

## 1. Create the production token (once)

Do **not** reuse the local development token. It sits in `.env` files on developer machines.

On the Hub server:

```bash
php artisan hub:generate-token
php artisan hub:generate-token --show    # prints the value without changing it
```

This writes `TOURLAST_API_TOKEN` into the Hub's `.env`. Copy the value into a password manager. You will paste the **same value** into Stays and Experiences in the next steps.

One token covers everything. If it ever leaks, change it in all three places at once.

---

## 2. Experiences

### 2.1 Settings (`.env`)

```dotenv
TOURLAST_HUB_TOKEN=<the token from step 1>
TOURLAST_HUB_URL=<HUB_URL>
```

`TOURLAST_HUB_TOKEN` protects the feed the Hub reads. `TOURLAST_HUB_URL` lets Experiences ask the Hub who owns a referral code, so the admin pages can show "Referred by". Without the URL nothing breaks, the owner name just does not appear.

### 2.2 Migrations

**STOP.** Experiences once renamed its tables (`experience_providers` became `providers`). The new referral column needs the renamed table. Check first:

```bash
php artisan migrate:status | grep -E "rename_experience_tables|add_ref_code"
```

- If `2026_08_20_121828_rename_experience_tables` shows **Ran**, continue.
- If it shows **Pending**, the live database is behind the code. Do not run everything blindly. Preview it:

```bash
php artisan migrate --pretend
```

Read the output. On the local development database, 16 migrations were pending, including a chat table rebuild and a card details change. Only run them on production if you know the code already expects them.

Then run:

```bash
php artisan migrate --force
```

This adds `providers.ref_code` (text, up to 40 characters, indexed). Existing providers keep an empty code. Nothing is rewritten.

### 2.3 Clear caches and check

```bash
php artisan config:clear
php artisan route:clear
php artisan optimize
```

Verify (replace `<TOKEN>`):

```bash
curl -s -o /dev/null -w "%{http_code}\n" <EXPERIENCES_URL>/api/sales-hub/referrals                                  # expect 401
curl -s -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer wrong" <EXPERIENCES_URL>/api/sales-hub/referrals # expect 401
curl -s -o /dev/null -w "%{http_code}\n" -H "Authorization: Bearer <TOKEN>" <EXPERIENCES_URL>/api/sales-hub/referrals # expect 200
```

The feed is limited to 60 requests per minute per address, before the token check, so repeated wrong guesses return 429.

---

## 3. Stays

### 3.1 Settings (`.env`)

```dotenv
TOURLAST_HUB_TOKEN=<the token from step 1>
TOURLAST_HUB_URL=<HUB_URL>
```

### 3.2 Migrations

Two new migrations come with this branch:

| Migration | What it does |
|---|---|
| `2026_09_23_090000_add_currency_snapshot_to_settlements_and_payout_transactions` | Adds `currency` and `usd_exchange_rate` to settlements and payout transactions. Backfills currency from the linked bank account. Old rows get a placeholder rate of 1.0. |
| `2026_10_02_100000_add_hub_columns_to_hotels_and_apartments` | Adds `referral_code` and `inactive_at` to hotels and apartments, `approved_at` to apartments, and backfills referral codes from existing staff referrals and pending registrations. |

**STOP.** The currency migration changes money tables. Preview it, and confirm with whoever owns settlements that the placeholder rate is acceptable:

```bash
php artisan migrate --pretend
php artisan migrate --force
```

### 3.3 Clear caches and check

```bash
php artisan config:clear
php artisan optimize
```

Run the same three `curl` checks as in 2.3 against `<STAYS_URL>`.

Then spot check real data: the feed should list your real hotels, and `ref_code` should be filled in for hotels whose owner registered through a referral link. Hotels that never had a code show `null`. That is expected.

Apartments are in the feed. Apartment `first_booking_at` is always empty because Stays has no apartment bookings table.

---

## 4. Sales Hub

### 4.1 Settings (`.env`)

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=<HUB_URL>

TOURLAST_SOURCE=api
TOURLAST_API_URL=<STAYS_URL>,<EXPERIENCES_URL>
TOURLAST_API_PATH=/api/sales-hub/referrals
TOURLAST_API_TOKEN=<the token from step 1>
TOURLAST_SYNC_EVERY_MINUTES=10
TOURLAST_FULL_SYNC_AT=02:00
```

Important:
- `TOURLAST_SOURCE` must be `api`. A value of `sandbox` makes the first sync fail in production on purpose. Sample data is blocked outside local and test environments.
- `TOURLAST_API_URL` is a comma separated list, with one entry per source app.
- Use a real database (MySQL), not SQLite. Set `DB_*`, `MAIL_*` and `QUEUE_CONNECTION=database` as described in `docs/DEPLOYMENT_AWS.md`.
- Live notifications use Reverb, which runs as a separate server. Set `BROADCAST_CONNECTION=reverb` and fill `REVERB_*` and `VITE_REVERB_*` from `.env.example`, using the same app id, key and secret as that server. Left empty, the bell still works, but it only refreshes by polling.

### 4.2 Deploy, migrate, seed

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=IncentivePolicySeeder --force
php artisan optimize
```

- The first seeder creates the 6 roles and 27 permissions. Without them nobody can sign in with the right access.
- The second creates the Schedule 1 pay policy. The app falls back to it in memory without the seed, but a payout statement would store an empty policy id.
- Both seeders are safe to run again.
- Do **not** seed demo data. The demo seeder refuses to run outside local and test environments.

### 4.3 Create the first admin

```bash
php artisan hub:create-super-admin
```

It asks for a name, email and password. It refuses to run if a Super Admin already exists. Use a real company email. Do not use an `@tourlast.test` address (see step 7).

### 4.4 Scheduler and queue

Add a cron entry:

```cron
* * * * * cd /var/www/sales-hub && php artisan schedule:run >> /dev/null 2>&1
```

Keep a queue worker running under Supervisor. Both are shown in `docs/DEPLOYMENT_AWS.md`. The scheduler runs the provider sync every 10 minutes, a full re-check nightly, the monthly statement drafts, and the daily and manager alerts.

### 4.5 First sync

```bash
php artisan hub:sync-tourlast --full
```

Expect a line like `Synced from api: N seen, N new`. Then check:

```bash
php artisan tinker --execute 'echo App\Models\Onboarding::count();'
```

If it fails with 401, the token differs between the apps. If it fails with 503, one side has no token set.

**STOP.** On a brand new production database there is no demo data and nothing to clean up. If this database was ever seeded locally and copied, do step 7 before anyone looks at the dashboard.

---

## 5. Create salespeople and their referral codes

In the Hub, sign in as the Super Admin and invite each salesperson (Team page). Each person with the salesperson role gets a code like `TL-JOHN-2847`.

A code can be changed on the Team page. Be careful with that: **rename a code only when you have to.** Providers already registered under the old code keep sending the old code. The Hub keeps their existing credit, but new sign-ups using the old link arrive unattributed and a Sales Admin has to assign them by hand.

Incentive pay needs an **incentive agreement** for each salesperson (Admin, Incentives page, `/admin/incentives`). Only people with an agreement get a monthly payout statement.

---

## 6. Prove it end to end

Do this once on production with a throwaway provider, using a real salesperson's code:

1. Open the Experiences provider sign-up link with the code: `<EXPERIENCES_URL>/provider/register?ref=<CODE>`.
2. Register a test provider named "REF TEST Provider". Approve it in the Experiences admin.
3. Open the Stays sign-up link with the same code: `<STAYS_URL>/list-your-property?ref=<CODE>`. Register a test hotel named "REF TEST Hotel", complete onboarding, and approve it as an admin.
4. On the Hub server: `php artisan hub:sync-tourlast`.
5. In the Hub, both test providers appear **attributed to that salesperson** (not in the "unattributed" list).
6. In the source apps' admin, the provider and hotel pages show "Referred by <salesperson> (<CODE>)".
7. Delete the two test rows when done.

If a provider shows as unattributed, check, in order: the provider has the code saved, the feed returns it (`curl` with the token), the code exists and is active in the Hub, and the sync ran.

---

## 7. Demo and test data

On production there should be none. Check:

```bash
php artisan hub:purge-demo-data
```

This is a dry run. It only lists what it would delete: users whose email ends in `@tourlast.test`, and providers with ids exactly `TL-` followed by five digits, plus the records that depend on them. **Read the list before doing anything else.** Only if it lists real demo leftovers, and you have a backup:

```bash
php artisan hub:purge-demo-data --force
```

Warning: this deletes every user with an `@tourlast.test` email. Never use that domain for real staff on production.

Also remove test data from the source apps' own databases (the seeded example hotels and providers, and the REF TEST rows). The Hub shows exactly what the source apps hold. In local development, 83 of 89 providers came from the source apps' seeded data, not from the Hub.

---

## 8. Mobile apps

The Experiences provider sign-up API accepts the code in the request body:

```
POST /api/v1/provider/register
{ ..., "ref_code": "TL-JOHN-2847" }
```

A mobile app has no cookie, so the app must send `ref_code` itself, taken from the link the user opened. The mobile app code is not in these repositories, so this change has to be made in the mobile app project. Stays' mobile sign-up has not been checked.

---

## 9. How salespeople get paid

The Hub works out pay and records it. It does **not** send money. There is no M-Pesa, bank or payment gateway integration.

1. On the 1st, the Hub drafts a statement for everyone with an incentive agreement covering that month.
2. A Sales Admin confirms four conditions: reports, training, partner follow-up and partner support.
3. Accounts approves the statement. The figures freeze.
4. Accounts pays by M-Pesa or bank, outside the Hub, using the salesperson's saved details (stored encrypted), by the 5th.
5. Accounts marks the statement paid in the Hub and enters the payment reference.

Pay rules come from the Schedule 1 policy in the `incentive_policies` table (retainer KES 7,500 at 18 points and KES 15,000 at 30, weekly and monthly bonuses, exceptional pay above 76 points, airtime up to KES 400, transport claims).

---

## 10. After go-live

- [ ] `<HUB_URL>/up` returns 200.
- [ ] Wrong token is rejected (401) on both feeds and on the Hub's referral code list.
- [ ] The sync log shows a successful run in the last 10 minutes.
- [ ] RDS or database backups are on for all three apps.
- [ ] The production token is stored in a password manager, and only in the three `.env` files.
- [ ] Rotate the token if it was ever pasted in a chat, ticket or email: run `hub:generate-token`, then update both source apps and clear their config caches.

## Known limits

- **Owner names in the source apps** come from the Hub's list of **active** codes. A code deactivated later shows as "not recognised" there.
- **Shared login** across the apps is not done. People use separate accounts until the central identity service holds everyone.
- **Stays customer referrals** (staff codes used when customers register) are a separate system and are unchanged.
- **Deleted and inactive providers:** Stays sends them flagged. Experiences has no provider deactivation yet, so it sends empty values.
- **Renamed codes:** a renamed code never removes credit already given to a provider. New sign-ups that still use the old code arrive unattributed and a Sales Admin assigns them by hand.
- **Signup banners** ("You were referred by ...") on the Stays and Experiences sign-up pages may still be in progress when you read this. Check each app's branch history before deploying.
