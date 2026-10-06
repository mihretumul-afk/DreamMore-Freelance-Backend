FROM php:8.2-cli

# Install Linux dependencies and PHP PostgreSQL extensions
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    libpq-dev \
    libzip-dev \
    && docker-php-ext-install pdo pdo_pgsql zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Copy application files
COPY . .

# Install Laravel dependencies
RUN composer install --no-dev --optimize-autoloader

EXPOSE 10000

# Start Laravel server
CMD php artisan serve --host=0.0.0.0 --port=${PORT:-10000}
