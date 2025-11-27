# Stage 1: Build dependencies
FROM composer:2 AS vendor
COPY composer.json composer.json
# COPY composer.lock composer.lock # No lock file yet
RUN composer install --no-dev --ignore-platform-reqs --no-scripts --prefer-dist

# Stage 2: Final image
FROM php:8.4-fpm-alpine

# Install Nginx and MariaDB client; install PHP extensions (mysqli, pdo_mysql) and clean up
RUN apk add --no-cache --update nginx \
  && docker-php-ext-install mysqli pdo pdo_mysql \
  && rm -rf /var/cache/apk/* /tmp/*

# Copy dependencies from vendor stage
COPY --from=vendor /app/vendor /var/www/html/vendor

# Copy application code
COPY . /var/www/html/

# Create nginx.conf directly in the Docker build
RUN printf '%s\n' \
  'worker_processes auto;' \
  '' \
  'events { worker_connections 1024; }' \
  '' \
  'http {' \
  '    include       mime.types;' \
  '    default_type  application/octet-stream;' \
  '' \
  '    sendfile        on;' \
  '' \
  '    server {' \
  '        listen       80;' \
  '        server_name  localhost;' \
  '        root   /var/www/html/public;' \
  '' \
  '        index  index.php index.html;' \
  '' \
  '        location / {' \
  '            try_files $uri $uri/ /index.php?$query_string;' \
  '        }' \
  '' \
  '        location ~ \.php$ {' \
  '            include fastcgi_params;' \
  '            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;' \
  '            fastcgi_index index.php;' \
  '            fastcgi_pass 127.0.0.1:9000;' \
  '        }' \
  '    }' \
  '}' \
  > /etc/nginx/nginx.conf

# Make sure Nginx and PHP-FPM can access/serve project files
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

CMD php-fpm -D && nginx -g 'daemon off;'
