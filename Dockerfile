# Stage 1: Build dependencies
FROM composer:2 AS vendor
COPY composer.json composer.json
# COPY composer.lock composer.lock # No lock file yet
COPY auth_keycloak.php auth_keycloak.php
COPY s3_client.php s3_client.php
RUN export COMPOSER_PROCESS_TIMEOUT=2000 && composer install --no-dev --ignore-platform-reqs --no-scripts --prefer-dist

# Stage 2: Build the SPA (Node never reaches the runtime image)
FROM node:24-alpine AS ui
WORKDIR /src/ui
COPY ui/package.json ui/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY ui/ ./
# vite.config.ts writes to ../public/app → /src/public/app
RUN npm run build

# Stage 3: Final image
FROM php:8.5-fpm-alpine

# Release stamp. .github/workflows/build.yml passes both as build args; without
# the ARG declarations the engine drops them and every image reports a version
# that is not the code it contains. APP_VERSION is what /api/v1/health and
# Settings → Maintenance show, and it takes precedence over manifest.json.
ARG VERSION=dev
ARG COMMIT=unknown
ENV APP_VERSION=${VERSION} \
    APP_COMMIT=${COMMIT}

# Install Nginx and MariaDB client; install PHP extensions (mysqli, pdo_mysql) and clean up
RUN apk add --no-cache --update nginx \
  && docker-php-ext-install mysqli pdo pdo_mysql \
  && rm -rf /var/cache/apk/* /tmp/*

# Copy dependencies from vendor stage
COPY --from=vendor /app/vendor /var/www/html/vendor
# Copy aws.phar
# aws.phar is now installed via composer

# Copy application code
COPY . /var/www/html/

# Copy the built SPA from the UI stage
COPY --from=ui /src/public/app/ /var/www/html/public/app/

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
  '    # 100 MiB attachments (PANEL_MAX_UPLOAD_BYTES) + the multipart envelope.' \
  '    client_max_body_size 101m;' \
  '' \
  '    server {' \
  '        listen       80;' \
  '        server_name  localhost;' \
  '        root   /var/www/html/public;' \
  '' \
  '        index  index.php index.html;' \
  '' \
  '        # SPA bundle: hashed assets are immutable, the shell is never cached.' \
  '        location = /app/index.html {' \
  '            add_header Cache-Control "no-store";' \
  '        }' \
  '' \
  '        location /app/assets/ {' \
  '            try_files $uri =404;' \
  '            expires 1y;' \
  '            add_header Cache-Control "public, immutable";' \
  '        }' \
  '' \
  '        # History fallback so /app/devices/12 survives a reload.' \
  '        location /app/ {' \
  '            try_files $uri /app/index.html;' \
  '        }' \
  '' \
  '        location / {' \
  '            try_files $uri $uri/ /index.php?$query_string;' \
  '        }' \
  '' \
  '        # REST API front controller: /api/v1/devices -> api.php (PATH_INFO)' \
  '        location ~ ^/api(/.*)?$ {' \
  '            include fastcgi_params;' \
  '            fastcgi_param SCRIPT_FILENAME $document_root/api.php;' \
  '            fastcgi_param SCRIPT_NAME /api.php;' \
  '            fastcgi_param PATH_INFO $1;' \
  '            fastcgi_pass 127.0.0.1:9000;' \
  '        }' \
  '' \
  '        # Panel API for the SPA (session cookie + CSRF): /panel-api/v1/...' \
  '        location ~ ^/panel-api(/.*)?$ {' \
  '            include fastcgi_params;' \
  '            fastcgi_param SCRIPT_FILENAME $document_root/panel_api.php;' \
  '            fastcgi_param SCRIPT_NAME /panel_api.php;' \
  '            fastcgi_param PATH_INFO $1;' \
  '            fastcgi_pass 127.0.0.1:9000;' \
  '        }' \
  '' \
  '        # SPA authentication: /auth/login, /auth/callback, /auth/logout' \
  '        location ~ ^/auth(/.*)?$ {' \
  '            include fastcgi_params;' \
  '            fastcgi_param SCRIPT_FILENAME $document_root/auth.php;' \
  '            fastcgi_param SCRIPT_NAME /auth.php;' \
  '            fastcgi_param PATH_INFO $1;' \
  '            fastcgi_pass 127.0.0.1:9000;' \
  '        }' \
  '' \
  '        # Entry point for / (redirects to /app/, plus the legacy SSO callback).' \
  '        location = /index.php {' \
  '            include fastcgi_params;' \
  '            fastcgi_param SCRIPT_FILENAME $document_root/index.php;' \
  '            fastcgi_pass 127.0.0.1:9000;' \
  '        }' \
  '' \
  '        # 11.0.0 deleted the legacy pages: only the entry points above run PHP,' \
  '        # so any other .php request is a 404 instead of a source download.' \
  '        location ~ \\.php$ {' \
  '            return 404;' \
  '        }' \
  '    }' \
  '}' \
  > /etc/nginx/nginx.conf

# Upload limits. PHP's defaults (2M/8M) reject a real PDF long before the panel
# sees it, and the failure surfaces as an empty `$_FILES` rather than an error,
# so nginx above and PHP here both have to sit above PANEL_MAX_UPLOAD_BYTES
# (100 MiB, identical for the local and S3 drivers).
RUN printf '%s\n' \
  'upload_max_filesize = 100M' \
  'post_max_size = 101M' \
  'max_file_uploads = 20' \
  'memory_limit = 256M' \
  'max_execution_time = 300' \
  > /usr/local/etc/php/conf.d/zz-bcmdb-uploads.ini

# Attachments for storage.driver = local. `var/` is in .dockerignore, so the
# directory is created here; the compose stacks bind-mount a host directory over
# it, and the entrypoint repairs the ownership of a mount Docker created as root.
RUN mkdir -p /var/www/html/var/storage
COPY bin/docker-entrypoint.sh /usr/local/bin/bcmdb-entrypoint
RUN chmod +x /usr/local/bin/bcmdb-entrypoint

# Make sure Nginx and PHP-FPM can access/serve project files
RUN chown -R www-data:www-data /var/www/html

EXPOSE 80

ENTRYPOINT ["bcmdb-entrypoint"]
CMD php-fpm -D && nginx -g 'daemon off;'
