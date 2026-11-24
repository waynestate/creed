# One image per PHP line for the framework matrix (scripts/framework-matrix.ps1 and .sh).
# BASE is the official php:<line>-cli image, pinned by the digest the script just pulled.
# On top: the extensions the skeletons need (intl for CakePHP; mbstring for CakePHP and
# PHPUnit, which the official 8.6 image no longer has; pdo_mysql for the MySQL connection
# CakePHP's home page checks; zip so Composer can unpack dists), git and unzip for
# Composer, Composer itself, and an ini that shows every deprecation, warning and notice
# on stderr. Nothing else: the matrix tests the frameworks on PHP as it ships, not on a
# PHP we tuned for them.
ARG BASE=php:8.6-cli@sha256:fc9ca34c7c35745ff63d571057ce505f49537003a2f9c8f7b791be4f196b4e29
FROM ${BASE}

COPY --from=docker.io/mlocati/php-extension-installer:2.13.2@sha256:4fd45396c16685266d32bf147a24ecd5e81029d3820295895bf4a90131190ac9 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=docker.io/library/composer:2.11.2@sha256:cd3a90dc5624c59a2f9ddf80dad08569f06416db31de519024f249d63bca554b /usr/bin/composer /usr/local/bin/composer

ARG EXTENSIONS="intl mbstring zip pdo_mysql"
# An end-of-life line's Debian (bullseye under php:8.0) leaves deb.debian.org for
# archive.debian.org; when the mirror no longer has its packages, read the archive.
RUN set -e \
    && if ! { apt-get update && apt-get install -y --no-install-recommends git unzip; }; then \
         for f in /etc/apt/sources.list /etc/apt/sources.list.d/*; do \
           [ -f "$f" ] && sed -i -e 's#deb.debian.org/debian-security#archive.debian.org/debian-security#g' -e 's#security.debian.org/debian-security#archive.debian.org/debian-security#g' -e 's#deb.debian.org/debian#archive.debian.org/debian#g' "$f"; \
         done; \
         printf 'Acquire::Check-Valid-Until "false";\n' > /etc/apt/apt.conf.d/99creed-matrix-archive; \
         apt-get update && apt-get install -y --no-install-recommends git unzip; \
       fi \
    && missing="" \
    && for ext in $EXTENSIONS; do php -m | grep -qix "$ext" || missing="$missing $ext"; done \
    && if [ -n "$missing" ]; then install-php-extensions $missing; fi \
    && rm -rf /var/lib/apt/lists/* \
    && printf '%s\n' 'error_reporting = E_ALL' 'display_errors = stderr' 'display_startup_errors = On' 'log_errors = Off' 'memory_limit = 1G' > /usr/local/etc/php/conf.d/zz-creed-matrix.ini

ENV COMPOSER_CACHE_DIR=/tmp/composer-cache \
    COMPOSER_ALLOW_SUPERUSER=1 \
    COMPOSER_NO_AUDIT=1
