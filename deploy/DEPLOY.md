# Deploying Finovo OMS / WMS

Everything needed to put Finovo live on a Linux server (Ubuntu 22.04 or 24.04), keep it fast under load and grow it
when traffic grows. The files referred to are in this `deploy` folder.

## 1. What the server needs

| Part | Version | Why |
| --- | --- | --- |
| PHP-FPM | 8.3 with extensions: mbstring, pdo_mysql, bcmath, intl, zip, curl, xml, gd, redis, opcache | Runs the Laravel API |
| MySQL | 8.0 (or MariaDB 10.11) | The database |
| Redis | 6 or newer | Cache, sessions, rate limits and the job queue in memory |
| nginx | 1.24 or newer | Serves the React app, passes the API to PHP, limits traffic |
| Node.js | 22 | Builds the React app on release |
| Composer, Supervisor, cron, certbot | current | Packages, queue workers, scheduler, HTTPS |

Smallest sensible start: one server with 4 vCPU and 8 GB RAM for app, database and Redis.

## 2. First installation

```bash
# 1. Code
sudo mkdir -p /var/www && cd /var/www
sudo git clone https://github.com/shafisani36/Finovo__OMS__WMS.git finovo
sudo chown -R www-data:www-data finovo && cd finovo

# 2. Database and user (use a long random password)
sudo mysql -e "CREATE DATABASE finovo CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
  CREATE USER 'finovo'@'localhost' IDENTIFIED BY 'CHANGE_ME';
  GRANT ALL ON finovo.* TO 'finovo'@'localhost';"

# 3. Settings: copy the example and fill in every value (see section 3)
cp backend/.env.example backend/.env
nano backend/.env
cd backend && composer install --no-dev --optimize-autoloader && php artisan key:generate && cd ..

# 4. Web server, PHP workers, queue workers, scheduler and backups
sudo cp deploy/nginx-php-snippet.conf /etc/nginx/snippets/finovo-php.conf
sudo cp deploy/nginx.conf /etc/nginx/sites-available/finovo && sudo ln -s /etc/nginx/sites-available/finovo /etc/nginx/sites-enabled/
sudo cp deploy/php-fpm-pool.conf /etc/php/8.3/fpm/pool.d/finovo.conf
sudo cp deploy/supervisor.conf /etc/supervisor/conf.d/finovo.conf
sudo crontab -u www-data deploy/crontab
sudo certbot --nginx -d app.yourdomain.com -d api.yourdomain.com
sudo nginx -t && sudo systemctl reload nginx php8.3-fpm
sudo supervisorctl reread && sudo supervisorctl update

# 5. First release (builds the frontend, runs migrations, caches config) and the first admin
./deploy/deploy.sh
cd backend && php artisan user:create admin@yourcompany.com --role=admin --name="Admin"
```

## 3. Settings that must be right (backend/.env)

- `APP_ENV=production`, `APP_DEBUG=false` (never true in production: it shows code and settings on errors).
- `APP_KEY`: made by `php artisan key:generate`. Never reuse a key that was ever committed to git.
- `APP_URL=https://api.yourdomain.com`, `FRONTEND_URL=https://app.yourdomain.com`, `SANCTUM_STATEFUL_DOMAINS=app.yourdomain.com`.
- Database: `DB_*`; `DB_TIMEZONE=+00:00` keeps the database clock in UTC like the app.
- Redis: `CACHE_STORE=redis`, `SESSION_DRIVER=redis`, `QUEUE_CONNECTION=redis`.
- Mail: `MAIL_*` (customer confirmations, tracking emails, one-time codes). Emails are sent by the queue workers.
- Stores, couriers, SSO and the assistant: the keys in the matching sections; empty ones are simply not offered.
- Behind Cloudflare or a load balancer: `TRUSTED_PROXIES` (and the `set_real_ip_from` lines in nginx), so rate limits
  see the real visitor and not the proxy.

## 4. Every release

```bash
cd /var/www/finovo && ./deploy/deploy.sh
```

It pulls `main`, installs packages, stops if `composer audit` finds a security problem, builds the frontend, runs the
migrations behind a short maintenance page, caches config and routes, restarts the queue workers and reloads PHP, then
checks `/up`. Deploy only commits that are green in GitHub Actions (CI runs the tests on MySQL, the audits and the build).

## 5. How traffic is controlled

