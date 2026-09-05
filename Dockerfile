FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN a2enmod rewrite headers

WORKDIR /var/www/html

COPY . /var/www/html/

# Ensure Apache has read & execute permissions for all PHP files
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80