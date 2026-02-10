# Dockerfile for Symfony Smart Scheduling System
# Multi-stage build for smaller final image
FROM composer:2 AS composer_stage

WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts --no-progress --prefer-dist --ignore-platform-reqs

FROM php:8.3-fpm

# Install system dependencies in a single layer
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    nginx \
    supervisor \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/* /tmp/* /var/tmp/*

# Configure PHP OPcache for production
RUN { \
    echo 'opcache.memory_consumption=256'; \
    echo 'opcache.interned_strings_buffer=16'; \
    echo 'opcache.max_accelerated_files=20000'; \
    echo 'opcache.revalidate_freq=0'; \
    echo 'opcache.validate_timestamps=0'; \
    echo 'opcache.enable=1'; \
    echo 'opcache.enable_cli=0'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Configure PHP for production
RUN { \
    echo 'upload_max_filesize=50M'; \
    echo 'post_max_size=50M'; \
    echo 'memory_limit=256M'; \
    echo 'max_execution_time=60'; \
    echo 'date.timezone=Asia/Manila'; \
    } > /usr/local/etc/php/conf.d/app.ini

# Configure PHP-FPM to pass environment variables
RUN { \
    echo '[www]'; \
    echo 'clear_env = no'; \
    } > /usr/local/etc/php-fpm.d/zz-env.conf

# Remove default nginx site
RUN rm -f /etc/nginx/sites-enabled/default /etc/nginx/sites-available/default

# Set working directory
WORKDIR /var/www/html

# Copy Composer dependencies from build stage
COPY --from=composer_stage /app/vendor ./vendor

# Copy nginx configuration
COPY docker/nginx/default.conf /etc/nginx/sites-available/default
RUN ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy startup script
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Copy application files
COPY . .

# === CRITICAL: Create .env.local.php with baked-in production values ===
# Symfony checks .env.local.php FIRST before .env file.
# This eliminates the need for .env at runtime entirely.
RUN php -r "\
\$vars = [ \
    'APP_ENV' => 'prod', \
    'APP_DEBUG' => '0', \
    'APP_SECRET' => '86d65f319c2756d34b934f31df998dbf', \
    'DATABASE_URL' => 'mysql://root:xylbarLKdKictHtllelHEcFLbozRYrDm@mysql.railway.internal:3306/railway?serverVersion=8.0', \
    'MAILER_DSN' => 'null://null', \
    'DEFAULT_URI' => 'https://norsuscheduling1-production.up.railway.app', \
    'MESSENGER_TRANSPORT_DSN' => 'doctrine://default', \
]; \
file_put_contents('.env.local.php', '<?php return ' . var_export(\$vars, true) . ';' . PHP_EOL);"

# Also create a minimal .env so Symfony doesn't complain during composer scripts
RUN echo 'APP_ENV=prod' > .env

# Run Composer scripts now that we have the full source
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-progress --prefer-dist --ignore-platform-reqs \
    || true

# Create required directories and set permissions
RUN mkdir -p var/cache var/log var/sessions public/curriculum_templates \
    && chown -R www-data:www-data var public/curriculum_templates .env.local.php \
    && chmod -R 775 var

# Warm up cache during build (uses .env.local.php values)
RUN php bin/console cache:clear --env=prod --no-debug 2>&1 || true \
    && php bin/console cache:warmup --env=prod --no-debug 2>&1 || true

# Remove .env (not needed - .env.local.php handles everything)
RUN rm -f .env

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost:${PORT:-80}/health || exit 1

CMD ["/usr/local/bin/start.sh"]
