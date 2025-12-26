# Deployment Guide - Smart Scheduling System

## Initial Production Setup

When you deploy this system to a live server for the first time, you won't have any users in the database. Follow these steps to set up your initial admin account.

### Option 1: Create Admin User via Command (Recommended)

After deploying your application and setting up the database, run:

```bash
php bin/console app:create-admin
```

The command will interactively prompt you for:
- Admin username
- Admin email
- Admin password (min 8 characters)
- First name
- Last name

**Example:**
```bash
$ php bin/console app:create-admin

 Create Admin User
 ==================

 Enter admin username [admin]:
 > admin

 Enter admin email [admin@norsu.edu.ph]:
 > admin@norsu.edu.ph

 Enter admin password (min 8 characters):
 > ********

 Confirm password:
 > ********

 Enter first name [Admin]:
 > Juan

 Enter last name [User]:
 > Dela Cruz

 [OK] Admin user created successfully!
```

### Option 2: Non-Interactive Mode

You can also provide all parameters directly:

```bash
php bin/console app:create-admin admin admin@norsu.edu.ph YourSecurePassword123 --first-name=Juan --last-name=DelaCruz
```

### Option 3: Using Test Users (Development Only)

For development or testing purposes only, you can create sample users:

```bash
php bin/console app:create-test-users
```

This creates:
- 1 Admin user (username: `admin`, password: `password`)
- 2 Department Heads
- 3 Faculty members

**⚠️ WARNING:** Do NOT use test users in production! They have weak passwords and are meant for development only.

## Production Deployment Checklist

### 1. Environment Configuration

**Create production environment file:**
```bash
cp .env.prod.example .env.prod
```

**Edit `.env.prod` with your production values:**
```env
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=your_secure_random_secret_here  # Generate with: php -r "echo bin2hex(random_bytes(32));"
DATABASE_URL="mysql://db_user:strong_password@db_host:3306/db_name?serverVersion=8.0&charset=utf8mb4"
DEFAULT_URI=https://your-production-domain.com
```

**Important:** Never commit `.env.prod` to version control!

### 2. Database Setup

**Create dedicated database user:**
```sql
CREATE USER 'scheduling_app'@'localhost' IDENTIFIED BY 'STRONG_RANDOM_PASSWORD';
CREATE DATABASE norsu_scheduling CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT SELECT, INSERT, UPDATE, DELETE ON norsu_scheduling.* TO 'scheduling_app'@'localhost';
FLUSH PRIVILEGES;
```

**Run migrations:**
```bash
php bin/console doctrine:migrations:migrate --env=prod --no-interaction
```

### 3. Install Dependencies (Production Mode)

```bash
composer install --no-dev --optimize-autoloader --no-interaction
npm install
```

### 4. Build Production Assets

```bash
npm run build-prod  # Minifies Tailwind CSS
php bin/console asset-map:compile --env=prod
```

### 5. Create Admin User

```bash
php bin/console app:create-admin --env=prod
```

### 6. Cache Management

```bash
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod
```

### 7. Set Proper Permissions (Linux/Unix)

```bash
# Set ownership
chown -R www-data:www-data .

# Set permissions
chmod -R 775 var/
chmod -R 775 public/build/

# Protect sensitive files
chmod 600 .env.prod
```

### 8. Web Server Configuration

**For Apache:**
- Ensure `public/` is your document root
- Enable `mod_rewrite` module
- `.htaccess` files are already configured

**For Nginx:**
- Use the provided `nginx.conf` as a template
- Update paths and domain names
- Restart Nginx: `sudo systemctl restart nginx`

### 9. Security Checklist

- [ ] HTTPS enabled with valid SSL certificate
- [ ] `APP_DEBUG=0` in production
- [ ] Strong `APP_SECRET` generated
- [ ] Database user has minimal privileges
- [ ] File permissions set correctly
- [ ] `.env` files excluded from version control
- [ ] Web server configured to deny access to sensitive directories
- [ ] Security headers enabled (via `.htaccess` or Nginx config)

### 10. Optional but Recommended

**Install APCu for better performance:**
```bash
# Ubuntu/Debian
sudo apt-get install php8.2-apcu

# Enable in php.ini
apc.enabled=1
apc.shm_size=128M
```

**Or set up Redis for cache and sessions:**
```bash
# Ubuntu/Debian
sudo apt-get install redis-server php8.2-redis

# Start Redis
sudo systemctl start redis-server
sudo systemctl enable redis-server

# Update .env.prod
REDIS_URL=redis://localhost:6379
```

Then uncomment Redis configuration in `config/packages/cache.yaml` and `config/packages/framework.yaml`.

### 11. Health Checks

Test your deployment:
```bash
# Detailed health check
curl https://your-domain.com/health

# Simple health check
curl https://your-domain.com/health/simple
```

### 12. Monitoring and Logging

**View logs:**
```bash
tail -f var/log/prod.log
```

**Consider adding:**
- Application monitoring (Sentry, New Relic)
- Uptime monitoring (Pingdom, UptimeRobot)
- Error alerting via email/Slack

### 13. Backup Strategy

