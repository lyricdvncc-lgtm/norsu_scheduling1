#!/bin/bash
set -e

cd /var/www/html

echo "=== Smart Scheduling System Starting ==="

# Use PORT env variable or default to 80
PORT=${PORT:-80}

echo "Port: ${PORT}"

# Generate nginx config with correct port
cat > /etc/nginx/sites-available/default << EOF
server {
    listen ${PORT};
    server_name localhost;
    root /var/www/html/public;

    index index.php;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css application/json application/javascript text/xml application/xml text/javascript image/svg+xml;
    gzip_min_length 256;

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ ^/index\.php(/|\$) {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_split_path_info ^(.+\.php)(/.*)$;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;
        fastcgi_read_timeout 60s;
        fastcgi_buffer_size 128k;
        fastcgi_buffers 4 256k;
        fastcgi_busy_buffers_size 256k;
        internal;
    }

    location ~ \.php$ {
        return 404;
    }

    # Cache static assets
    location ~* \.(css|js|png|jpg|jpeg|gif|ico|svg|woff|woff2|ttf|eot)$ {
        expires 30d;
        add_header Cache-Control "public, immutable";
        try_files \$uri =404;
    }

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;

    # Deny access to sensitive files
    location ~ /(\.env|composer\.|config|src|var|vendor|migrations|tests) {
        deny all;
        return 404;
    }

    # Increase upload size
    client_max_body_size 50M;

    error_log /var/log/nginx/error.log;
    access_log /var/log/nginx/access.log;
}
EOF

# Ensure symlink exists
ln -sf /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default

# Create required directories
mkdir -p /var/www/html/var/cache /var/www/html/var/log /var/www/html/var/sessions
chown -R www-data:www-data /var/www/html/var
chmod -R 775 /var/www/html/var

# Run database migrations
echo "Running database schema update..."
php bin/console doctrine:schema:update --force --no-interaction 2>&1 || true
php bin/console doctrine:migrations:version --add --all --no-interaction 2>&1 || true

echo "Starting PHP-FPM and Nginx via Supervisor..."

# Start supervisor (manages both PHP-FPM and Nginx)
exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
