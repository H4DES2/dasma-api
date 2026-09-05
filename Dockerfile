FROM php:8.2-apache

RUN docker-php-ext-install mysqli pdo pdo_mysql
RUN a2enmod rewrite headers

# Enable global CORS headers in Apache
RUN echo '<Directory /var/www/html>\n\
    Header always set Access-Control-Allow-Origin "*"\n\
    Header always set Access-Control-Allow-Methods "POST, GET, OPTIONS, DELETE, PUT"\n\
    Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With"\n\
</Directory>' > /etc/apache2/conf-available/cors.conf \
    && a2enconf cors

WORKDIR /var/www/html
COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80