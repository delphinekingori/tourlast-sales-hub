# Launch checklist: moving the Sales Hub to a new server

Do these in order. Each step says how to check it worked. Longer background is in `docs/PRODUCTION_SETUP.md` (connecting Stays and Experiences), `docs/DEPLOYMENT_AWS.md` (AWS server, mail, Supervisor) and `docs/SYSTEM_GUIDE.md`.

## 1. The server

- [ ] PHP 8.3 or newer with these extensions: `bcmath`, `ctype`, `curl`, `dom`, `fileinfo`, `gd` (or `imagick`), `intl`, `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `zip`.
- [ ] MySQL 8 (or MariaDB 10.6+). Do not use SQLite on a live site.
- [ ] Composer 2, Node 20+ and npm (only needed to build the assets), and Git.
- [ ] Nginx or Apache serving `public/` as the web root, with HTTPS (a real certificate).
- [ ] Supervisor (to keep the queue worker running) and cron (for the scheduler).
- [ ] The web server user can write to `storage/` and `bootstrap/cache/`.

## 2. Get the code and install

```
git clone <repo-url> /var/www/sales-hub
cd /var/www/sales-hub
git checkout main
composer install --no-dev --optimize-autoloader
npm ci
npm run build
```

Check: `public/build/manifest.json` exists. If you see "Unable to locate file in Vite manifest", the build did not run.

## 3. The `.env` file

```
cp .env.example .env
php artisan key:generate
```

Set these before anything else. Do not commit `.env`.

| Setting | Live value |
| --- | --- |
| `APP_ENV` | `production` |
| `APP_DEBUG` | `false` (never `true` on a live site) |
| `APP_URL` | the real https address |
| `TRUSTED_PROXIES` | set if you sit behind a load balancer or proxy |
| `DB_*` | the MySQL database, user and password |
| `SESSION_DRIVER`, `CACHE_STORE`, `QUEUE_CONNECTION` | `database` (or `redis` if you run Redis) |
| `LOG_STACK` | `daily` (and `LOG_DAILY_DAYS=14`) |
| `MAIL_*` | real mail settings. Invitations, password resets and alerts are sent by mail |
| `FILESYSTEM_DISK`, `AWS_*` | only if uploads go to S3 |

Extra settings for features. Leave the ones you do not use empty.

| Feature | Settings |
| --- | --- |
| Stays and Experiences sync | `TOURLAST_SOURCE=api`, `TOURLAST_API_URL`, `TOURLAST_API_TOKEN` (create it with `php artisan hub:generate-token`), `TOURLAST_WEBHOOK_SECRET`. See `docs/PRODUCTION_SETUP.md` |
| Flights | `FLIGHTS_API_URL`, `FLIGHTS_ADMIN_BOOKING_URL`. The same shared sync token is used |
| M-Pesa package payments | `MPESA_DRIVER=daraja`, `DARAJA_ENVIRONMENT=production`, `DARAJA_CONSUMER_KEY`, `DARAJA_CONSUMER_SECRET`, `DARAJA_SHORTCODE`, `DARAJA_PASSKEY`, `DARAJA_CALLBACK_SECRET`, `DARAJA_CALLBACK_BASE_URL`, `DARAJA_ALLOWED_IPS` (Safaricom callback IPs) |
| Automatic refunds and payouts | `TRAVEL_AUTO_REFUNDS` and `INCENTIVE_AUTO_PAYOUT` stay `false` until Safaricom approves B2C. Then set the `DARAJA_B2C_*` values |
| Live updates in the browser | `REVERB_*` and `VITE_REVERB_*`. Run `npm run build` again after changing the `VITE_` values |
| Maps in the claim form | `GOOGLE_MAPS_API_KEY`, `GOOGLE_MAPS_COUNTRY`. See section 8 |
| Money | `TRAVEL_CURRENCY=KES` |

**Important:** if `MPESA_DRIVER` is left as `sandbox` on a live site, the app refuses to start payments with the message "M-Pesa is in test mode on this live site". This is on purpose, so no one is told a payment went through when it did not.

## 4. Database

```
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan db:seed --class=IncentivePolicySeeder --force
```

- **Do not skip the roles seeder.** Without it the Travel pages fail with `There is no role named travel-salesperson for guard web`. It is safe to run again, and you must run it after every update that adds a role or permission.
- Do **not** run `DemoSeeder` or `TravelDemoSeeder` on a live site. They create fake people and bookings. If demo data got in by mistake, `php artisan hub:purge-demo-data` removes the sample people and codes.

Check: `php artisan tinker --execute 'echo Spatie\Permission\Models\Role::count();'` shows the roles (the six Sales Hub roles plus the Travel roles).

## 5. First admin

```
php artisan hub:create-super-admin
```

Follow the prompts. It also makes sure the roles exist. Sign in and change the password.

## 6. Storage and caches

```
php artisan storage:link
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

