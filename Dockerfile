FROM php:8.2-apache
COPY . /var/www/html/

RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libwebp-dev \
    libxpm-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp --with-xpm \
    && docker-php-ext-install gd mysqli pdo pdo_mysql

RUN a2enmod rewrite
RUN a2enmod headers

EXPOSE 80