**Set up automated database backups:**
```bash
# Example cron job (daily at 2 AM)
0 2 * * * /usr/bin/mysqldump -u scheduling_app -p'password' norsu_scheduling | gzip > /backups/db_$(date +\%Y\%m\%d).sql.gz
```

**Also backup:**
- Uploaded files (if any in `public/` directory)
- `.env.prod` file (encrypted storage)

### 14. Login to the system
   - Navigate to: `https://your-domain.com/login`
   - Use the credentials you created in step 2
   - You'll have full admin access to create other users

## Adding More Users After Initial Setup

Once logged in as admin, you can:
1. Navigate to Admin Panel > Users
2. Create additional users (admins, department heads, faculty)
3. Assign roles and departments as needed

## Security Recommendations

1. **Use strong passwords** - At least 16 characters with mixed case, numbers, and symbols
2. **Change default passwords** - If you used the test users command, change all passwords immediately
3. **Enable HTTPS** - Always use SSL/TLS in production (configured in web server)
4. **Regular backups** - Set up automated database and file backups
5. **Monitor access logs** - Keep track of admin login attempts
6. **Keep software updated** - Regularly update Symfony and dependencies
7. **Rate limiting** - Configured to prevent brute force attacks (5 attempts per 15 minutes)
8. **Security headers** - Configured in `.htaccess` and `nginx.conf`
9. **File permissions** - Follow principle of least privilege
10. **Environment files** - Never commit `.env.prod` to version control

## Performance Optimization

### Recommended Production Setup:
- **PHP OPcache** - Enable and configure for better PHP performance
- **APCu or Redis** - For application caching (already configured)
- **Database indexing** - Indexes are already in place via migrations
- **CDN** - Consider using a CDN for static assets
- **HTTP/2** - Enable in your web server configuration

### PHP Configuration (php.ini):
```ini
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=16
opcache.max_accelerated_files=20000
opcache.validate_timestamps=0
realpath_cache_size=4096K
realpath_cache_ttl=600
```

## Troubleshooting

### "User already exists" error
If you try to create an admin user but get an error that the user exists:
1. Check existing users in the database
2. Use a different username/email
3. Or reset the password of the existing user

### Can't access admin panel
Make sure your user has the `ROLE_ADMIN` role assigned. Check database or create a new admin user.

### Cache issues
If you see stale data or errors:
```bash
php bin/console cache:clear --env=prod
php bin/console cache:warmup --env=prod
```

### Permission denied errors
Ensure web server user has proper permissions:
```bash
chown -R www-data:www-data var/
chmod -R 775 var/
```:
- Check application logs: `var/log/prod.log`
- Review web server logs
- Test health check: `curl https://your-domain.com/health`
- Refer to the main `README.md` for feature documentation
- Contact your system administrator

## Files Added for Production

This deployment includes:
- `.env.prod.example` - Production environment template
- `.htaccess` - Apache configuration (root and public/)
- `nginx.conf` - Nginx server configuration template
- `config/packages/rate_limiter.yaml` - Rate limiting configuration
- Custom error pages in `templates/bundles/TwigBundle/Exception/`
- Health check controller: `src/Controller/HealthController.php`

## Post-Deployment Verification

After deployment, verify:
1. [ ] Application loads at your domain
2. [ ] Login works with admin credentials
3. [ ] Health check returns healthy status
4. [ ] HTTPS is enabled and working
5. [ ] Error pages display correctly (test with invalid URLs)
6. [ ] Database operations work (create a test user)
7. [ ] Cache is working (check response times)
8. [ ] Logs are being written to `var/log/`
9. [ ] File uploads work (if applicable)
10. [ ] All roles and permissions function correctly

### Database connection failed
- Verify `DATABASE_URL` in `.env.prod`
- Check database user permissions
- Ensure database server is running
- Test connection: `php bin/console dbal:run-sql "SELECT 1" --env=prod`

### 500 Internal Server Error
Check logs for details:
```bash
tail -f var/log/prod.log
# Or check web server logs
tail -f /var/log/nginx/scheduling_error.log
```

### Assets not loading
Rebuild assets:
```bash
npm run build-prod
php bin/console cache:clear --env=prod
```

## Production URLs

- **Login:** `https://your-domain.com/login`
- **Admin Panel:** `https://your-domain.com/admin`
- **Health Check:** `https://your-domain.com/health`

## New Features (Production Ready)

### Health Check Endpoints
Monitor application health:
- `/health` - Detailed health check (JSON)
- `/health/simple` - Simple OK response for load balancers

### Rate Limiting
Automatic protection against brute force attacks:
- Login attempts: 5 per 15 minutes
- API requests: 100 per minute

### Custom Error Pages
User-friendly error pages for:
- 404 Not Found
- 403 Access Denied
- 500 Internal Server Error
- Generic errors

### Security Enhancements
- HTTPS enforcement in production
- Security headers (XSS protection, clickjacking prevention)
- CSRF protection enabled
- Secure session cookies

## Environment Variables

Make sure these are set in your production `.env` file:

```env
APP_ENV=prod
APP_DEBUG=0
DATABASE_URL="mysql://user:password@localhost:3306/database_name"
```

## Support

For additional help, refer to the main README.md or contact your system administrator.
