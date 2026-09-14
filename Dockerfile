# Use official PHP Apache image
FROM php:8.2-apache

# Set working directory
WORKDIR /var/www/html

# Install system dependencies and build tools
RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    zip \
    unzip \
    libssl-dev \
    pkg-config \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_mysql bcmath \
    && pecl install mongodb \
    && docker-php-ext-enable mongodb

# Enable Apache mod_rewrite for Slim Framework URL routing
RUN a2enmod rewrite

# Configure Apache DocumentRoot to point to the public/ directory
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides in public directory
RUN echo '<Directory /var/www/html/public>\n\
    Options Indexes FollowSymLinks\n\
    AllowOverride All\n\
    Require all granted\n\
</Directory>' > /etc/apache2/conf-available/app-rewrite.conf \
    && a2enconf app-rewrite

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Copy composer manifest files first to leverage Docker layer caching
COPY composer.json composer.lock* ./

# Install project dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

# Copy the rest of the application code
COPY . .

# Ensure vendor autoloader is up to date and set file permissions
RUN composer dump-autoload --optimize \
    && chown -R www-data:www-data /var/www/html

# Ensure PHP populates $_ENV from environment variables (important for Docker and Render)
RUN echo 'variables_order = "EGPCS"' >> /usr/local/etc/php/conf.d/docker-php-vars.ini

# Copy entrypoint script and fix Windows line endings if any
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/docker-entrypoint.sh \
    && chmod +x /usr/local/bin/docker-entrypoint.sh

# Expose HTTP port (80 for local, Render dynamically assigns $PORT)
EXPOSE 80 10000

# Use dynamic entrypoint script
ENTRYPOINT ["docker-entrypoint.sh"]

