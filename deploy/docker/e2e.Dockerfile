ARG PHP_BASE_IMAGE=facturly-app
FROM ${PHP_BASE_IMAGE}
RUN apt-get update && apt-get install -y --no-install-recommends poppler-utils && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY backend/composer.json backend/composer.lock ./
RUN composer install --no-interaction --prefer-dist --no-scripts
COPY backend/app app
COPY backend/bootstrap bootstrap
COPY backend/config config
COPY backend/database database
COPY backend/public public
COPY backend/resources resources
COPY backend/routes routes
COPY backend/tests tests
COPY backend/artisan backend/phpunit.xml ./
COPY deploy/docker/e2e-providers.php bootstrap/providers.php
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/private/documents bootstrap/cache \
    && APP_ENV=testing E2E_ISOLATED=1 composer dump-autoload --no-interaction
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000", "--no-reload"]
