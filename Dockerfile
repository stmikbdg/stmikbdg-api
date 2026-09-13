FROM node:22-bookworm AS assets
WORKDIR /app
COPY package*.json ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY . .
RUN npm run build

FROM php:8.3-apache-bookworm
ENV PHP_UPLOAD_MAX_FILESIZE=20M PHP_POST_MAX_SIZE=25M PHP_MEMORY_LIMIT=512M PHP_MAX_EXECUTION_TIME=180 APACHE_DOCUMENT_ROOT=/app/public
RUN apt-get update && apt-get install -y git unzip curl libpq-dev libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev libonig-dev libcurl4-openssl-dev && docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install pdo_pgsql pgsql zip intl bcmath gd mbstring curl exif pcntl && pecl install redis && docker-php-ext-enable redis && a2enmod rewrite && sed -ri 's!/var/www/html!/app/public!g' /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf && printf '<Directory /app/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' > /etc/apache2/conf-available/laravel.conf && a2enconf laravel && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-scripts
COPY . .
COPY --from=assets /app/public/build ./public/build
RUN composer dump-autoload --optimize && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && chmod -R ug+rwX storage bootstrap/cache && chmod +x docker-entrypoint.sh
ENTRYPOINT ["/app/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
