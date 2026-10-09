FROM php:8.3-fpm

# Install system dependencies and PHP extensions
RUN apt-get update && apt-get install -y \
    git curl libpng-dev libonig-dev libxml2-dev zip unzip nginx

RUN docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www

COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader

# Set permissions for Laravel storage
RUN chown -R www-data:www-data /var/www/storage /var/www/bootstrap/cache

# Nginx setup
COPY .docker/nginx.conf /etc/nginx/sites-available/default

EXPOSE 80

CMD service nginx start && php-fpm