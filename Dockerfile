FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        chromium fonts-liberation fonts-noto-color-emoji ca-certificates curl \
        libpng-dev libjpeg-dev libwebp-dev libzip-dev \
    && docker-php-ext-configure gd --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" gd zip pcntl \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-startseite.ini
COPY docker/apache.conf /etc/apache2/conf-available/startseite.conf
RUN a2enconf startseite

COPY src /app/src
COPY bin /app/bin
COPY public /var/www/html
RUN echo "<?php return '/app';" > /var/www/html/app_path.php \
    && mkdir -p /data && chown www-data:www-data /data

COPY docker/entrypoint.sh /usr/local/bin/startseite-entrypoint
RUN chmod +x /usr/local/bin/startseite-entrypoint

# Chromium cannot use its sandbox inside an unprivileged container.
ENV STARTSEITE_DATA_DIR=/data \
    STARTSEITE_CHROMIUM_FLAGS=--no-sandbox

VOLUME /data
EXPOSE 80
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s CMD curl -fsS -o /dev/null http://127.0.0.1/login || exit 1

ENTRYPOINT ["startseite-entrypoint"]
CMD ["apache2-foreground"]
