FROM php:8.3-cli-alpine

LABEL maintainer="José Guilherme <https://github.com/JzGuilherme>"
LABEL description="PHP 8.3 CLI Worker container with pdo_mysql, pcntl, curl, mbstring and supervisor"

# Install system dependencies and build libraries
RUN apk add --no-cache \
    bash \
    curl \
    curl-dev \
    git \
    icu-dev \
    oniguruma-dev \
    supervisor \
    unzip \
    zip

# Install required PHP extensions for concurrent queue workers and AI HTTP calls
RUN docker-php-ext-install -j$(nproc) \
    curl \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo \
    pdo_mysql

# Configure PHP CLI settings for long-running worker stability
RUN { \
    echo "memory_limit=256M"; \
    echo "max_execution_time=0"; \
    echo "opcache.enable_cli=1"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=8"; \
    echo "opcache.max_accelerated_files=4000"; \
    } > /usr/local/etc/php/conf.d/worker-cli.ini

# Install Composer 2
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

# Create log directory with proper permissions
RUN mkdir -p /var/www/html/logs /var/log/supervisor \
    && chown -R www-data:www-data /var/www/html

CMD ["php", "-a"]
