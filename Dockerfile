FROM php:8.3-fpm-alpine

# Install pdo_mysql and bcmath (highly recommended for RabbitMQ libraries)
RUN docker-php-ext-install pdo_mysql bcmath

WORKDIR /var/www/html
