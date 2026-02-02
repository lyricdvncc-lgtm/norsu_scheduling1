# Dockerfile for Symfony Smart Scheduling System
FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update && apt-get install -y \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libicu-dev \
    zip \
    unzip \
    nginx \
    supervisor \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd zip intl opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Copy nginx configuration
COPY docker/nginx/default.conf /etc/nginx/sites-available/default

# Copy supervisor configuration
COPY docker/supervisor/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Copy startup script
COPY docker/start.sh /usr/local/bin/start.sh
RUN chmod +x /usr/local/bin/start.sh

# Install PHP dependencies without running post-install scripts
# Post-install scripts will run when container starts with actual .env file
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Set permissions for var directory and create cache
RUN mkdir -p /var/www/html/var/cache /var/www/html/var/log /var/www/html/var/sessions \
    && chown -R www-data:www-data /var/www/html/var \
    && chmod -R 777 /var/www/html/var

# Create public directories if they don't exist
RUN mkdir -p /var/www/html/public/curriculum_templates \
    && chown -R www-data:www-data /var/www/html/public/curriculum_templates

# Expose port (will be overridden by Railway's PORT env var)
EXPOSE 80

# Start using startup script that handles PORT env variable
CMD ["/usr/local/bin/start.sh"]
