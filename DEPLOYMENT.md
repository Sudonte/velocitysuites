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

**Option A - change the document root (use if your Hostinger plan allows it):**
1. hPanel -> Websites -> your domain -> Manage -> look for a Document Root
   / Advanced setting
2. Point it to `.../hotel_reservation/public` (wherever you uploaded the
   repo, plus `/public`)
3. No file moves needed

**Option B - restructure manually (if there's no document-root setting):**
1. Move the *contents* of `public/` (not the folder itself) up into
   `public_html/`: `index.php`, `.htaccess`, `css/`, `js/`, etc.
2. Move everything else (`app/`, `bootstrap/`, `config/`, `routes/`,
   `vendor/`, etc.) into a sibling folder outside `public_html`, e.g.
   `public_html/../hotel_reservation_app/`
3. Edit the `index.php` now sitting in `public_html` - it has two
   `require` lines pointing at `../vendor/autoload.php` and
   `../bootstrap/app.php`. Update both paths to point at wherever you
   moved the app folder in step 2 (e.g.
   `../hotel_reservation_app/vendor/autoload.php`).

Either way, verify by hitting the domain: you should get the Velocity
Suites landing page, not a 403 or a directory listing.

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