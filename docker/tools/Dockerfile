# PHP 8.3 with wp-cli and Composer, for wp, PHPUnit, PHPCS and the zip.
FROM wordpress:cli-2.12.0-php8.3
USER root
COPY --from=composer:2.11.2 /usr/bin/composer /usr/bin/composer
RUN apk add --no-cache zip \
	&& echo 'memory_limit=512M' > /usr/local/etc/php/conf.d/zz-tools.ini
ENV COMPOSER_HOME=/tmp/composer
USER 33:33
