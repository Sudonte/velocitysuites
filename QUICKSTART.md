# Hotel Booking System - Quick Start Guide

## 🚀 Quick Setup (5 minutes)

### Step 1: Install Dependencies
```bash
composer install
```

### Step 2: Configure Environment
```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` and set your database credentials:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hotel_reservation
DB_USERNAME=root
DB_PASSWORD=
```

### Step 3: Setup Database
```bash
php artisan migrate
php artisan db:seed
php artisan storage:link
```

### Step 4: Start Server
```bash
php artisan serve
```

Visit: http://localhost:8000

---

## 🔐 Login Credentials

### Admin Account
- Email: `admin@hotel.com`
- Password: `password123`
- Access: `/admin/dashboard`

### Manager Account
- Email: `manager@hotel.com`
- Password: `password123`
- Access: `/manager/dashboard`

### Receptionist Account
- Email: `receptionist@hotel.com`
- Password: `password123`
- Access: `/receptionist/dashboard`

### Guest Account
- Email: `guest@hotel.com`
- Password: `password123`
- Access: `/guest/dashboard`

---

## 📋 What's Included

✅ **Complete Database Schema** - 15+ tables with relationships
✅ **Authentication System** - Register, Login, Password Reset, OTP Verification
✅ **Multi-Role Authorization** - Admin, Manager, Receptionist, Guest
✅ **Admin Module** - Users, Rooms, Promotions, Amenities Management
✅ **Guest Module** - Room Booking, Reservation Management
✅ **Responsive UI** - Bootstrap 5, Mobile-friendly
✅ **Activity Logging** - Track user actions
✅ **Sample Data** - Pre-seeded with test data

---

## 🗂️ Project Structure

```
app/
├── Http/Controllers/
│   ├── Admin/          (Admin management)
│   ├── Guest/          (Guest booking)
│   └── Auth/           (Authentication)
├── Models/             (Database models)
├── Policies/           (Authorization)
└── Providers/

database/
├── migrations/         (Schema)
├── factories/          (Test data)
└── seeders/            (Initial data)

resources/views/
├── admin/              (Admin pages)
├── guest/              (Guest pages)
├── auth/               (Login/Register)
├── layouts/            (Page templates)
└── components/         (Reusable components)

routes/
└── web.php             (All routes)
```

---

## 🎯 Common Tasks

### Create a New Room (Admin)
1. Login as Admin
2. Go to Rooms → Add Room
3. Fill in details and upload image
4. Click Save

### Book a Room (Guest)
1. Login as Guest
2. Click Search Rooms
3. Select dates and preferences
4. Click Book Now
5. Confirm reservation

### Manage Users (Admin)
1. Go to Users
2. Search or filter users
3. Click user to edit
4. Update details and Save

---

## 🔧 Useful Commands

```bash
# Run all migrations
php artisan migrate

# Rollback migrations
php artisan migrate:rollback

# Reset database
php artisan migrate:refresh

# Seed database
php artisan db:seed

# Create new controller
php artisan make:controller ControllerName

# Create new model
php artisan make:model ModelName

# Create new migration
php artisan make:migration migration_name

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

---

## ⚙️ Configuration Files

- `.env` - Environment variables
- `config/app.php` - Application settings
- `config/database.php` - Database configuration
- `config/auth.php` - Authentication settings

---

## 📚 File Locations

| Item | Location |
|------|----------|
| Routes | `routes/web.php` |
| Controllers | `app/Http/Controllers/` |
| Models | `app/Models/` |
| Views | `resources/views/` |
| Migrations | `database/migrations/` |
| CSS | `public/css/` |
| JavaScript | `public/js/` |
| Images | `storage/app/public/` |

---

## 🆘 Troubleshooting

### Error: SQLSTATE[HY000]
**Solution**: Check database connection in `.env`

### Error: Class not found
**Solution**: Run `composer autoload-dump`

### Error: File permissions denied
**Solution**: Run `chmod -R 755 storage bootstrap/cache`

### Error: No such file or directory
**Solution**: Run `php artisan storage:link`

---

## 📞 Support

For issues or questions:
1. Check Laravel documentation: https://laravel.com/docs
2. Review error messages carefully
3. Check database credentials
4. Ensure all dependencies are installed

---

## 📝 Notes

- Change default passwords in production
- Never commit `.env` with real credentials
- Enable HTTPS in production
- Regularly backup database
- Keep Laravel and packages updated

---

**Enjoy building!** 🎉
