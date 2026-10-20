# FacturaScripts dev/production-ish image.
#
# WHY APACHE AND NOT `php -S`:
# FacturaScripts ships an .htaccess, relies on mod_rewrite for its pretty URLs and
# expects Apache semantics (AllowOverride). The PHP built-in server hides routing
# bugs, so the local stack runs the same webserver family as a real deployment.
#
# WHY APACHE RUNS AS UID 1000:
# The repository is bind-mounted from the host. If Apache ran as www-data (uid 33)
# the installer could not write config.php, MyFiles or Plugins and every upload
# would end up root-owned inside the checkout.
FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpq-dev \
        libzip-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libicu-dev \
        unzip \
        git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mysqli pgsql bcmath gd zip intl \
    && a2enmod rewrite headers expires \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Serve the checkout itself as document root and honour its .htaccess.
RUN printf '%s\n' \
        '<Directory /var/www/html>' \
        '    AllowOverride All' \
        '    Require all granted' \
        '</Directory>' \
        > /etc/apache2/conf-available/facturascripts.conf \
    && a2enconf facturascripts \
    && sed -ri 's/^: \$\{APACHE_RUN_USER:=www-data\}$/: ${APACHE_RUN_USER:=#1000}/; s/^: \$\{APACHE_RUN_GROUP:=www-data\}$/: ${APACHE_RUN_GROUP:=#1000}/' /etc/apache2/envvars \
    && grep -E 'APACHE_RUN_(USER|GROUP):=' /etc/apache2/envvars

# PHP's default timezone, because PHP does NOT read the container's TZ variable
# for date_default_timezone_get(): without this the process clocks stay UTC while
# the operator (and the client's payment terms) are in Spain, and the monthly
# billing job's "today" can be the previous day. dev-stack/docker-compose.yml
# also sets TZ=Europe/Madrid for the parts that do honour it, and
# Lib\Period::today() pins it in code for the billing calendar itself.
RUN printf '%s\n' 'date.timezone = Europe/Madrid' > /usr/local/etc/php/conf.d/timezone.ini

WORKDIR /var/www/html
