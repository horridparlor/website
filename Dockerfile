FROM php:8.2-apache
ARG UID=1000
ARG GID=1000
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

# /var/www/html is bind-mounted from the host repo, and gitignored upload dirs
# (is-back/card-images, is-back/card-art) get created there by the host user. Match
# www-data's uid/gid to the host user so Apache can actually write into them.
RUN groupmod -g "$GID" www-data && usermod -u "$UID" -g "$GID" www-data \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80
