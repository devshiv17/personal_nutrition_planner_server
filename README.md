# Personalized Nutritional Planner - Backend API

A Laravel-based REST API backend for the Personalized Nutritional Planner MVP that generates AI-powered weekly meal plans based on dietary restrictions and personal metrics, featuring voice logging capabilities for food tracking.

## 🚀 Quick Start

### Prerequisites
- PHP 8.2+
- Composer
- PostgreSQL 13+ (or Supabase)
- Node.js 18+ (for frontend integration)

### Installation

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd back
   ```

2. **Install dependencies**
   ```bash
   composer install
   ```

3. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

4. **Configure database**
   Update your `.env` file with database credentials:
   ```env
   DB_CONNECTION=pgsql
   DB_HOST=aws-0-ap-south-1.pooler.supabase.com
   DB_PORT=6543
   DB_DATABASE=postgres
   DB_USERNAME=postgres.lfpxnxptbyytwjlloxui
   DB_PASSWORD=your_password
   DB_SSLMODE=require
   ```

5. **Run migrations**
   ```bash
   php artisan migrate
   ```

6. **Start development server**
   ```bash
   php artisan serve
   ```

## 🏗️ Architecture

### Tech Stack
- **Framework**: Laravel 12 (LTS)
- **Database**: PostgreSQL with Supabase
- **Authentication**: Laravel Sanctum (API tokens)
- **Code Quality**: PHPStan (Level 6)
- **Testing**: PHPUnit
- **Code Style**: Laravel Pint

### Database Schema
The application uses a comprehensive PostgreSQL schema with the following core tables:

- **users** - User profiles with health metrics and dietary preferences
- **foods** - Comprehensive food database with nutritional information
- **user_health_metrics** - Extensible health tracking data
- **dietary_restrictions** - Master dietary restrictions database
- **user_dietary_restrictions** - User-specific dietary restrictions
- **password_reset_tokens** - Authentication token management

## 📋 Features

### Core Functionality
- ✅ User registration and authentication
- ✅ Profile management with health metrics
- ✅ BMI/BMR calculations
- 🔄 AI-powered meal plan generation
- 🔄 Voice-based food logging
- 🔄 Progress tracking and analytics
- 🔄 Grocery list generation

### Dietary Options Supported
- **Keto** - High fat, low carb
- **Mediterranean** - Balanced, heart-healthy
- **Vegan** - Plant-based
- **Diabetic-friendly** - Low glycemic index

## 🛠️ Development

### Code Quality Tools

Run code analysis:
```bash
composer phpstan
```

Run tests:
```bash
composer test
```

Format code:
```bash
./vendor/bin/pint
```

### Database Operations

Reset database:
```bash
php artisan migrate:fresh
```

Seed test data:
```bash
php artisan db:seed
```

Check migration status:
```bash
php artisan migrate:status
```

## 🔧 API Documentation

### Authentication Endpoints
```
POST /api/register     - User registration
POST /api/login        - User login
POST /api/logout       - User logout
POST /api/password/reset - Password reset
```

### User Management
```
GET    /api/user/profile     - Get user profile
PUT    /api/user/profile     - Update user profile
GET    /api/user/metrics     - Get user health metrics
```

### Meal Planning
```
POST   /api/meal-plans/generate    - Generate meal plan
GET    /api/meal-plans/{id}        - Get specific meal plan
GET    /api/meal-plans/current     - Get current meal plan
GET    /api/grocery-list/{id}      - Get grocery list
```

### Food Logging
```
POST   /api/food-logs       - Log food consumption
GET    /api/food-logs/daily - Get daily food logs
PUT    /api/food-logs/{id}  - Update food log entry
DELETE /api/food-logs/{id}  - Delete food log entry
```

## 🧪 Testing

Run all tests:
```bash
php artisan test
```

Run specific test suite:
```bash
php artisan test --testsuite=Feature
php artisan test --testsuite=Unit
```

Run with coverage:
```bash
php artisan test --coverage
```

## 🚀 Deployment

### Production Setup

1. **Configure environment**
   ```bash
   APP_ENV=production
   APP_DEBUG=false
   ```

2. **Optimize application**
   ```bash
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   composer install --optimize-autoloader --no-dev
   ```

3. **Set up cron jobs**
   ```bash
   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
   ```

### CI/CD Pipeline

The project includes GitHub Actions workflow for:
- ✅ Automated testing (PHPUnit)
- ✅ Code quality analysis (PHPStan)
- ✅ Code style checks (Laravel Pint)
- 🔄 Deployment automation

## 📊 Performance Requirements

- **Page load time**: < 3 seconds on 3G connection
- **Voice recognition response**: < 2 seconds
- **Meal plan generation**: < 5 seconds
- **API response time**: < 1 second for standard requests
- **Uptime target**: 99.5%

## 🔒 Security Features

- HTTPS encryption for all data transmission
- Password hashing with bcrypt
- API token-based authentication
- Input validation and sanitization
- CORS configuration for secure cross-origin requests
- Rate limiting to prevent abuse

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Make your changes
4. Run tests and code quality checks
5. Commit your changes (`git commit -m 'Add amazing feature'`)
6. Push to the branch (`git push origin feature/amazing-feature`)
7. Open a Pull Request

### Code Standards
- Follow PSR-12 coding standards
- Write comprehensive tests for new features
- Maintain PHPStan level 6 compatibility
- Add proper documentation for new endpoints

## 📝 License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## 🆘 Support

For support and questions:
- Create an issue in the GitHub repository
- Check the [troubleshooting guide](docs/troubleshooting.md)
- Review the [API documentation](docs/api.md)

## 🗺️ Roadmap

### Phase 1 (Current) - Core Foundation
- [x] User authentication system
- [x] Database schema implementation
- [x] Basic user profile management
- [ ] Laravel Sanctum integration

### Phase 2 - Meal Planning Engine
- [ ] BMI/BMR calculation system
- [ ] AI meal plan generation
- [ ] Recipe database integration
- [ ] Grocery list functionality

### Phase 3 - Voice Logging
- [ ] Voice recognition implementation
- [ ] Food database integration
- [ ] Daily tracking system
- [ ] Voice-to-nutrition conversion

### Phase 4 - Analytics & UI
- [ ] Dashboard development
- [ ] Progress tracking charts
- [ ] Mobile responsiveness
- [ ] Performance optimization

---

**Built with ❤️ using Laravel 12 LTS**
