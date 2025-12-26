# Development Environment Setup

## Required PHP Extensions

This project requires the following PHP extensions:
- `gd` (for image processing in PHPSpreadsheet)
- `zip` (for Excel file handling)
- `exif` (for image metadata)
- `pdo_mysql` (for database)
- `intl` (for internationalization)
- `mbstring` (for multibyte string handling)

## Installation Instructions

### Windows (XAMPP/WAMP)

1. Open your `php.ini` file (usually in `C:\xampp\php\php.ini`)
2. Find and uncomment (remove semicolon) from these lines:
   ```ini
   extension=gd
   extension=zip
   extension=exif
   extension=pdo_mysql
   extension=intl
   extension=mbstring
   ```
3. Restart Apache

### Windows (Laravel Herd / Laragon)

Extensions are usually pre-installed. If not:
1. Open PHP settings from the control panel
2. Enable: GD, ZIP, EXIF, PDO MySQL, Intl

### Linux (Ubuntu/Debian)

```bash
sudo apt-get update
sudo apt-get install -y \
    php8.2-gd \
    php8.2-zip \
    php8.2-mysql \
    php8.2-intl \
    php8.2-mbstring \
    php8.2-xml \
    php8.2-curl
```

Restart PHP-FPM:
```bash
sudo systemctl restart php8.2-fpm
```

### macOS (Homebrew)

```bash
brew install php@8.2
brew install php-gd php-zip
```

Restart PHP:
```bash
brew services restart php@8.2
```

### Docker

Use the provided Dockerfile:
```bash
docker build -t smart-scheduling .
docker run -p 8000:9000 smart-scheduling
```

## Verify Installation

Check if extensions are enabled:
```bash
php -m | grep -E 'gd|zip|exif|pdo_mysql|intl'
```

Or create a PHP info file:
```php
<?php phpinfo(); ?>
```

## Composer Installation

After enabling extensions:
```bash
composer install
```

If you still encounter issues:
```bash
composer install --ignore-platform-req=ext-gd
```

## Production Deployment

The project uses Nixpacks configuration ([nixpacks.toml](nixpacks.toml)) which automatically installs required extensions during deployment to Railway, Heroku, or similar platforms.

No additional configuration needed for production deployments.
