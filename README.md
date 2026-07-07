# Hotel Booking & Reservation System

A comprehensive Laravel-based hotel booking and reservation management system with multi-role dashboard support (Admin, Manager, Receptionist, Guest).

## Features

### 🔐 Authentication & Authorization
- User registration with OTP verification
- Login with role-based access control
- Password reset functionality
- Account status management
- Activity logging

### 👨‍💼 Admin Module
- **Dashboard**: System-wide statistics and analytics
- **User Management**: Create, edit, deactivate users with role assignment
- **Room Management**: Full CRUD operations with image uploads
- **Promotions**: Create and manage promotional discounts
- **Amenities**: Manage hotel amenities and charges

### 👔 Manager Module
- Dashboard with revenue analytics
- View and filter reservations
- Generate reports
- Notification management

### 🛎️ Receptionist Module
- Check-in/Check-out management
- Reservation management
- Billing generation
- Payment processing
- Guest amenity requests

### 🏨 Guest Module
- Room search and filtering
- Make reservations
- Modify pending reservations
- Cancel reservations
- Promotion browsing
- Booking history

## Technology Stack

- **Backend**: Laravel 11.x
- **Database**: MySQL/MariaDB
- **Frontend**: HTML5, CSS3, JavaScript, Bootstrap 5
- **Authentication**: Laravel Built-in Auth
- **ORM**: Eloquent

## Installation

### Prerequisites
- PHP 8.2+
- Composer
- MySQL/MariaDB
- Node.js & npm (optional, for asset compilation)

### Setup Steps

1. **Clone or Extract Project**
   ```bash
   cd hotel_reservation
   ```

2. **Install Dependencies**
   ```bash
   composer install
   ```

3. **Environment Configuration**
   ```bash
   cp .env.example .env
   ```

4. **Generate Application Key**
   ```bash
   php artisan key:generate
   ```

5. **Configure Database** (.env file)
   ```
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=hotel_reservation
   DB_USERNAME=root
   DB_PASSWORD=
   ```

6. **Run Migrations**
   ```bash
   php artisan migrate
   ```

7. **Seed Database**
   ```bash
   php artisan db:seed
   ```

8. **Create Storage Link**
   ```bash
   php artisan storage:link
   ```

9. **Start Application**
   ```bash
   php artisan serve
   ```

Visit `http://localhost:8000` in your browser.

## Default Test Accounts

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@hotel.com | password123 |
| Manager | manager@hotel.com | password123 |
| Receptionist | receptionist@hotel.com | password123 |
| Guest | guest@hotel.com | password123 |

## Database Schema

### Core Tables
- **users**: User accounts with roles
- **guests**: Guest profiles linked to user accounts
- **rooms**: Hotel room inventory
- **room_images**: Additional room images
- **reservations**: Guest room reservations
- **bookings**: Booking records linked to reservations
- **billing**: Billing information and charges
- **payments**: Payment transaction records
- **promotions**: Discount and promotional offers
- **amenities**: Available hotel amenities
- **amenity_requests**: Guest requests for amenities
- **notifications**: User notifications
- **activity_logs**: System activity tracking

## API Endpoints

### Authentication
- `POST /register` - Register new guest
- `POST /login` - User login
- `POST /logout` - User logout
- `GET /forgot-password` - Request password reset
- `POST /forgot-password` - Send reset link
- `POST /verify-otp` - Verify registration OTP

### Admin Routes `/admin/*`
- `GET /dashboard` - Admin dashboard
- `GET /users` - List all users
- `POST /users` - Create user
- `PUT /users/{id}` - Update user
- `DELETE /users/{id}` - Delete user
- `GET /rooms` - List rooms
- `POST /rooms` - Create room
- `PUT /rooms/{id}` - Update room
- `DELETE /rooms/{id}` - Delete room

### Guest Routes `/guest/*`
- `GET /dashboard` - Guest dashboard
- `GET /search-rooms` - Search available rooms
- `GET /reservations/create` - View room for booking
- `POST /reservations` - Create reservation
- `GET /reservations/{id}` - View reservation details
- `PUT /reservations/{id}` - Update reservation
- `PUT /reservations/{id}/cancel` - Cancel reservation

## File Structure

```
hotel_reservation/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/
│   │   │   ├── Guest/
│   │   │   └── Auth/
│   │   └── Middleware/
│   ├── Models/
│   ├── Policies/
│   └── Providers/
├── database/
│   ├── migrations/
│   ├── factories/
│   └── seeders/
├── resources/
│   ├── views/
│   │   ├── admin/
│   │   ├── guest/
│   │   ├── auth/
│   │   ├── layouts/
│   │   └── components/
│   ├── css/
│   └── js/
├── routes/
├── public/
└── config/
```

## Features Implemented

### ✅ Completed
- Database schema and migrations
- Eloquent models with relationships
- Authentication system with OTP verification
- Authorization policies and gates
- Admin user management
- Admin room management with image upload
- Guest dashboard
- Guest room search and booking
- Reservation management
- Database seeders with sample data
- Responsive Bootstrap UI
- Activity logging
- Multi-role authorization

### 📋 To Be Implemented
- Admin promotions management
- Admin amenities management
- Manager analytics and reports
- Receptionist check-in/check-out
- Billing and payment processing
- Email notifications
- SMS notifications
- Advanced analytics
- API documentation
- Unit & integration tests

## Key Classes & Controllers

### Controllers
- `AuthenticationController` - User login/logout
- `RegisterController` - User registration
- `AdminDashboardController` - Admin statistics
- `UserManagementController` - User CRUD
- `RoomManagementController` - Room CRUD
- `GuestDashboardController` - Guest dashboard
- `ReservationController` - Reservation management

### Models
- User, Guest
- Room, RoomImage
- Reservation, Booking
- Billing, Payment
- Promotion, Amenity, AmenityRequest
- Notification, ActivityLog

### Policies
- UserPolicy
- RoomPolicy
- ReservationPolicy

## Middleware

- `CheckRole` - Role-based access control
- `CheckAccountStatus` - Verify account is active
- `LogActivity` - Log user activities

## Usage Examples

### Creating a Reservation (Guest)
1. Go to `/guest/dashboard`
2. Click "Search Rooms"
3. Select check-in and check-out dates
4. Browse available rooms
5. Click "Book Now"
6. Confirm booking details
7. Reservation created with pending status

### Managing Users (Admin)
1. Go to `/admin/users`
2. View all users with search/filter
3. Click user to edit
4. Change role or status
5. Reset password if needed
6. Delete user if required

### Room Management (Admin)
1. Go to `/admin/rooms`
2. Search or filter rooms
3. Create new room
4. Upload room images
5. Edit room details
6. Delete room if unused

## Troubleshooting

### Database Connection Error
- Ensure MySQL is running
- Check `.env` database credentials
- Verify database exists: `php artisan migrate`

### File Upload Not Working
- Ensure `storage/` directory is writable
- Run: `php artisan storage:link`
- Check file permissions

### Permission Denied Errors
- Run: `chmod -R 755 storage bootstrap/cache`
- Ensure web server user owns directories

## Support & Contribution

For issues, feature requests, or contributions, please open an issue or submit a pull request.

## License

This project is licensed under the MIT License.

## Security Notes

- Never commit `.env` file with production credentials
- Use strong passwords in production
- Enable HTTPS in production
- Regularly update Laravel and dependencies
- Implement rate limiting for API
- Use prepared statements (Eloquent handles this)
- Sanitize user inputs (Laravel validation)

## Credits

Built with Laravel, Bootstrap 5, and modern web technologies.

---

**Last Updated**: 2024
**Version**: 1.0.0


In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
