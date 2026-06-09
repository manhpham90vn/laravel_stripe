# E2E app image: Laravel 13 (PHP 8.3) served with a real queue worker.
# Built from the repo root so it can COPY the full source. Used only by
# docker-compose.e2e.yml — not a production image.
# composer.lock pins Symfony 8.1, which requires PHP >= 8.4.1.
FROM php:8.4-cli

# System libs needed to build the PHP extensions Laravel + SQLite require.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip curl ca-certificates gnupg \
        libzip-dev libicu-dev libonig-dev libsqlite3-dev sqlite3 \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite mbstring bcmath intl zip \
    && rm -rf /var/lib/apt/lists/*

# Node 20 — Blade uses @vite, so we need a production asset build (public/build).
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
    && apt-get install -y --no-install-recommends nodejs \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Install PHP deps first (better layer caching). Keep dev deps: the DatabaseSeeder
# uses model factories (fakerphp/faker lives in require-dev).
COPY composer.json composer.lock ./
RUN composer install --no-scripts --no-autoloader --prefer-dist --no-interaction

# Install JS deps and keep them — vite build needs the dev toolchain.
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts

# Bring in the rest of the source, finish the autoloader, build assets.
COPY . .
RUN composer dump-autoload --optimize \
    && npm run build

RUN chmod +x docker/app-entrypoint.sh

EXPOSE 8000
ENTRYPOINT ["docker/app-entrypoint.sh"]
