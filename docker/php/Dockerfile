FROM php:8.3-apache

# System & PHP Extensions
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      git \
      unzip \
 && docker-php-ext-install pdo pdo_mysql \
 && a2enmod rewrite headers \
 && rm -rf /var/lib/apt/lists/*

# Apache + PHP Config
COPY docker/php/apache/vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini

# Arbeitsverzeichnis
WORKDIR /var/www/html
