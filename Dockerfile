# ─── Stage 1: Node — compile frontend assets ──────────────────────────────────
FROM node:20-alpine AS node-builder

WORKDIR /app

COPY package*.json ./
RUN npm ci --no-audit --no-fund

COPY resources/ resources/
COPY vite.config.js ./
COPY public/ public/

RUN npm run build

# ─── Stage 2: PHP application ─────────────────────────────────────────────────
FROM php:8.2-fpm-alpine AS app

# System deps
RUN apk add --no-cache \
    nginx \
    supervisor \
    curl \
    zip \
    unzip \
    git \
    mysql-client \
    oniguruma-dev \
    libpng-dev \
    libxml2-dev \
    icu-dev \
    shadow

# PHP extensions
RUN docker-php-ext-configure intl \
 && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mbstring \
        bcmath \
        opcache \
        intl \
        pcntl \
        xml \
        ctype \
        fileinfo

# Redis PECL extension (autoconf + php-dev needed to compile)
RUN apk add --no-cache autoconf g++ make \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del autoconf g++ make \
 && rm -rf /tmp/pear

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

# Copy application source
COPY . .

# Copy compiled frontend assets from stage 1
COPY --from=node-builder /app/public/build public/build

# Install PHP dependencies (production)
RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-progress

# Ensure storage / bootstrap/cache are writable
RUN mkdir -p storage/framework/{sessions,views,cache} \
              storage/logs \
              bootstrap/cache \
 && chown -R www-data:www-data /var/www \
 && chmod -R 775 storage bootstrap/cache

# Copy Docker support configs
COPY docker/nginx.conf      /etc/nginx/http.d/default.conf
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/php.ini          /usr/local/etc/php/conf.d/custom.ini
COPY docker/entrypoint.sh    /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
