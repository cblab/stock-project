FROM php:8.3-cli

ENV APP_ENV=prod \
    COMPOSER_ALLOW_SUPERUSER=1

RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install intl pdo_mysql opcache zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app/web

COPY web/composer.json web/composer.lock web/symfony.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --classmap-authoritative

COPY web ./
RUN composer dump-autoload --classmap-authoritative --no-dev \
    && mkdir -p var/cache var/log var/share var/tailwind \
    && php bin/console tailwind:build --minify --env=prod \
    && php bin/console asset-map:compile --env=prod \
    && chmod -R 0777 var

EXPOSE 8000

CMD ["php", "-S", "0.0.0.0:8000", "-t", "public", "public/index.php"]
