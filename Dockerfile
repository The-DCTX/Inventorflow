# Image applicative InventorFlow : PHP 8.4 + Apache (mod_php).
FROM php:8.4-apache

# Dépendances système + extensions PHP requises par l'app
# (pdo_mysql/mysqli : base ; mbstring : libonig ; gd : images ; zip ; ldap).
RUN apt-get update && apt-get install -y --no-install-recommends \
        libldap2-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
        libonig-dev default-mysql-client \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli mbstring gd zip ldap \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Autoriser les .htaccess (l'app en fournit un à la racine)
RUN sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf

WORKDIR /var/www/html
COPY . /var/www/html/
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && chown -R www-data:www-data /var/www/html

# L'entrypoint prépare la config + la base, puis lance Apache (le CMD).
ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]