| Layer | Limit | What happens above it |
| --- | --- | --- |
| nginx, per visitor address | API 30 requests a second (burst 60); sign-in and the customer track page 2 a second (burst 10); 60 open connections | 429 at once, the server stays fast for everyone else |
| Laravel, per signed-in user | `API_RATE_LIMIT` requests a minute (300) | 429 with the time to wait |
| Laravel, sign-in | 5 tries a minute per address and email; one-time codes 5 a minute | 429 |
| Customer track page | 10 tries a minute per address | 429 |
| PHP-FPM | `request_terminate_timeout` 65 s; nginx gives up after 60 s | No request can hold a worker forever |
| Calls to stores, couriers and AI | 10 s to connect, 30 s to answer (unless a call sets less) | The call fails with a message instead of hanging |
| Slow work (emails, store updates, webhooks, syncing) | Runs on the queue workers, never inside a page request | Pages stay fast even when a store or mail server is slow |

For attacks bigger than one server can absorb (DDoS), put Cloudflare (free plan is enough to start) in front of both
domains.

## 6. Measured speed and how far one server goes

Load test on a small machine (2 vCPU, with MySQL, Redis and the load generator all on the same 2 vCPU), 20,000 orders,
40 users hitting the same page at once, PHP with OPcache and cached routes:

| Request | Requests a second | Typical answer time with 40 at once | Errors or timeouts |
| --- | --- | --- | --- |
| Health check `/up` | 253 | 158 ms | 0 |
| Signed-in user (`/auth/me`) | 209 | 192 ms | 0 |
| Orders page (20 orders with items, shipments, counts) | 56 | 713 ms | 0 |
| Late and at-risk orders (SLA) | 149 | 265 ms | 0 |
| Inventory figures | 141 | 284 ms | 0 |
| Customer track page from one address | 12 allowed, rest 429 in 67 ms | | rate limit works |

On its own (no one else using the server) a page answers in 20 to 75 ms.

What that means for real servers (rough guide; measure on your own hardware with your own data):

- One app server with 8 vCPU (database on its own server) handles about 400 to 800 requests a second: roughly
  5,000 to 10,000 people working at the same time.
- 100,000 people at the same moment is about 7,000 to 10,000 requests a second. No single server does that; Finovo is
  built so that you add servers:
  1. Load balancer (or Cloudflare) in front of 12 to 20 app servers, all with the same code and `.env`.
  2. One MySQL primary (16 vCPU, 64 GB RAM, SSD) plus 2 read replicas in `DB_READ_HOSTS`.
  3. One Redis server (or a managed Redis) shared by all app servers.
  4. Uploaded files (product photos, form attachments) on shared storage (NFS or S3) mounted at `backend/storage/app`.
  5. The React app served from a CDN (it is static files).
  6. Queue workers on 2 or more servers; the scheduler may run on every server, each job runs on one only.
- The app is ready for this: sign-in uses tokens (no server memory), sessions, cache, rate limits and locks are in Redis,
  scheduled jobs run on one server only, heavy work is queued, and order counts are cached until an order changes.

## 7. Watching it

- Uptime: point a monitor (UptimeRobot, Better Stack) at `https://api.yourdomain.com/up`. It fails when the database or
  cache is down.
- Errors: `backend/storage/logs/laravel-YYYY-MM-DD.log` (one file a day, 14 kept); queue workers in `worker.log`; slow PHP
  requests (over 5 s) in `/var/log/php8.3-fpm-finovo-slow.log`.
- Failed background jobs: `php artisan queue:failed`, retry with `php artisan queue:retry all`.

## 8. Backups

`deploy/backup.sh` runs every night at 02:30 (from the crontab), writes a compressed MySQL dump to `/var/backups/finovo`
and keeps 14 days. Copy that folder to another place every day (another server or cloud storage), and test a restore
once a month:

```bash
gunzip < /var/backups/finovo/finovo-YYYYMMDD-HHMMSS.sql.gz | mysql -u finovo -p finovo_restore_test
```

## 9. Before the first customer

- [ ] Mail password changed and a new `APP_KEY` (an old `.env` is in the git history, commit 1618a80).
- [ ] `APP_DEBUG=false`, HTTPS on both domains, `/up` answers 200.
- [ ] Supervisor shows the workers running (`sudo supervisorctl status`), the scheduler runs (`php artisan schedule:list`).
- [ ] One full order tried by hand: create, allocate, pick, pack, dispatch, return.
- [ ] One real store and one real courier connected in their sandbox or test mode.
- [ ] A backup made and restored once.
