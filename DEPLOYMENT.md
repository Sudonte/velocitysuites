# Deployment Guide - Velocity Suites Hotel Booking System

## Overview
This document provides deployment instructions for the Laravel 12 Hotel Booking and Reservation System to be hosted on Hostinger.

## Requirements
- PHP 8.2+
- MySQL 8.0+
- Laravel 12
- Composer
- Apache/Nginx

## Environment Configuration

### Local Development (.env)
```env
APP_NAME="Velocity Suites"
APP_ENV=local
APP_KEY=base64:...
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hotel_booking
DB_USERNAME=root
DB_PASSWORD=

QUEUE_CONNECTION=database
SESSION_DRIVER=database
```

### Production (.env on Hostinger)
```env
APP_NAME="Velocity Suites"
APP_ENV=production
APP_KEY=base64:...
APP_DEBUG=false
APP_URL=https://your-domain.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_database_name
DB_USERNAME=your_username
DB_PASSWORD=your_password

QUEUE_CONNECTION=database
SESSION_DRIVER=database
FILESYSTEM_DISK=public
```

## Deployment Steps

### 1. Clone Repository
```bash
git clone https://github.com/your-repo/hotel-reservation.git
cd hotel-reservation
```

### 2. Install Dependencies
```bash
composer install --optimize-autoloader --no-dev
```

### 3. Environment Configuration
```bash
cp .env.example .env
php artisan key:generate
```

### 4. Configure .env
Edit `.env` file with your Hostinger database credentials.

### 5. Database Setup
```bash
php artisan migrate
php artisan db:seed --class=DatabaseSeeder
```

### 6. Storage Link
```bash
php artisan storage:link
```

### 7. Cache Configuration
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 8. Queue Setup (Optional)
If using queue for background jobs:
```bash
php artisan queue:work database --sleep=3 --tries=3 --max-time=3600
```

### 9. Point the Web Root at `public/`, Not the Project Root

**This is the single most common Laravel-on-shared-hosting mistake, and it
produces a bare "403 Forbidden" with no Laravel error page** - the web
server rejects the request before PHP ever runs, because it's serving the
project root (which has no `index.php` and disabled directory listing)
instead of `public/index.php`, Laravel's only web-facing entry point.

On Hostinger there is no Document Root setting for shared hosting plans,
and Hostinger's Git auto-deploy tool can only target `public_html` or a
subfolder under it - it cannot deploy to a sibling directory. The working
setup for this project (**this is what's actually live**):

- Git auto-deploy (hPanel -> Advanced -> Git) is configured with root
  directory `hotel_reservation`, so every push to `master` clones the repo
  into `public_html/hotel_reservation` and runs `composer install`
  automatically.
- Because that puts the whole app inside the web root, the repo ships a
  root-level `.htaccess` (`Require all denied`) that blocks all direct
  HTTP access to anything under `hotel_reservation/` - it's only ever
  reached via PHP `require` from `public_html/index.php`, never via a URL.
- `public_html/index.php` is **not** part of the repo (it's hand-maintained
  on the server, see below) and its three paths point at
  `./hotel_reservation/...` (child folder, not `../` parent) since
  `hotel_reservation` is nested inside `public_html`.
- `public_html/storage` is a symlink to
  `public_html/hotel_reservation/storage/app/public` (recreate with `ln -s`
  if it's ever missing - `php artisan storage:link` will fail on this host
  because both `symlink()` and `exec()` are disabled by CageFS).

**One-time setup after connecting Git auto-deploy** (only needed once per
fresh server, not on every subsequent push):
1. In hPanel Git settings, set root directory to `hotel_reservation` and
   deploy.
2. Copy `.env` into `public_html/hotel_reservation/.env` (has real DB
   credentials - never comes from git, since it's gitignored).
3. `cd public_html/hotel_reservation && php artisan config:cache &&
   php artisan route:cache && php artisan view:cache && chmod -R 775
   storage bootstrap/cache`
4. Create `public_html/index.php` by hand (see template below) and the
   `public_html/storage` symlink.

`public_html/index.php` template (copy of Laravel's default, with the two
`require` paths and the maintenance-mode check pointed at the nested app
folder):
```php
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/hotel_reservation/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/hotel_reservation/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/hotel_reservation/bootstrap/app.php';

$app->handleRequest(Request::capture());
```

**On every subsequent push**, `git push origin master` triggers the
redeploy automatically via Hostinger's Git tool (or click "Redeploy" in
hPanel). If the change includes a new migration, SSH in and run
`php artisan migrate --force` from `public_html/hotel_reservation` - schema
changes are never applied automatically.

Verify by hitting the domain: you should get the Velocity Suites landing
page, not a 403 or a directory listing. Also spot-check that
`https://your-domain.com/hotel_reservation/.env` returns 403 (proves the
deny-all rule is active).

## Important Notes

### File Storage
- All uploaded files (room images, profile images) are stored in `storage/app/public/`
- The `php artisan storage:link` command must be run to make files publicly accessible
- On Hostinger, ensure the storage directory is properly linked via .htaccess if needed

### Queue Configuration
- Default: `QUEUE_CONNECTION=database` (compatible with shared hosting)
- Avoid Redis or Supervisor as they require SSH access

### Permissions
```bash
chmod -R 755 storage bootstrap/cache
chmod -R 775 storage
```

## Post-Deployment Checklist
- [ ] Verify `.env` is not committed to git
- [ ] Test login for all user roles (Guest, Receptionist, Manager, Admin)
- [ ] Test room booking flow
- [ ] Test check-in/check-out workflow
- [ ] Verify notifications are working
- [ ] Check email notifications (if configured)
- [ ] Test payment recording (receptionist)
- [ ] Verify landing page redirects for authenticated users

## Troubleshooting

### 403 Forbidden (no Laravel error page, just a bare "Access to this
### resource on the server is denied!")
- This is the web server rejecting the request before Laravel runs - not
  an application bug. See step 9 above: the document root is pointing at
  the project root instead of `public/`.

### 500 Internal Server Error
- Set `APP_DEBUG=true` temporarily to see error details
- Check `.env` configuration
- Verify file permissions

### Database Connection Issues
- Verify MySQL credentials in `.env`
- Ensure database exists
- Check database host (localhost vs 127.0.0.1)

### Session Issues
- Clear browser cookies
- Verify SESSION_DOMAIN in .env if using subdomains