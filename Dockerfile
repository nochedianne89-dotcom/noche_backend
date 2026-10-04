ARG PHP_VERSION=8.5
 
FROM php:${PHP_VERSION}-apache
 
# Install PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql
 
# Enable Apache mod_rewrite
RUN a2enmod rewrite
 
# Allow .htaccess overridesARG PHP_VERSION=8.4

FROM php:${PHP_VERSION}-apache

# PDO MySQL
RUN docker-php-ext-install pdo pdo_mysql

# Apache mod_rewrite
RUN a2enmod rewrite

# Allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Ipasa ang Authorization header sa PHP (kailangan ng JWT)
RUN echo 'SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1' > /etc/apache2/conf-available/authorization.conf \
    && a2enconf authorization

# Copy app files
COPY . /var/www/html/

# Siguraduhing may .env file (walang laman) kung wala sa repo.
# Ang totoong values ay galing sa Render environment variables.
RUN touch /var/www/html/.env

# Permissions
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

# Document root = public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public

RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot ${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
    && sed -i 's|<Directory /var/www/html>|<Directory ${APACHE_DOCUMENT_ROOT}>|g' /etc/apache2/apache2.conf

# Makinig sa PORT na ibinibigay ng Render (default 80 kung local)
ENV PORT=80
RUN sed -i 's/Listen 80/Listen ${PORT}/' /etc/apache2/ports.conf \
    && sed -i 's/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/' /etc/apache2/sites-available/000-default.conf

EXPOSE 80
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
 
# Copy app files
COPY . /var/www/html/
 
# Fix permissions
RUN chown -R www-data:www-data /var/www/html \
&& chmod -R 755 /var/www/html
 
# Point Apache document root to public/
ENV APACHE_DOCUMENT_ROOT /var/www/html/public
 
RUN sed -i 's|DocumentRoot /var/www/html|DocumentRoot ${APACHE_DOCUMENT_ROOT}|g' /etc/apache2/sites-available/000-default.conf \
&& sed -i 's|<Directory /var/www/html>|<Directory ${APACHE_DOCUMENT_ROOT}>|g' /etc/apache2/apache2.conf
 
EXPOSE 80