If something looks wrong after a settings change, run `php artisan optimize:clear` and then the cache commands again. After any `.env` change, run `php artisan config:cache`.

## 7. Scheduler, queue and websockets

1. Cron entry (runs the scheduler every minute):

   ```
   * * * * * cd /var/www/sales-hub && php artisan schedule:run >> /dev/null 2>&1
   ```

   The scheduler runs the provider sync every 10 minutes, a nightly full re-check, flight and booking jobs, reminders, the daily and manager alerts, and the monthly statement drafts.

2. Queue worker under Supervisor (restart it after every deploy with `php artisan queue:restart`):

   ```
   [program:sales-hub-worker]
   command=php /var/www/sales-hub/artisan queue:work --sleep=3 --tries=3 --max-time=3600
   autostart=true
   autorestart=true
   user=www-data
   numprocs=1
   ```

3. If you use live updates, run Reverb under Supervisor too: `php artisan reverb:start`. Proxy the websocket port through your web server.

Check: `php artisan schedule:list` shows the jobs, and a test job is picked up by the worker.

## 8. Connect the other apps

Follow `docs/PRODUCTION_SETUP.md` sections 1 to 6. In short:

- [ ] Create the sync token with `php artisan hub:generate-token` and put it in the Stays and Experiences apps.
- [ ] Set the webhook secret in all three apps.
- [ ] Run the first sync: `php artisan hub:sync-tourlast`. Check the sync run finishes and does not stay "running".
- [ ] Ask the source apps to send the `?ref=` referral code and save it with each provider. Without it, providers show as unattributed.
- [ ] M-Pesa: after the Daraja settings are in, run `php artisan travel:mpesa-register-urls`, then take one small real payment to test.

**Google Maps (claim form):** in Google Cloud turn on billing, then enable Maps JavaScript API, Places API (New) and Geocoding API. Restrict the key to this site's address. Without it the place fields stay plain text, which still works.

## 9. Security check before you open the doors

- [ ] `APP_DEBUG=false` and `APP_ENV=production`.
- [ ] The site only loads on HTTPS, and HTTP redirects to it.
- [ ] `.env`, `storage/` and `vendor/` are not reachable from the web.
- [ ] The default or demo passwords (`password`) exist nowhere on this server.
- [ ] `DARAJA_ALLOWED_IPS` is set, and `DARAJA_CALLBACK_SECRET` is a long random value.
- [ ] Database backups run daily and a restore has been tried once.
- [ ] Log files rotate (`LOG_STACK=daily`) and someone looks at the error log.
- [ ] Mail works: send a password reset to yourself.

## 10. Smoke test after launch

Sign in as each kind of user and check:

1. Admin: the dashboard, Team, and Integration pages load.
2. Sales manager: the onboardings list and the unattributed list.
3. Salesperson: add a lead, make a claim (try the place search), see referral codes.
4. Travel salesperson: `/travel`, create a package and a booking (one guest, then add another), pick an influencer code.
5. Run a sync and watch new providers arrive.
6. Make one small M-Pesa payment and see it matched to a booking.

## 11. Every later deploy

```
php artisan down
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan optimize:clear
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan event:cache
php artisan queue:restart
php artisan up
```

## Quick fixes

| What you see | Do this |
| --- | --- |
| `There is no role named ... for guard web` | Run the roles seeder (step 4), then `php artisan permission:cache-reset` |
| "Unable to locate file in Vite manifest" | Run `npm run build` |
| Changes to `.env` have no effect | `php artisan config:clear` then `php artisan config:cache` |
| Pages load but jobs, mail or syncs never run | The cron entry or the queue worker is not running (step 7) |
| Providers show as unattributed | The source apps are not sending `ref_code` (step 8) |
| "M-Pesa is in test mode on this live site" | Set `MPESA_DRIVER=daraja` and the Daraja settings |
| Map fields stay plain text | Add the Google key and turn on billing and the three APIs |
