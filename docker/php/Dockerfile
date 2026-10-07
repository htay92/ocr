FROM php:8.3-fpm-bookworm

# poppler-utils: pdftotext / pdftoppm for PDF text layers and page rendering.
RUN apt-get update && apt-get install -y --no-install-recommends \
        poppler-utils git unzip curl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_pgsql redis gd intl zip pcntl bcmath opcache

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

# Run as the host user so files written to the bind mount stay editable.
ARG UID=1000
ARG GID=1000
RUN groupmod -o -g ${GID} www-data && usermod -o -u ${UID} -g www-data www-data

WORKDIR /var/www/html
USER www-data
