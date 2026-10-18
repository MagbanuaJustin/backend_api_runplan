FROM php:8.3-apache

# PHP's MySQL driver + Apache modules LavaLust needs
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers

# Serve only the public/ folder
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf \
    && printf '<Directory /var/www/html/public>\n    AllowOverride All\n    Require all granted\n</Directory>\n' \
    > /etc/apache2/conf-available/runplan.conf \
    && a2enconf runplan

COPY . /var/www/html
RUN chown -R www-data:www-data /var/www/html/runtime

# Render gives us a PORT variable; make Apache listen on it
CMD ["/bin/sh", "-c", "sed -i \"s/Listen 80/Listen ${PORT:-10000}/\" /etc/apache2/ports.conf && sed -i \"s/:80>/:${PORT:-10000}>/\" /etc/apache2/sites-available/000-default.conf && apache2-foreground"]