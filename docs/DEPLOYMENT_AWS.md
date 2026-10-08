# Deploying Tourlast Sales Hub on AWS

Target address: `https://sales-hub.tourlast.com`.

## What it needs

| Piece | Suggested AWS service |
|---|---|
| Web server with PHP 8.3+, Nginx, Composer, Node 20+ (build only) | EC2 (t3.small is plenty to start) or Lightsail |
| MySQL 8 database | RDS for MySQL (enable automated backups) |
| Email from sales@tourlast.com | Amazon SES (verify the tourlast.com domain) or the company SMTP server |
| HTTPS certificate | Let's Encrypt on the server, or ACM on a load balancer |
| DNS | Route 53 (or your DNS provider): `sales-hub.tourlast.com` → server |

Required PHP extensions: `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `gd`, `intl`, `bcmath`.

## First deployment

```bash
git clone <repo> /var/www/sales-hub && cd /var/www/sales-hub
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && php artisan key:generate
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://sales-hub.tourlast.com

DB_CONNECTION=mysql
DB_HOST=<rds-endpoint>
DB_DATABASE=sales_hub
DB_USERNAME=sales_hub
DB_PASSWORD=<secret>

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
CACHE_STORE=database

# Amazon SES
MAIL_MAILER=ses
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_DEFAULT_REGION=eu-west-1
MAIL_FROM_ADDRESS="sales@tourlast.com"
MAIL_FROM_NAME="Tourlast Sales"

# tourlast.com connection: see docs/TOURLAST_INTEGRATION.md
TOURLAST_SOURCE=api
TOURLAST_API_URL=https://www.tourlast.com
TOURLAST_API_PATH=/api/sales-hub/referrals
TOURLAST_API_TOKEN=...   # shared token both ways — write it with: php artisan hub:generate-token
```

SES needs `composer require aws/aws-sdk-php`. To use the company SMTP server instead, set `MAIL_MAILER=smtp` and the `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME` and `MAIL_PASSWORD` values.

```bash
php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan hub:create-super-admin   # prompts for name, email, password
php artisan storage:link
php artisan optimize
```

If tourlast.com will push provider records to the API instead of the Hub reading tourlast.com, generate the one shared token and give the printed value to the tourlast.com developer (they store it as `TOURLAST_HUB_TOKEN`):

```bash
php artisan hub:generate-token   # also set TOURLAST_SOURCE=push in .env
```

Don't run the plain `db:seed` in production. The demo data only seeds when `APP_ENV=local`, but the roles seeder is all production needs.

## Background processes

The scheduler runs the tourlast.com sync every 10 minutes, the nightly full re-check, and the 07:30 weekday manager summary. A queue worker sends emails.

Add a cron entry for the scheduler:

```cron
* * * * * cd /var/www/sales-hub && php artisan schedule:run >> /dev/null 2>&1
```

Add a Supervisor program for the queue worker:

```ini
[program:sales-hub-worker]
command=php /var/www/sales-hub/artisan queue:work --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=1
stdout_logfile=/var/www/sales-hub/storage/logs/worker.log
```

## Updating

```bash
git pull
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan optimize
sudo supervisorctl restart sales-hub-worker
```

## Checklist before go-live

- [ ] `https://sales-hub.tourlast.com/up` returns 200
- [ ] You can sign in as the Super Admin, invite a test salesperson, and the email arrives from sales@tourlast.com
- [ ] `php artisan hub:sync-tourlast --full` succeeds against tourlast.com
- [ ] The test salesperson's link `/r/<code>` lands on tourlast.com with `?ref=` attached
- [ ] RDS automated backups are on
